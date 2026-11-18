<?php

declare(strict_types=1);

namespace ShopBite\BusinessHour\SalesChannel;

use ShopBite\BusinessHour\BusinessHourCollection;
use Shopware\Core\Framework\Struct\Struct;

/**
 * @psalm-api JSON shape of the Store API response, read by the serializer
 */
final class BusinessHourStruct extends Struct
{
    public function __construct(
        public readonly BusinessHourCollection $businessHours
    ) {
    }
}
