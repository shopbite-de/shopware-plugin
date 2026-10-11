<?php

declare(strict_types=1);

namespace ShopBite\Passkey;

use DateInterval;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\PasswordGrant;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use League\OAuth2\Server\RequestAccessTokenEvent;
use League\OAuth2\Server\RequestEvent;
use League\OAuth2\Server\RequestRefreshTokenEvent;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use Override;
use Psr\Http\Message\ServerRequestInterface;
use Shopware\Core\Framework\Api\OAuth\User\User;

/**
 * grant_type=shopbite_passkey on /api/oauth/token: the backoffice sends the signed WebAuthn
 * assertion (parameter "credential", JSON) instead of username and password, and gets the same
 * access and refresh token as a password login.
 *
 * Shopware's ScopeRepository grants "write" only to the password grant; a passkey login is the
 * same person proving themselves, so the scopes are finalized as for a password login.
 */
final class PasskeyGrant extends PasswordGrant
{
    public const string TYPE = 'shopbite_passkey';

    public function __construct(
        UserRepositoryInterface $userRepository,
        RefreshTokenRepositoryInterface $refreshTokenRepository,
        private readonly PasskeyService $passkeys,
    ) {
        parent::__construct($userRepository, $refreshTokenRepository);
    }

    #[Override]
    public function getIdentifier(): string
    {
        return self::TYPE;
    }

    #[Override]
    public function respondToAccessTokenRequest(
        ServerRequestInterface $request,
        ResponseTypeInterface $responseType,
        DateInterval $accessTokenTTL,
    ): ResponseTypeInterface {
        $client = $this->validateClient($request);
        $scopes = $this->validateScopes($this->getRequestParameter('scope', $request, $this->defaultScope));
        $user = $this->validateUser($request, $client);

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
        /** @var mixed $credential a JSON body can carry the credential as an object */
        $credential = $this->getRequestParameter('credential', $request);
        if (\is_array($credential)) {
            $credential = json_encode($credential, \JSON_THROW_ON_ERROR);
        }
        if (!\is_string($credential) || $credential === '') {
            throw OAuthServerException::invalidRequest('credential');
        }

        try {
            $userId = $this->passkeys->verifyLogin($credential);
            if ($userId === '') {
                throw new PasskeyException('Passkey login failed.');
            }

            return new User($userId);
        } catch (PasskeyException) {
            $this->getEmitter()->emit(new RequestEvent(RequestEvent::USER_AUTHENTICATION_FAILED, $request));

            throw OAuthServerException::invalidCredentials();
        }
    }
}
