<?php

declare(strict_types=1);

namespace ShopBite\Passkey;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * WebAuthn ceremonies for the backoffice (fingerprint or face instead of a password).
 *
 * Registration: a logged-in user gets creation options, the browser creates a passkey on the
 * device, the attestation is checked and the credential stored. Login: anyone gets request
 * options (a one-time challenge, no username needed, the passkey is discoverable), the browser
 * signs it, PasskeyGrant hands the assertion to verifyLogin() and gets the user id.
 *
 * Options live in the cache for five minutes and are used once.
 */
final readonly class PasskeyService
{
    private const int TTL = 300;
    private const string CACHE_PREFIX = 'shopbite_passkey_';

    private SerializerInterface $serializer;

    /** @psalm-suppress PossiblyUnusedMethod Constructed by the DI container */
    public function __construct(
        private PasskeyStore $store,
        private PasskeyConfig $config,
        private CacheItemPoolInterface $cache,
    ) {
        $this->serializer = new WebauthnSerializerFactory(
            new AttestationStatementSupportManager([new NoneAttestationStatementSupport()]),
        )->create();
    }

    /**
     * @return array<string, mixed> PublicKeyCredentialCreationOptionsJSON for navigator.credentials.create
     */
    public function registrationOptions(string $userId): array
    {
        $this->assertEnabled();
        $user = $this->store->user($userId) ?? throw new PasskeyException('Unknown user.');

        $options = PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create('ShopBite Backoffice', $this->config->rpId()),
            // the user handle is the Shopware user id; the login maps it back
            PublicKeyCredentialUserEntity::create($user['username'], $userId, $user['name']),
            random_bytes(32),
            [PublicKeyCredentialParameters::createPk(-7), PublicKeyCredentialParameters::createPk(-257)],
            AuthenticatorSelectionCriteria::create(
                null,
                AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
            ),
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            array_map(
                static fn (string $id): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create('public-key', self::fromBase64Url($id)),
                $this->store->credentialIdsForUser($userId),
            ),
            60_000,
        );
        $this->remember('register_' . $userId, $this->serialize($options));

        return $this->toArray($options);
    }

    /** Checks the browser's answer to registrationOptions() and stores the passkey. */
    public function register(string $userId, string $credentialJson, string $name): string
    {
        $this->assertEnabled();
        $optionsJson = $this->take('register_' . $userId) ?? throw new PasskeyException('The registration expired, please try again.');
        $options = $this->serializer->deserialize($optionsJson, PublicKeyCredentialCreationOptions::class, 'json');
        $credential = $this->deserializeCredential($credentialJson);
        if (!$credential->response instanceof AuthenticatorAttestationResponse) {
            throw new PasskeyException('Not a registration response.');
        }

        try {
            $record = AuthenticatorAttestationResponseValidator::create($this->ceremonies()->creationCeremony())
                ->check($credential->response, $options, $this->config->rpId());
        } catch (\Throwable $e) {
            throw new PasskeyException('The passkey could not be verified.', 0, $e);
        }

        return $this->store->add(
            $userId,
            self::toBase64Url($record->publicKeyCredentialId),
            mb_substr(trim($name) ?: 'Gerät', 0, 255),
            $this->serialize($record),
        );
    }

    /**
     * @return array<string, mixed> PublicKeyCredentialRequestOptionsJSON for navigator.credentials.get
     */
    public function loginOptions(): array
    {
        $this->assertEnabled();
        $options = PublicKeyCredentialRequestOptions::create(
            random_bytes(32),
            $this->config->rpId(),
            [],
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            60_000,
        );
        $this->remember('login_' . self::toBase64Url($options->challenge), $this->serialize($options));

        return $this->toArray($options);
    }

    /**
     * Verifies a signed login challenge and returns the Shopware user id.
     * Every failure is the same exception, so callers cannot tell why.
     */
    public function verifyLogin(string $credentialJson): string
    {
        $this->assertEnabled();
        $fail = static fn (?\Throwable $previous = null): PasskeyException => new PasskeyException('Passkey login failed.', 0, $previous);

        try {
            $credential = $this->deserializeCredential($credentialJson);
        } catch (PasskeyException $e) {
            throw $fail($e);
        }
        $response = $credential->response;
        if (!$response instanceof AuthenticatorAssertionResponse) {
            throw $fail();
        }
        $optionsJson = $this->take('login_' . self::toBase64Url($response->clientDataJSON->challenge)) ?? throw $fail();
        $stored = $this->store->findByCredentialId(self::toBase64Url($credential->rawId)) ?? throw $fail();
        $user = $this->store->user($stored['userId']);
        if ($user === null || !$user['active']) {
            throw $fail();
        }

        try {
            $options = $this->serializer->deserialize($optionsJson, PublicKeyCredentialRequestOptions::class, 'json');
            $record = $this->serializer->deserialize($stored['record'], CredentialRecord::class, 'json');
            $updated = AuthenticatorAssertionResponseValidator::create($this->ceremonies()->requestCeremony())
                ->check($record, $response, $options, $this->config->rpId(), $response->userHandle);
        } catch (\Throwable $e) {
            throw $fail($e);
        }
        if ($updated->userHandle !== $stored['userId']) {
            throw $fail();
        }

        $this->store->recordUse($stored['id'], $this->serialize($updated));

        return $stored['userId'];
    }

    /**
     * @return list<array{id: string, name: string, createdAt: string, lastUsedAt: string|null}>
     */
    public function list(string $userId): array
    {
        return $this->store->listForUser($userId);
    }

    public function delete(string $id, string $userId): bool
    {
        return $this->store->delete($id, $userId);
    }

    public function enabled(): bool
    {
        return $this->config->enabled();
    }

    private function ceremonies(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();
        $factory->setAllowedOrigins($this->config->origins());

        return $factory;
    }

    private function deserializeCredential(string $json): PublicKeyCredential
    {
        try {
            return $this->serializer->deserialize($json, PublicKeyCredential::class, 'json');
        } catch (\Throwable $e) {
            throw new PasskeyException('Malformed credential.', 0, $e);
        }
    }

    private function serialize(object $value): string
    {
        return $this->serializer->serialize($value, 'json', [
            AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
            JsonEncode::OPTIONS => \JSON_THROW_ON_ERROR,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(object $options): array
    {
        /** @var array<string, mixed> */
        return json_decode($this->serialize($options), true, 512, \JSON_THROW_ON_ERROR);
    }

    private function remember(string $key, string $value): void
    {
        $item = $this->cache->getItem(self::CACHE_PREFIX . $key);
        $item->set($value);
        $item->expiresAfter(self::TTL);
        $this->cache->save($item);
    }

    /** Reads and removes, so a challenge works once. */
    private function take(string $key): ?string
    {
        $item = $this->cache->getItem(self::CACHE_PREFIX . $key);
        if (!$item->isHit()) {
            return null;
        }
        $this->cache->deleteItem(self::CACHE_PREFIX . $key);
        $value = $item->get();

        return \is_string($value) ? $value : null;
    }

    private function assertEnabled(): void
    {
        if (!$this->config->enabled()) {
            throw new PasskeyException('Passkeys are not configured (plugin configuration: passkey relying party id and origins).');
        }
    }

    public static function toBase64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function fromBase64Url(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}
