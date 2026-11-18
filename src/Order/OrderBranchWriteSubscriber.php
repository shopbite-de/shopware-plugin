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

        $pending = self::pendingInserts($commands);
        foreach ($commands as $command) {
            foreach ($this->salesChannelsOf($command, $pending) as $salesChannelId) {
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
     * @param array<string, array{string, string|null}> $pending see pendingInserts()
     *
     * @return list<string|null>
     */
    private function salesChannelsOf(WriteCommand $command, array $pending): array
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
            $salesChannels[] = \is_string($parentId) ? $this->salesChannelOf($parent, $parentId, $pending) : null;
        } elseif ($salesChannels === []) {
            $salesChannels[] = null;
        }

        return $salesChannels;
    }

    /**
     * The sales channel of a row, also when the row is inserted in this same write (a new
     * order with its line items, a new delivery with its positions).
     *
     * @param array<string, array{string, string|null}> $pending
     */
    private function salesChannelOf(string $entity, string $id, array $pending, int $depth = 0): ?string
    {
        $insert = $pending[$entity . ':' . $id] ?? null;
        if ($insert === null || $depth > 5) {
            return $this->resolver->ofRow($entity, $id);
        }
        [$kind, $value] = $insert;
        if ($value === null) {
            return null;
        }

        return $kind === 'salesChannel' ? bin2hex($value) : $this->salesChannelOf($kind, $value, $pending, $depth + 1);
    }

    /**
     * Rows inserted by this write: `entity:id` => ['salesChannel', id] for orders,
     * [parent entity, parent id] for their parts.
     *
     * @param list<WriteCommand> $commands
     *
     * @psalm-suppress InternalMethod
     *
     * @return array<string, array{string, string|null}>
     */
    private static function pendingInserts(array $commands): array
    {
        $pending = [];
        foreach ($commands as $command) {
            $id = $command->getPrimaryKey()['id'] ?? null;
            if (!$command instanceof InsertCommand || !\is_string($id)) {
                continue;
            }
            $entity = $command->getEntityName();
            $payload = $command->getPayload();
            if ($entity === 'order') {
                $salesChannelId = $payload['sales_channel_id'] ?? null;
                $pending[$entity . ':' . $id] = ['salesChannel', \is_string($salesChannelId) ? $salesChannelId : null];

                continue;
            }
            $parent = OrderFamily::PARENTS[$entity] ?? null;
            if ($parent !== null) {
                $parentId = $payload[$parent[0]] ?? null;
                $pending[$entity . ':' . $id] = [$parent[1], \is_string($parentId) ? $parentId : null];
            }
        }

        return $pending;
    }

    /**
     * @psalm-suppress InternalMethod
     */
    private static function entityName(WriteCommand $command): string
    {
        return $command->getEntityName();
    }
}
