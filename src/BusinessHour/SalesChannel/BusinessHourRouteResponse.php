<?php

declare(strict_types=1);

namespace ShopBite\BusinessHour\SalesChannel;

use ShopBite\BusinessHour\BusinessHourCollection;
use Shopware\Core\System\SalesChannel\StoreApiResponse;

/**
 * @extends StoreApiResponse<BusinessHourStruct>
 */
final class BusinessHourRouteResponse extends StoreApiResponse
{
    public function __construct(BusinessHourStruct $object)
    {
        parent::__construct($object);
    }

    public function getBusinessHours(): BusinessHourCollection
    {
        return $this->object->businessHours;
    }
}
