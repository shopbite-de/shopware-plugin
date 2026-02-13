<?php

declare(strict_types=1);

namespace ShopBite\MultiChannelGroup\SalesChannel;

use ShopBite\MultiChannelGroup\MultiChannelGroupCollection;
use Shopware\Core\System\SalesChannel\StoreApiResponse;

/**
 * @extends StoreApiResponse<MultiChannelGroupStruct>
 */
final class MultiChannelGroupRouteResponse extends StoreApiResponse
{
    public function __construct(MultiChannelGroupStruct $object)
    {
        parent::__construct($object);
    }

    public function getMultiChannelGroup(): MultiChannelGroupCollection
    {
        return $this->object->multiChannelGroup;
    }
}
