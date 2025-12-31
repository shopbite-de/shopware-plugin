<?php

declare(strict_types=1);

namespace ShopBite\Config\SalesChannel;

use Shopware\Core\Framework\Struct\Struct;

/** @psalm-api  */
final class ShopBiteConfigStruct extends Struct
{
    public function __construct(
        public readonly bool $isCheckoutEnabled,
        public readonly int $deliveryTime,
    ) {
    }
}
