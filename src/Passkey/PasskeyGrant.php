<?php

declare(strict_types=1);

namespace ShopBite\Passkey;

use DateInterval;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\PasswordGrant;
use League\OAuth2\Server\RequestAccessTokenEvent;
use League\OAuth2\Server\RequestEvent;
use League\OAuth2\Server\RequestRefreshTokenEvent;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use Override;
use Psr\Http\Message\ServerRequestInterface;
use Shopware\Core\Framework\Api\OAuth\User\User;

/**
 * SPIKE (plugin#38): grant_type=shopbite_passkey. The real version verifies a WebAuthn assertion;
 * this prototype accepts username + SHOPBITE_PASSKEY_SPIKE_SECRET to prove token issuance.
 */
final class PasskeyGrant extends PasswordGrant
{
    #[Override]
    public function getIdentifier(): string
    {
        return 'shopbite_passkey';
    }

    #[Override]
    public function respondToAccessTokenRequest(ServerRequestInterface $request, ResponseTypeInterface $responseType, DateInterval $accessTokenTTL): ResponseTypeInterface
    {
        $client = $this->validateClient($request);
        $scopes = $this->validateScopes($this->getRequestParameter('scope', $request, $this->defaultScope));
        $user = $this->validateUser($request, $client);

        // Shopware's ScopeRepository only grants "write" to the password grant; a passkey login is
        // the same person proving themselves, so the scopes are finalized as for a password login.
        $finalizedScopes = $this->scopeRepository->finalizeScopes($scopes, 'password', $client, $user->getIdentifier());

        $accessToken = $this->issueAccessToken($accessTokenTTL, $client, $user->getIdentifier(), $finalizedScopes);
        $this->getEmitter()->emit(new RequestAccessTokenEvent(RequestEvent::ACCESS_TOKEN_ISSUED, $request, $accessToken));
        $responseType->setAccessToken($accessToken);
        $refreshToken = $this->issueRefreshToken($accessToken);
        if ($refreshToken !== null) {
            $this->getEmitter()->emit(new RequestRefreshTokenEvent(RequestEvent::REFRESH_TOKEN_ISSUED, $request, $refreshToken));
            $responseType->setRefreshToken($refreshToken);
        }

        return $responseType;
    }

    #[Override]
    protected function validateUser(ServerRequestInterface $request, ClientEntityInterface $client): UserEntityInterface
    {
        $userId = $this->getRequestParameter('user_id', $request) ?? throw OAuthServerException::invalidRequest('user_id');
        $secret = $this->getRequestParameter('spike_secret', $request);
        if (!\is_string($secret) || $secret === '' || !hash_equals(trim((string) @file_get_contents('/tmp/passkey-spike-secret')), $secret)) {
            throw OAuthServerException::invalidCredentials();
        }

        return new User((string) $userId);
    }
}
