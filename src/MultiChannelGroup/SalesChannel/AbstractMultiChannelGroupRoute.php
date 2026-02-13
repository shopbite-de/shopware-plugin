<?php

declare(strict_types=1);

namespace ShopBite\MultiChannelGroup\SalesChannel;

use Shopware\Core\System\SalesChannel\SalesChannelContext;

abstract readonly class AbstractMultiChannelGroupRoute
{
    abstract public function getDecorated(): AbstractMultiChannelGroupRoute;

    abstract public function load(SalesChannelContext $context): MultiChannelGroupRouteResponse;
}
