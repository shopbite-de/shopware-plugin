<?php

declare(strict_types=1);

namespace ShopBite\Config\SalesChannel;

use Shopware\Core\Framework\Struct\Struct;

/** @psalm-api  */
final class ShopBiteConfigStruct extends Struct
{
    public function __construct(
        public readonly bool $isCheckoutEnabled,
        public readonly ?string $addressStreet = null,
        public readonly ?string $addressPostalCode = null,
        public readonly ?string $addressCity = null,
        public readonly ?string $telephone = null,
        public readonly ?string $googleBusinessProfileUrl = null,
        public readonly ?string $menuCategoryId = null,
        /**
         * The active shipping methods of the sales channel, see ShippingMethodFulfillment.
         *
         * @var list<array{id: string, fulfillment: string, leadTimeMinutes: int}>
         */
        public readonly array $shippingMethods = [],
    ) {
    }
}
