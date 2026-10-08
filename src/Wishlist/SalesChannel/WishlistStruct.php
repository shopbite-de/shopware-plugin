<?php

declare(strict_types=1);

namespace ShopBite\Wishlist\SalesChannel;

use Shopware\Core\Framework\Struct\Struct;

/**
 * @psalm-api
 */
final class WishlistStruct extends Struct
{
    /**
     * @param list<WishlistItemStruct> $elements newest first
     */
    public function __construct(
        public readonly array $elements,
    ) {
    }

    #[\Override]
    public function getApiAlias(): string
    {
        return 'shopbite_wishlist';
    }
}
