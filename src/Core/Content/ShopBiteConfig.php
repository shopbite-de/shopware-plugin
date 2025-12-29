<?php

declare(strict_types=1);

namespace ShopBite\Core\Content;

use Shopware\Core\Framework\Struct\Struct;

final class ShopBiteConfig extends Struct
{
    public function __construct(
        public readonly bool $isCheckoutEnabled,
        public readonly int $deliveryTime,
    ) {
    }
}
