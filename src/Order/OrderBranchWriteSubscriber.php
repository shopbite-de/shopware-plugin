<?php

declare(strict_types=1);

namespace ShopBite\Order;

use ShopBite\Staff\BranchBinding;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWriteEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Admin API users bound to branches write only orders (and their parts) of these sales
 * channels: before a write, every command on the order family is resolved to its order's
 * sales channel, as it is stored and as the command would set it (new order, other parent).
 * Admins, integrations, unbound users and system-scope writes are unchanged.
 *
 * @psalm-api
 */
final readonly class OrderBranchWriteSubscriber implements EventSubscriberInterface
{
    /** @psalm-suppress PossiblyUnusedMethod Constructed by the container */
    public function __construct(
        private BranchBinding $branchBinding,
        private OrderSalesChannelResolver $resolver,
    ) {
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [EntityWriteEvent::class => 'onWrite'];
    }

    public function onWrite(EntityWriteEvent $event): void
    {
        $context = $event->getContext();
        if ($context->getScope() === Context::SYSTEM_SCOPE || !$context->getSource() instanceof AdminApiSource) {
            return;
        }

        $commands = array_values(array_filter(
            $event->getCommands(),
            static fn (WriteCommand $command): bool => OrderFamily::contains(self::entityName($command)),
        ));
        if ($commands === []) {
            return;
        }

        $salesChannelIds = $this->branchBinding->salesChannelIds($context);
        if ($salesChannelIds === null) {
            return;
        }

        foreach ($commands as $command) {
            foreach ($this->salesChannelsOf($command) as $salesChannelId) {
                if ($salesChannelId === null || !BranchBinding::permits($salesChannelIds, $salesChannelId)) {
                    throw BranchAccessException::otherBranch(self::entityName($command));
                }
            }
        }
    }

    /**
     * The sales channels a command touches; null where it cannot be resolved (refused).
     *
     * @psalm-suppress InternalMethod write commands are internal, the event hands out nothing else
     *
     * @return list<string|null>
     */
    private function salesChannelsOf(WriteCommand $command): array
    {
        $entity = $command->getEntityName();
        $payload = $command->getPayload();
        $primaryKey = $command->getPrimaryKey();
        $salesChannels = [];

        if (!$command instanceof InsertCommand && isset($primaryKey['id']) && $entity !== 'order_tag') {
            $salesChannels[] = $this->resolver->ofRow($entity, $primaryKey['id']);
        }

        if ($entity === 'order') {
            if (\array_key_exists('sales_channel_id', $payload)) {
                $salesChannels[] = \is_string($payload['sales_channel_id']) ? bin2hex($payload['sales_channel_id']) : null;
            } elseif ($command instanceof InsertCommand) {
                $salesChannels[] = null;
            }

            return $salesChannels;
        }

        [$column, $parent] = OrderFamily::PARENTS[$entity];
        $parentId = $payload[$column] ?? $primaryKey[$column] ?? null;
        if ($parentId !== null) {
            $salesChannels[] = \is_string($parentId) ? $this->resolver->ofRow($parent, $parentId) : null;
        } elseif ($salesChannels === []) {
            $salesChannels[] = null;
        }

        return $salesChannels;
    }

    /**
     * @psalm-suppress InternalMethod
     */
    private static function entityName(WriteCommand $command): string
    {
        return $command->getEntityName();
    }
}
