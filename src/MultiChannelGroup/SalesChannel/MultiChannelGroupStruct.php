<?php

declare(strict_types=1);

namespace ShopBite\MultiChannelGroup\SalesChannel;

use ShopBite\MultiChannelGroup\MultiChannelGroupCollection;
use Shopware\Core\Framework\Struct\Struct;

final class MultiChannelGroupStruct extends Struct
{
    public function __construct(
        public readonly MultiChannelGroupCollection $multiChannelGroup
    ) {
    }
}
