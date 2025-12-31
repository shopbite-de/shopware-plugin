<?php

declare(strict_types=1);

namespace ShopBite\BusinessHour\SalesChannel;

use Shopware\Core\System\SalesChannel\SalesChannelContext;

abstract readonly class AbstractBusinessHourRoute
{
    abstract public function getDecorated(): AbstractBusinessHourRoute;

    abstract public function load(SalesChannelContext $context): BusinessHourRouteResponse;
}
