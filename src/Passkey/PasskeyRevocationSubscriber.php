<?php

declare(strict_types=1);

namespace ShopBite\Passkey;

use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\User\UserEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * A user's passkeys are removed when their password changes or they are deactivated, after
 * every DAL write on `user`: the Administration, the Admin API, password recovery and the
 * shopbite:staff:* commands.
 *
 * @psalm-api Registered as event subscriber in services.xml
 */
final readonly class PasskeyRevocationSubscriber implements EventSubscriberInterface
{
    /** @psalm-suppress PossiblyUnusedMethod Constructed by the DI container */
    public function __construct(private PasskeyStore $store)
    {
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [UserEvents::USER_WRITTEN_EVENT => 'onUserWritten'];
    }

    public function onUserWritten(EntityWrittenEvent $event): void
    {
        $userIds = [];
        foreach ($event->getWriteResults() as $result) {
            if ($result->getOperation() !== EntityWriteResult::OPERATION_UPDATE) {
                continue;
            }
            $deactivated = $result->hasPayload('active') && !$result->getProperty('active');
            if (!$result->hasPayload('password') && !$deactivated) {
                continue;
            }
            $id = $result->getPrimaryKey();
            if (\is_string($id) && Uuid::isValid($id)) {
                $userIds[] = $id;
            }
        }

        $this->store->deleteForUsers(array_values(array_unique($userIds)));
    }
}
