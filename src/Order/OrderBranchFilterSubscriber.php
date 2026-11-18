<?php

declare(strict_types=1);

namespace ShopBite\Order;

use ShopBite\Staff\BranchBinding;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Event\BeforeEntityAggregationEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntitySearchedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Admin API users bound to branches (BranchBinding) read only the orders of these sales
 * channels: searches and aggregations on orders and the entities that belong to one order
 * get a filter on the order's sales channel. Admins, integrations, unbound users and
 * system-scope reads are unchanged. Orders loaded as an association of another entity
 * (e.g. a customer's orders) are not filtered; writes are not checked here.
 *
 * @psalm-api
 */
final readonly class OrderBranchFilterSubscriber implements EventSubscriberInterface
{
    /** Entity name => path of the order's sales channel id. */
    private const array FILTERED = [
        'order' => 'salesChannelId',
        'order_line_item' => 'order.salesChannelId',
        'order_delivery' => 'order.salesChannelId',
        'order_transaction' => 'order.salesChannelId',
        'order_customer' => 'order.salesChannelId',
        'order_address' => 'order.salesChannelId',
        'document' => 'order.salesChannelId',
    ];

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
        $field = self::FILTERED[$event->getDefinition()->getEntityName()] ?? null;
        $context = $event->getContext();
        if ($field === null || $context->getScope() === Context::SYSTEM_SCOPE) {
            return;
        }

        $salesChannelIds = $this->branchBinding->salesChannelIds($context);
        if ($salesChannelIds !== null) {
            $event->getCriteria()->addFilter(new EqualsAnyFilter($field, $salesChannelIds));
        }
    }
}
