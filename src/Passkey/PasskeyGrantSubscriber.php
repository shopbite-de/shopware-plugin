<?php

declare(strict_types=1);

namespace ShopBite\Passkey;

use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** SPIKE: enables the grant after Shopware's ApiAuthenticationListener (priority 128). */
final readonly class PasskeyGrantSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private AuthorizationServer $authorizationServer,
        private UserRepositoryInterface $userRepository,
        private RefreshTokenRepositoryInterface $refreshTokenRepository,
        private string $accessTokenTtl = 'PT10M',
        private string $refreshTokenTtl = 'P1W',
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => [['enableGrant', 127]]];
    }

    public function enableGrant(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $grant = new PasskeyGrant($this->userRepository, $this->refreshTokenRepository);
        $grant->setRefreshTokenTTL(new \DateInterval($this->refreshTokenTtl));
        $this->authorizationServer->enableGrantType($grant, new \DateInterval($this->accessTokenTtl));
    }
}
