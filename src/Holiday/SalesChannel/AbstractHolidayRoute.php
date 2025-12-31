<?php

declare(strict_types=1);

namespace ShopBite\Holiday\SalesChannel;

use Shopware\Core\System\SalesChannel\SalesChannelContext;

abstract readonly class AbstractHolidayRoute
{
    abstract public function getDecorated(): AbstractHolidayRoute;

    abstract public function load(SalesChannelContext $context): HolidayRouteResponse;
}
