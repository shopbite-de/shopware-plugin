<?php

declare(strict_types=1);

namespace ShopBite\Migration;

use Doctrine\DBAL\Connection;
use ShopBite\Fulfillment\ShippingMethodFulfillment;
use ShopBite\Service\CustomFieldsInstaller;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * One-time data migration for the lead time per shipping method: fills the custom fields
 * shopbite_fulfillment (pickup when the technical name or a name contains "abhol", "pickup" or
 * "selbst", else delivery) and shopbite_lead_time_minutes (the former plugin setting
 * defaultDeliveryTime: the channel value when all channels of the method agree, else the global
 * value, else 30) of every shipping method, only where a field is empty or invalid. Written into
 * the system language translation, which all other languages fall back to.
 *
 * @psalm-api registered by Shopware's migration collection
 */
final class Migration1795564800ShippingMethodFulfillment extends MigrationStep
{
    private const string DELIVERY_TIME_KEY = 'ShopBitePlugin.config.defaultDeliveryTime';

    private const string PICKUP_PATTERN = '/abhol|pickup|selbst/i';

    #[\Override]
    public function getCreationTimestamp(): int
    {
        return 1795564800;
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

        $names = [];
        $systemCustomFields = [];
        foreach ($connection->fetchAllAssociative(
            'SELECT LOWER(HEX(shipping_method_id)) AS id, LOWER(HEX(language_id)) AS language_id, name, custom_fields FROM shipping_method_translation',
        ) as $row) {
            $id = (string) $row['id'];
            if (\is_string($row['name']) && $row['name'] !== '') {
                $names[$id][] = $row['name'];
            }
            if ($row['language_id'] === Defaults::LANGUAGE_SYSTEM) {
                $systemCustomFields[$id] = self::decode($row['custom_fields']);
            }
        }

        foreach ($connection->fetchAllAssociative(
            'SELECT LOWER(HEX(id)) AS id, technical_name FROM shipping_method',
        ) as $row) {
            $id = (string) $row['id'];
            if (!\array_key_exists($id, $systemCustomFields)) {
                continue;
            }
            $current = $systemCustomFields[$id];
            $filled = self::backfill(
                $current,
                (string) $row['technical_name'],
                $names[$id] ?? [],
                self::minutes($channelsByMethod[$id] ?? [], $channelValues, $global),
            );
            if ($filled === $current) {
                continue;
            }
            $connection->executeStatement(
                'UPDATE shipping_method_translation SET custom_fields = :customFields WHERE shipping_method_id = :id AND language_id = :language',
                [
                    'customFields' => json_encode($filled, \JSON_THROW_ON_ERROR),
                    'id' => Uuid::fromHexToBytes($id),
                    'language' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
                ],
            );
        }
    }

    /**
     * The custom fields with fulfillment and lead time filled where they are empty or invalid.
     *
     * @param array<array-key, mixed>|null $customFields
     * @param list<string>                 $names        the method's names in all languages
     *
     * @return array<array-key, mixed>
     */
    public static function backfill(?array $customFields, string $technicalName, array $names, int $minutes): array
    {
        $customFields ??= [];
        if (ShippingMethodFulfillment::validFulfillment($customFields[CustomFieldsInstaller::SHOPBITE_FULFILLMENT] ?? null) === null) {
            $pickup = false;
            foreach ([$technicalName, ...$names] as $name) {
                $pickup = $pickup || preg_match(self::PICKUP_PATTERN, $name) === 1;
            }
            $customFields[CustomFieldsInstaller::SHOPBITE_FULFILLMENT] = $pickup ? ShippingMethodFulfillment::PICKUP : ShippingMethodFulfillment::DELIVERY;
        }
        if (ShippingMethodFulfillment::validLeadTimeMinutes($customFields[CustomFieldsInstaller::SHOPBITE_LEAD_TIME_MINUTES] ?? null) === null) {
            $customFields[CustomFieldsInstaller::SHOPBITE_LEAD_TIME_MINUTES] = $minutes;
        }

        return $customFields;
    }

    /**
     * The former delivery time for a method: the channel value (else the global value) when all
     * its channels agree, else the global value, else 30.
     *
     * @param list<string>              $salesChannelIds
     * @param array<string, int|null>   $channelValues   valid channel values by sales channel id
     */
    public static function minutes(array $salesChannelIds, array $channelValues, ?int $global): int
    {
        $values = [];
        foreach ($salesChannelIds as $salesChannelId) {
            $values[] = $channelValues[$salesChannelId] ?? $global;
        }
        $values = array_values(array_unique($values, \SORT_REGULAR));
        $minutes = \count($values) === 1 ? $values[0] : $global;

        return $minutes ?? ShippingMethodFulfillment::DEFAULT_LEAD_TIME_MINUTES;
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
