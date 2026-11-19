<?php

declare(strict_types=1);

namespace ShopBite\Fulfillment;

use ShopBite\Service\CustomFieldsInstaller;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;

/**
 * The single source of truth for what a shipping method means to the restaurant: its
 * fulfillment (delivery or pickup) and its lead time in minutes (until delivered or ready for
 * pickup), read from the shipping method custom fields `shopbite_fulfillment` and
 * `shopbite_lead_time_minutes`. A method without (valid) values counts as delivery with
 * 30 minutes; nothing is guessed from names at runtime (existing methods got the values once
 * from Migration1795564800ShippingMethodFulfillment).
 */
final readonly class ShippingMethodFulfillment
{
    public const string DELIVERY = 'delivery';

    public const string PICKUP = 'pickup';

    public const int DEFAULT_LEAD_TIME_MINUTES = 30;

    public const int MIN_LEAD_TIME_MINUTES = 1;

    public const int MAX_LEAD_TIME_MINUTES = 1440;

    /**
     * @return self::DELIVERY|self::PICKUP
     */
    public static function fulfillment(ShippingMethodEntity $method): string
    {
        return self::validFulfillment(self::customFields($method)[CustomFieldsInstaller::SHOPBITE_FULFILLMENT] ?? null) ?? self::DELIVERY;
    }

    public static function leadTimeMinutes(ShippingMethodEntity $method): int
    {
        return self::configuredLeadTimeMinutes($method) ?? self::DEFAULT_LEAD_TIME_MINUTES;
    }

    /**
     * Whether the lead time is set on the method (else the default of 30 minutes applies).
     */
    public static function isLeadTimeSet(ShippingMethodEntity $method): bool
    {
        return self::configuredLeadTimeMinutes($method) !== null;
    }

    /**
     * The name in the context language, else the stored one, else the technical name.
     */
    public static function name(ShippingMethodEntity $method): string
    {
        $name = $method->getTranslation('name') ?? $method->getName();

        return \is_string($name) && $name !== '' ? $name : $method->getTechnicalName();
    }

    /**
     * @return self::DELIVERY|self::PICKUP|null
     */
    public static function validFulfillment(mixed $value): ?string
    {
        return match ($value) {
            self::DELIVERY => self::DELIVERY,
            self::PICKUP => self::PICKUP,
            default => null,
        };
    }

    public static function validLeadTimeMinutes(mixed $value): ?int
    {
        return \is_int($value) && $value >= self::MIN_LEAD_TIME_MINUTES && $value <= self::MAX_LEAD_TIME_MINUTES ? $value : null;
    }

    private static function configuredLeadTimeMinutes(ShippingMethodEntity $method): ?int
    {
        return self::validLeadTimeMinutes(self::customFields($method)[CustomFieldsInstaller::SHOPBITE_LEAD_TIME_MINUTES] ?? null);
    }

    /**
     * Custom fields are translated: the DAL puts the merged values of the language chain into
     * `translated`, the raw property holds only those of the context language.
     *
     * @return array<array-key, mixed>
     */
    private static function customFields(ShippingMethodEntity $method): array
    {
        $customFields = $method->getTranslation('customFields') ?? $method->getCustomFields();

        return \is_array($customFields) ? $customFields : [];
    }
}
