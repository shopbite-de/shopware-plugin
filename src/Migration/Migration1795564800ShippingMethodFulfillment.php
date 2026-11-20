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
 * One-time data migration for the fulfillment per shipping method. Where the custom field
 * shopbite_fulfillment of the system language translation is empty or invalid, it is filled:
 * pickup when the technical name or a name contains "abhol", "pickup" or "selbst", else delivery;
 * a valid value stays. The result is written into every translation of the method, because the
 * channel language's custom fields win over the system language's. The lead time is the method's
 * delivery time, see Migration1795651200ShippingMethodDeliveryTime.
 *
 * @psalm-api registered by Shopware's migration collection
 */
final class Migration1795564800ShippingMethodFulfillment extends MigrationStep
{
    private const string PICKUP_PATTERN = '/abhol|pickup|selbst/i';

    #[\Override]
    public function getCreationTimestamp(): int
    {
        return 1795564800;
    }

    #[\Override]
    public function update(Connection $connection): void
    {
        $names = [];
        /** @var array<string, array<string, array<array-key, mixed>|null>> $customFields by method and language */
        $customFields = [];
        foreach ($connection->fetchAllAssociative(
            'SELECT LOWER(HEX(shipping_method_id)) AS id, LOWER(HEX(language_id)) AS language_id, name, custom_fields FROM shipping_method_translation',
        ) as $row) {
            $id = (string) $row['id'];
            if (\is_string($row['name']) && $row['name'] !== '') {
                $names[$id][] = $row['name'];
            }
            $customFields[$id][(string) $row['language_id']] = self::decode($row['custom_fields']);
        }

        foreach ($connection->fetchAllAssociative(
            'SELECT LOWER(HEX(id)) AS id, technical_name FROM shipping_method',
        ) as $row) {
            $id = (string) $row['id'];
            $translations = $customFields[$id] ?? [];
            if (!\array_key_exists(Defaults::LANGUAGE_SYSTEM, $translations)) {
                continue;
            }
            $fulfillment = self::backfill($translations[Defaults::LANGUAGE_SYSTEM], (string) $row['technical_name'], $names[$id] ?? [])[CustomFieldsInstaller::SHOPBITE_FULFILLMENT];
            foreach ($translations as $languageId => $current) {
                $updated = $current ?? [];
                $updated[CustomFieldsInstaller::SHOPBITE_FULFILLMENT] = $fulfillment;
                if ($updated === $current) {
                    continue;
                }
                $connection->executeStatement(
                    'UPDATE shipping_method_translation SET custom_fields = :customFields WHERE shipping_method_id = :id AND language_id = :language',
                    [
                        'customFields' => json_encode($updated, \JSON_THROW_ON_ERROR),
                        'id' => Uuid::fromHexToBytes($id),
                        'language' => Uuid::fromHexToBytes($languageId),
                    ],
                );
            }
        }
    }

    /**
     * The custom fields with the fulfillment filled when it is empty or invalid.
     *
     * @param array<array-key, mixed>|null $customFields
     * @param list<string>                 $names        the method's names in all languages
     *
     * @return array<array-key, mixed>
     */
    public static function backfill(?array $customFields, string $technicalName, array $names): array
    {
        $customFields ??= [];
        if (ShippingMethodFulfillment::validFulfillment($customFields[CustomFieldsInstaller::SHOPBITE_FULFILLMENT] ?? null) === null) {
            $pickup = false;
            foreach ([$technicalName, ...$names] as $name) {
                $pickup = $pickup || preg_match(self::PICKUP_PATTERN, $name) === 1;
            }
            $customFields[CustomFieldsInstaller::SHOPBITE_FULFILLMENT] = $pickup ? ShippingMethodFulfillment::PICKUP : ShippingMethodFulfillment::DELIVERY;
        }

        return $customFields;
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
