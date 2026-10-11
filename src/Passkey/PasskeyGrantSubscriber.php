<?php

declare(strict_types=1);

namespace ShopBite\Passkey;

use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Shopware enables its grants on every main request in ApiAuthenticationListener::setupOAuth()
 * (priority 128); this adds the passkey grant to the same authorization server right after.
 *
 * @psalm-suppress UnusedClass Registered as event subscriber in services.xml
 */
final readonly class PasskeyGrantSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private AuthorizationServer $authorizationServer,
        private UserRepositoryInterface $userRepository,
        private RefreshTokenRepositoryInterface $refreshTokenRepository,
        private PasskeyService $passkeys,
        private string $accessTokenTtl = 'PT10M',
        private string $refreshTokenTtl = 'P1W',
    ) {
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => [['enableGrant', 127]]];
    }

    public function enableGrant(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->passkeys->enabled()) {
            return;
        }
        $grant = new PasskeyGrant($this->userRepository, $this->refreshTokenRepository, $this->passkeys);
        $grant->setRefreshTokenTTL(new \DateInterval($this->refreshTokenTtl));
        $this->authorizationServer->enableGrantType($grant, new \DateInterval($this->accessTokenTtl));
    }
}
