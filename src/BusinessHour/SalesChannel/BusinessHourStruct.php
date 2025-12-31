<?php

declare(strict_types=1);

namespace ShopBite\BusinessHour\SalesChannel;

use ShopBite\BusinessHour\BusinessHourCollection;
use Shopware\Core\Framework\Struct\Struct;

final class BusinessHourStruct extends Struct
{
    public function __construct(
        public readonly BusinessHourCollection $businessHours
    ) {
    }
}
