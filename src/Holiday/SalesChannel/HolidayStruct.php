<?php

declare(strict_types=1);

namespace ShopBite\Holiday\SalesChannel;

use ShopBite\Holiday\HolidayCollection;
use Shopware\Core\Framework\Struct\Struct;

/**
 * @psalm-api JSON shape of the Store API response, read by the serializer
 */
final class HolidayStruct extends Struct
{
    public function __construct(
        public readonly HolidayCollection $holidays
    ) {
    }
}
