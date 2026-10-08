<?php

declare(strict_types=1);

namespace ShopBite\Wishlist\SalesChannel;

use Shopware\Core\System\SalesChannel\StoreApiResponse;

/**
 * @extends StoreApiResponse<WishlistItemStruct>
 */
final class WishlistItemRouteResponse extends StoreApiResponse
{
    public function __construct(WishlistItemStruct $object)
    {
        parent::__construct($object);
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod accessor for decorators and tests
     */
    public function getItem(): WishlistItemStruct
    {
        return $this->object;
    }
}
