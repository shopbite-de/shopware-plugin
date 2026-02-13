<?php

declare(strict_types=1);

namespace ShopBite\MultiChannelGroup;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<MultiChannelGroupEntity>
 */
final class MultiChannelGroupCollection extends EntityCollection
{
    #[\Override]
    protected function getExpectedClass(): string
    {
        return MultiChannelGroupEntity::class;
    }
}
