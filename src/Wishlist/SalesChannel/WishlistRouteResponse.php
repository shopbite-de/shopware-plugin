<?php

declare(strict_types=1);

namespace ShopBite\Wishlist\SalesChannel;

use Shopware\Core\System\SalesChannel\StoreApiResponse;

/**
 * @extends StoreApiResponse<WishlistStruct>
 */
final class WishlistRouteResponse extends StoreApiResponse
{
    public function __construct(WishlistStruct $object)
    {
        parent::__construct($object);
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod accessor for decorators and tests
     *
     * @return list<WishlistItemStruct>
     */
    public function getElements(): array
    {
        return $this->object->elements;
    }
}
