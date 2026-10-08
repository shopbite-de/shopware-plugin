<?php

declare(strict_types=1);

namespace ShopBite\Wishlist\SalesChannel;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\NoContentResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

abstract readonly class AbstractWishlistRoute
{
    /** @psalm-suppress PossiblyUnusedMethod called by the Store API router and by decorators */
    abstract public function getDecorated(): AbstractWishlistRoute;

    abstract public function load(SalesChannelContext $context, CustomerEntity $customer): WishlistRouteResponse;

    /** @psalm-suppress PossiblyUnusedMethod called by the Store API router and by decorators */
    abstract public function add(RequestDataBag $data, SalesChannelContext $context, CustomerEntity $customer): WishlistItemRouteResponse;

    /** @psalm-suppress PossiblyUnusedMethod called by the Store API router and by decorators */
    abstract public function delete(string $id, SalesChannelContext $context, CustomerEntity $customer): NoContentResponse;

    /** @psalm-suppress PossiblyUnusedMethod called by the Store API router and by decorators */
    abstract public function merge(RequestDataBag $data, SalesChannelContext $context, CustomerEntity $customer): WishlistRouteResponse;
}
