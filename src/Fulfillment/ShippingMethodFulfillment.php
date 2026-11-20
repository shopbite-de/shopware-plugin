<?php

declare(strict_types=1);

namespace ShopBite\Fulfillment;

use ShopBite\Checkout\Cart\Delivery\MinuteAwareDeliveryBuilder;
use ShopBite\Service\CustomFieldsInstaller;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\System\DeliveryTime\DeliveryTimeEntity;

/**
 * The single source of truth for what a shipping method means to the restaurant: its
 * fulfillment (delivery or pickup, custom field `shopbite_fulfillment`) and its lead time in
 * minutes (until delivered or ready for pickup), which is the maximum of the method's Shopware
 * delivery time converted to minutes and clamped to 1..1440. A method without a valid fulfillment
 * counts as delivery, one without a (known) delivery time has 30 minutes; nothing is guessed from
 * names at runtime.
 */
final readonly class ShippingMethodFulfillment
{
    public const string DELIVERY = 'delivery';

    public const string PICKUP = 'pickup';

    public const int DEFAULT_LEAD_TIME_MINUTES = 30;

    public const int MIN_LEAD_TIME_MINUTES = 1;

    public const int MAX_LEAD_TIME_MINUTES = 1440;

    /** The delivery time unit the plugin adds (Administration and MinuteAwareDeliveryBuilder). */
    public const string UNIT_MINUTE = MinuteAwareDeliveryBuilder::UNIT_MINUTE;

    /**
     * Minutes per delivery time unit. Months and years only matter as "more than a day", since
     * lead times are clamped to 1440 minutes.
     */
    private const array MINUTES_PER_UNIT = [
        self::UNIT_MINUTE => 1,
        DeliveryTimeEntity::DELIVERY_TIME_HOUR => 60,
        DeliveryTimeEntity::DELIVERY_TIME_DAY => 1440,
        DeliveryTimeEntity::DELIVERY_TIME_WEEK => 10080,
        DeliveryTimeEntity::DELIVERY_TIME_MONTH => 43200,
        DeliveryTimeEntity::DELIVERY_TIME_YEAR => 525600,
    ];

    /**
     * @return self::DELIVERY|self::PICKUP
     */
    public static function fulfillment(ShippingMethodEntity $method): string
    {
        return self::validFulfillment(self::customFields($method)[CustomFieldsInstaller::SHOPBITE_FULFILLMENT] ?? null) ?? self::DELIVERY;
    }

    /**
     * The lead time from the method's delivery time, which must be loaded (association
     * `deliveryTime`); see ShippingMethodLeadTimes::leadTimeMinutes() for a method without it.
     */
    public static function leadTimeMinutes(ShippingMethodEntity $method): int
    {
        return self::deliveryTimeMinutes($method->getDeliveryTime()) ?? self::DEFAULT_LEAD_TIME_MINUTES;
    }

    /**
     * Whether the method has a delivery time of a known unit (else the default of 30 minutes applies).
     */
    public static function isLeadTimeSet(ShippingMethodEntity $method): bool
    {
        return self::deliveryTimeMinutes($method->getDeliveryTime()) !== null;
    }

    /**
     * The maximum of a delivery time in minutes, clamped to 1..1440; null without a delivery
     * time or for an unknown unit.
     */
    public static function deliveryTimeMinutes(?DeliveryTimeEntity $deliveryTime): ?int
    {
        if ($deliveryTime === null) {
            return null;
        }

        return self::minutesOf($deliveryTime->getUnit(), $deliveryTime->getMax());
    }

    /**
     * @see deliveryTimeMinutes()
     */
    public static function minutesOf(string $unit, int $max): ?int
    {
        $factor = self::MINUTES_PER_UNIT[$unit] ?? null;
        if ($factor === null) {
            return null;
        }

        return max(self::MIN_LEAD_TIME_MINUTES, min(self::MAX_LEAD_TIME_MINUTES, $max * $factor));
    }

    /**
     * The name of a delivery time of exactly that many minutes: German for German locales
     * ("20 Minuten"), English for all others ("20 minutes").
     */
    public static function deliveryTimeName(int $minutes, string $localeCode): string
    {
        if (str_starts_with(strtolower($localeCode), 'de')) {
            return $minutes === 1 ? '1 Minute' : $minutes . ' Minuten';
        }

        return $minutes === 1 ? '1 minute' : $minutes . ' minutes';
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
