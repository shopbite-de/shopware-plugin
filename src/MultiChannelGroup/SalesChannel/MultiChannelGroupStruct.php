<?php

declare(strict_types=1);

namespace ShopBite\MultiChannelGroup\SalesChannel;

use ShopBite\MultiChannelGroup\MultiChannelGroupCollection;
use Shopware\Core\Framework\Struct\Struct;

/**
 * @psalm-api JSON shape of the Store API response, read by the serializer
 */
final class MultiChannelGroupStruct extends Struct
{
    public function __construct(
        public readonly MultiChannelGroupCollection $multiChannelGroup
    ) {
    }
}
