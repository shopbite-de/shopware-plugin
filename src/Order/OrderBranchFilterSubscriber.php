<?php

declare(strict_types=1);

namespace ShopBite\Order;

use ShopBite\Staff\BranchBinding;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Event\BeforeEntityAggregationEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntitySearchedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Admin API users bound to branches (BranchBinding) read only the orders of these sales
 * channels: searches and aggregations on an entity of the order family get a filter on the
 * order's sales channel. Associations are loaded without these events, so criteria that reach
 * the order family from another entity (`sales_channel.orders`, `order.salesChannel.orders`,
 * also as filter, sorting or aggregation path) are refused for bound users (OrderPathGuard).
 * Admins, integrations, unbound users and system-scope reads are unchanged.
 *
 * @psalm-api
 */
final readonly class OrderBranchFilterSubscriber implements EventSubscriberInterface
{
    /** Never a sales channel id: a user with an unreadable binding sees no orders. */
    private const string NO_SALES_CHANNEL = '00000000000000000000000000000000';

    /** @psalm-suppress PossiblyUnusedMethod Constructed by the container */
    public function __construct(private BranchBinding $branchBinding)
    {
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            EntitySearchedEvent::class => 'onSearch',
            BeforeEntityAggregationEvent::class => 'onSearch',
        ];
    }

    public function onSearch(EntitySearchedEvent|BeforeEntityAggregationEvent $event): void
    {
        $context = $event->getContext();
        if ($context->getScope() === Context::SYSTEM_SCOPE || !$context->getSource() instanceof AdminApiSource) {
            return;
        }

        $definition = $event->getDefinition();
        $field = OrderFamily::SALES_CHANNEL_PATHS[$definition->getEntityName()] ?? null;
        $forbiddenPath = OrderPathGuard::forbiddenPath($definition, $event->getCriteria());
        if ($field === null && $forbiddenPath === null) {
            return;
        }

        $salesChannelIds = $this->branchBinding->salesChannelIds($context);
        if ($salesChannelIds === null) {
            return;
        }
        if ($forbiddenPath !== null) {
            throw BranchAccessException::orderPath($forbiddenPath);
        }
        $event->getCriteria()->addFilter(new EqualsAnyFilter($field, $salesChannelIds ?: [self::NO_SALES_CHANNEL]));
    }
}
