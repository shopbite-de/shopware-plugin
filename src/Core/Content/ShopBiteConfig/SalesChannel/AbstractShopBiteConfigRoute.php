<?php

declare(strict_types=1);

namespace ShopBite\Core\Content\ShopBiteConfig\SalesChannel;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

abstract class AbstractShopBiteConfigRoute
{
    abstract public function getDecorated(): self;

    abstract public function load(Criteria $criteria, SalesChannelContext $context): ShopBiteConfigRouteResponse;
}
