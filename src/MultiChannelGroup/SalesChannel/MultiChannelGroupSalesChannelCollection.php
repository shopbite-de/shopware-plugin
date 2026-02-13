<?php

declare(strict_types=1);

namespace ShopBite\MultiChannelGroup\SalesChannel;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<MultiChannelGroupSalesChannelEntity>
 */
final class MultiChannelGroupSalesChannelCollection extends EntityCollection
{
    #[\Override]
    protected function getExpectedClass(): string
    {
        return MultiChannelGroupSalesChannelEntity::class;
    }
}
