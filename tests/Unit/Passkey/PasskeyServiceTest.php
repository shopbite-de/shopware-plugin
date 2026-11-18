<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Passkey;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ShopBite\Passkey\PasskeyConfig;
use ShopBite\Passkey\PasskeyException;
use ShopBite\Passkey\PasskeyService;
use ShopBite\Passkey\PasskeyStore;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(PasskeyService::class)]
#[CoversClass(PasskeyConfig::class)]
#[CoversClass(PasskeyStore::class)]
#[AllowMockObjectsWithoutExpectations]
class PasskeyServiceTest extends TestCase
{
    private const string USER = '0190000000007000800000000000a002';

    private Connection&MockObject $connection;
    private PasskeyStore $store;
    private ArrayAdapter $cache;
    private InMemoryStore $locks;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->store = new PasskeyStore($this->connection);
        $this->cache = new ArrayAdapter();
        $this->locks = new InMemoryStore();
    }

    public function testConfigNeedsHostAndOrigins(): void
    {
        static::assertFalse($this->config('', '')->enabled());
        static::assertFalse($this->config('backoffice.example', '')->enabled());
        $config = $this->config(' backoffice.example ', 'https://backoffice.example/, https://alt.example');
        static::assertTrue($config->enabled());
        static::assertSame('backoffice.example', $config->rpId());
        static::assertSame(['https://backoffice.example', 'https://alt.example'], $config->origins());
    }

    public function testEverythingIsRefusedWhenNotConfigured(): void
    {
        $service = new PasskeyService($this->store, $this->config('', ''), $this->cache, new LockFactory($this->locks));
        static::assertFalse($service->enabled());
        $this->expectException(PasskeyException::class);
        $service->loginOptions();
    }

    public function testLoginOptionsAskForUserVerificationWithoutUsername(): void
    {
        $options = $this->service()->loginOptions();

        static::assertSame('backoffice.example', $options['rpId']);
        static::assertSame('required', $options['userVerification']);
        static::assertArrayNotHasKey('allowCredentials', array_filter($options));
        static::assertIsString($options['challenge']);
        static::assertTrue($this->cache->hasItem('shopbite_passkey_login_' . $options['challenge']));
    }

    public function testRegistrationOptionsUseTheUserIdAsHandleAndExcludeKnownDevices(): void
    {
        $this->connection->method('fetchAssociative')
            ->willReturn(['username' => 'chef', 'first_name' => 'Clara', 'last_name' => 'Chef', 'active' => 1]);
        $this->connection->method('fetchFirstColumn')->willReturn(['AQID']);

        $options = $this->service()->registrationOptions(self::USER);

        static::assertSame('backoffice.example', $options['rp']['id']);
        static::assertSame('chef', $options['user']['name']);
        static::assertSame('Clara Chef', $options['user']['displayName']);
        static::assertSame(PasskeyService::toBase64Url(self::USER), $options['user']['id']);
        static::assertSame('required', $options['authenticatorSelection']['userVerification']);
        static::assertSame('required', $options['authenticatorSelection']['residentKey']);
        static::assertSame('none', $options['attestation'] ?? 'none');
        static::assertSame('AQID', $options['excludeCredentials'][0]['id']);
    }

    public function testRegistrationWithoutOptionsHasExpired(): void
    {
        $this->expectExceptionMessage('expired');
        $this->service()->register(self::USER, '{}', 'iPhone');
    }

    public function testLoginWithGarbageOrAnUnknownChallengeFails(): void
    {
        foreach (['not json', '{"id":"x","rawId":"eA","type":"public-key","response":{}}'] as $credential) {
            try {
                $this->service()->verifyLogin($credential);
                static::fail('no exception');
            } catch (PasskeyException $e) {
                static::assertSame('Passkey login failed.', $e->getMessage());
            }
        }
    }

    public function testChallengeIsUsedOnce(): void
    {
        $this->userExists();
        $service = $this->service();
        $service->registrationOptions(self::USER);

        try {
            $service->register(self::USER, 'not json', 'iPhone');
            static::fail('no exception');
        } catch (PasskeyException $e) {
            static::assertSame('Malformed credential.', $e->getMessage());
        }

        $this->expectExceptionMessage('expired');
        $service->register(self::USER, 'not json', 'iPhone');
    }

    public function testChallengeTakenByAConcurrentRequestIsRefused(): void
    {
        $this->userExists();
        $service = $this->service();
        $service->registrationOptions(self::USER);

        // another request is consuming the same challenge right now
        $concurrent = new LockFactory($this->locks)->createLock('shopbite_passkey_register_' . self::USER);
        static::assertTrue($concurrent->acquire());

        try {
            $service->register(self::USER, 'not json', 'iPhone');
            static::fail('no exception');
        } catch (PasskeyException $e) {
            static::assertStringContainsString('expired', $e->getMessage());
        }
        static::assertTrue($this->cache->hasItem('shopbite_passkey_register_' . self::USER), 'left to the request holding it');
    }

    public function testLoginChallengeIsLockedWhileTaken(): void
    {
        $options = $this->service()->loginOptions();
        $concurrent = new LockFactory($this->locks)->createLock('shopbite_passkey_login_' . $options['challenge']);
        static::assertTrue($concurrent->acquire());

        $credential = json_encode([
            'id' => 'AQID',
            'rawId' => 'AQID',
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => PasskeyService::toBase64Url((string) json_encode([
                    'type' => 'webauthn.get',
                    'challenge' => $options['challenge'],
                    'origin' => 'https://backoffice.example',
                ])),
                'authenticatorData' => PasskeyService::toBase64Url(str_repeat("\0", 37)),
                'signature' => 'AQID',
            ],
        ]);
        $this->connection->expects($this->never())->method('fetchAssociative');

        $this->expectExceptionMessage('Passkey login failed.');
        $this->service()->verifyLogin((string) $credential);
    }

    private function userExists(): void
    {
        $this->connection->method('fetchAssociative')
            ->willReturn(['username' => 'chef', 'first_name' => 'Clara', 'last_name' => 'Chef', 'active' => 1]);
        $this->connection->method('fetchFirstColumn')->willReturn([]);
    }

    private function service(): PasskeyService
    {
        return new PasskeyService($this->store, $this->config('backoffice.example', 'https://backoffice.example'), $this->cache, new LockFactory($this->locks));
    }

    private function config(string $rpId, string $origins): PasskeyConfig
    {
        $systemConfig = $this->createStub(SystemConfigService::class);
        $systemConfig->method('getString')->willReturnMap([
            ['ShopBitePlugin.config.passkeyRpId', null, $rpId],
            ['ShopBitePlugin.config.passkeyOrigins', null, $origins],
        ]);

        return new PasskeyConfig($systemConfig);
    }
}
