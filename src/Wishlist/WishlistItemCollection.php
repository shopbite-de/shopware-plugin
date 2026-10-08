<?php

declare(strict_types=1);

namespace ShopBite\Wishlist;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<WishlistItemEntity>
 */
final class WishlistItemCollection extends EntityCollection
{
    #[\Override]
    protected function getExpectedClass(): string
    {
        return WishlistItemEntity::class;
    }
}
