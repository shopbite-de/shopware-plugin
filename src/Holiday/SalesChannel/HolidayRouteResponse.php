<?php

declare(strict_types=1);

namespace ShopBite\Holiday\SalesChannel;

use ShopBite\Holiday\HolidayCollection;
use Shopware\Core\System\SalesChannel\StoreApiResponse;

/**
 * @extends StoreApiResponse<HolidayStruct>
 */
final class HolidayRouteResponse extends StoreApiResponse
{
    public function __construct(HolidayStruct $object)
    {
        parent::__construct($object);
    }

    public function getHolidays(): HolidayCollection
    {
        return $this->object->holidays;
    }
}
