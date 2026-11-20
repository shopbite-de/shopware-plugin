<?php

declare(strict_types=1);

namespace ShopBite\Migration;

use Doctrine\DBAL\Connection;
use ShopBite\Fulfillment\ShippingMethodFulfillment;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The lead time of a shipping method is its Shopware delivery time. Every shipping method whose
 * delivery time is not minute based (Shopware's default "1-3 days", a "0-1 hour", none) gets one of
 * exactly X minutes (an existing delivery time with unit minute and min = max = X, else a new one
 * named "X Minuten" / "X minutes"); a method with a minute delivery time keeps it. X is the value of
 * the removed custom field shopbite_lead_time_minutes when a translation holds a valid one (system
 * language first), else the former plugin setting defaultDeliveryTime (the values set explicitly on
 * the method's channels when they agree, else the global value, else 30). Delivery times are never
 * changed, only assigned. Afterwards the custom field, its key in the shipping method translations
 * and the former setting are removed. Running it again changes nothing.
 *
 * @psalm-api registered by Shopware's migration collection
 */
final class Migration1795651200ShippingMethodDeliveryTime extends MigrationStep
{
    private const string DELIVERY_TIME_KEY = 'ShopBitePlugin.config.defaultDeliveryTime';

    private const string LEAD_TIME_FIELD = 'shopbite_lead_time_minutes';

    #[\Override]
    public function getCreationTimestamp(): int
    {
        return 1795651200;
    }

    #[\Override]
    public function update(Connection $connection): void
    {
        [$global, $channelValues] = $this->deliveryTimes($connection);

        /** @var array<string, list<string>> $channelsByMethod */
        $channelsByMethod = [];
        foreach ($connection->fetchAllAssociative(
            'SELECT LOWER(HEX(shipping_method_id)) AS shipping_method_id, LOWER(HEX(sales_channel_id)) AS sales_channel_id FROM sales_channel_shipping_method',
        ) as $row) {
            $channelsByMethod[(string) $row['shipping_method_id']][] = (string) $row['sales_channel_id'];
        }

        /** @var array<string, array<string, array<array-key, mixed>|null>> $customFields by method and language */
        $customFields = [];
        foreach ($connection->fetchAllAssociative(
            'SELECT LOWER(HEX(shipping_method_id)) AS id, LOWER(HEX(language_id)) AS language_id, custom_fields FROM shipping_method_translation',
        ) as $row) {
            $customFields[(string) $row['id']][(string) $row['language_id']] = self::decode($row['custom_fields']);
        }

        $minuteDeliveryTimes = null;
        $locales = null;
        foreach ($connection->fetchAllAssociative(
            'SELECT LOWER(HEX(shipping_method.id)) AS id, LOWER(HEX(delivery_time.id)) AS delivery_time_id, delivery_time.unit
             FROM shipping_method LEFT JOIN delivery_time ON delivery_time.id = shipping_method.delivery_time_id',
        ) as $row) {
            if ($row['unit'] === ShippingMethodFulfillment::UNIT_MINUTE) {
                continue;
            }
            $id = (string) $row['id'];
            $minutes = self::customFieldMinutes($customFields[$id] ?? [])
                ?? self::minutes($channelsByMethod[$id] ?? [], $channelValues, $global);

            $minuteDeliveryTimes ??= $this->minuteDeliveryTimes($connection);
            if (!isset($minuteDeliveryTimes[$minutes])) {
                $locales ??= $this->locales($connection);
                $minuteDeliveryTimes[$minutes] = $this->createDeliveryTime($connection, $minutes, $locales);
            }
            $connection->executeStatement(
                'UPDATE shipping_method SET delivery_time_id = :deliveryTime, updated_at = :now WHERE id = :id',
                [
                    'deliveryTime' => Uuid::fromHexToBytes($minuteDeliveryTimes[$minutes]),
                    'now' => self::now(),
                    'id' => Uuid::fromHexToBytes($id),
                ],
            );
        }

        foreach ($customFields as $id => $translations) {
            foreach ($translations as $languageId => $current) {
                if ($current === null || !\array_key_exists(self::LEAD_TIME_FIELD, $current)) {
                    continue;
                }
                unset($current[self::LEAD_TIME_FIELD]);
                $connection->executeStatement(
                    'UPDATE shipping_method_translation SET custom_fields = :customFields WHERE shipping_method_id = :id AND language_id = :language',
                    [
                        'customFields' => $current === [] ? null : json_encode($current, \JSON_THROW_ON_ERROR),
                        'id' => Uuid::fromHexToBytes($id),
                        'language' => Uuid::fromHexToBytes($languageId),
                    ],
                );
            }
        }

        $connection->executeStatement('DELETE FROM custom_field WHERE name = :name', ['name' => self::LEAD_TIME_FIELD]);
        $connection->executeStatement('DELETE FROM system_config WHERE configuration_key = :key', ['key' => self::DELIVERY_TIME_KEY]);
    }

    /**
     * The former delivery time for a method: the values set explicitly on its channels when they
     * agree (channels without their own value do not count), else the global value, else 30.
     *
     * @param list<string>            $salesChannelIds
     * @param array<string, int|null> $channelValues   valid channel values by sales channel id
     */
    public static function minutes(array $salesChannelIds, array $channelValues, ?int $global): int
    {
        $values = [];
        foreach ($salesChannelIds as $salesChannelId) {
            if (isset($channelValues[$salesChannelId])) {
                $values[] = $channelValues[$salesChannelId];
            }
        }
        $values = array_values(array_unique($values, \SORT_REGULAR));
        $minutes = \count($values) === 1 ? $values[0] : $global;

        return $minutes ?? ShippingMethodFulfillment::DEFAULT_LEAD_TIME_MINUTES;
    }

    /**
     * A valid value of the removed custom field, system language first.
     *
     * @param array<string, array<array-key, mixed>|null> $translations custom fields by language
     */
    private static function customFieldMinutes(array $translations): ?int
    {
        $system = $translations[Defaults::LANGUAGE_SYSTEM] ?? null;
        unset($translations[Defaults::LANGUAGE_SYSTEM]);
        foreach ([$system, ...array_values($translations)] as $customFields) {
            $minutes = ShippingMethodFulfillment::validLeadTimeMinutes($customFields[self::LEAD_TIME_FIELD] ?? null);
            if ($minutes !== null) {
                return $minutes;
            }
        }

        return null;
    }

    /**
     * The existing delivery times of exactly X minutes, by X (the oldest when there are several).
     *
     * @return array<int, string>
     */
    private function minuteDeliveryTimes(Connection $connection): array
    {
        $ids = [];
        foreach ($connection->fetchAllAssociative(
            'SELECT LOWER(HEX(id)) AS id, min, max FROM delivery_time WHERE unit = :unit ORDER BY created_at, id',
            ['unit' => ShippingMethodFulfillment::UNIT_MINUTE],
        ) as $row) {
            $max = (int) $row['max'];
            if ((int) $row['min'] === $max && !isset($ids[$max])) {
                $ids[$max] = (string) $row['id'];
            }
        }

        return $ids;
    }

    /**
     * @param array<string, string> $locales locale code by language id
     */
    private function createDeliveryTime(Connection $connection, int $minutes, array $locales): string
    {
        $id = Uuid::randomBytes();
        $connection->executeStatement(
            'INSERT INTO delivery_time (id, min, max, unit, created_at) VALUES (:id, :min, :max, :unit, :now)',
            ['id' => $id, 'min' => $minutes, 'max' => $minutes, 'unit' => ShippingMethodFulfillment::UNIT_MINUTE, 'now' => self::now()],
        );
        foreach ($locales as $languageId => $locale) {
            $connection->executeStatement(
                'INSERT INTO delivery_time_translation (delivery_time_id, language_id, name, created_at) VALUES (:id, :language, :name, :now)',
                [
                    'id' => $id,
                    'language' => Uuid::fromHexToBytes($languageId),
                    'name' => ShippingMethodFulfillment::deliveryTimeName($minutes, $locale),
                    'now' => self::now(),
                ],
            );
        }

        return Uuid::fromBytesToHex($id);
    }

    /**
     * The locale code of every language by language id; the system language is always included.
     *
     * @return array<string, string>
     */
    private function locales(Connection $connection): array
    {
        $locales = [Defaults::LANGUAGE_SYSTEM => 'en-GB'];
        foreach ($connection->fetchAllAssociative(
            'SELECT LOWER(HEX(language.id)) AS id, locale.code AS locale
             FROM language LEFT JOIN locale ON locale.id = COALESCE(language.translation_code_id, language.locale_id)',
        ) as $row) {
            $locales[(string) $row['id']] = \is_string($row['locale']) ? $row['locale'] : '';
        }

        return $locales;
    }

    /**
     * @return array{int|null, array<string, int|null>}
     */
    private function deliveryTimes(Connection $connection): array
    {
        $global = null;
        $channelValues = [];
        foreach ($connection->fetchAllAssociative(
            'SELECT LOWER(HEX(sales_channel_id)) AS sales_channel_id, configuration_value AS value FROM system_config WHERE configuration_key = :key',
            ['key' => self::DELIVERY_TIME_KEY],
        ) as $row) {
            $value = self::decode($row['value'])['_value'] ?? null;
            $value = \is_string($value) && ctype_digit($value) ? (int) $value : $value;
            $minutes = ShippingMethodFulfillment::validLeadTimeMinutes($value);
            if ($row['sales_channel_id'] === null) {
                $global = $minutes;
            } elseif ($minutes !== null) {
                $channelValues[(string) $row['sales_channel_id']] = $minutes;
            }
        }

        return [$global, $channelValues];
    }

    private static function now(): string
    {
        return new \DateTimeImmutable()->format(Defaults::STORAGE_DATE_TIME_FORMAT);
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function decode(mixed $json): ?array
    {
        if (!\is_string($json) || $json === '') {
            return null;
        }
        try {
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($decoded) ? $decoded : null;
    }
}
