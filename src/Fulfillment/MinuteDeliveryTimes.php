<?php

declare(strict_types=1);

namespace ShopBite\Fulfillment;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Delivery times with the plugin's unit `minute` (lead times, see ShippingMethodFulfillment) on
 * the database level, shared by the settings write, the migration and the plugin lifecycle.
 *
 * Shopware core does not know the unit: without the plugin (MinuteAwareDeliveryBuilder) every cart
 * with such a delivery time fails. So whenever the plugin moves a shipping method to a minute
 * delivery time it remembers the method's previous delivery time in
 * `shopbite_shipping_method_delivery_time`; deactivate() puts the previous ones back (and is
 * refused while other shipping methods or products would keep a minute delivery time), activate()
 * reassigns the minute ones.
 */
final readonly class MinuteDeliveryTimes
{
    public const string TABLE = 'shopbite_shipping_method_delivery_time';

    private const string UNIT = ShippingMethodFulfillment::UNIT_MINUTE;

    private const int MAX_LISTED = 20;

    /**
     * @psalm-suppress PossiblyUnusedMethod Constructed by the container
     */
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * The locale code of every language by language id; the system language is always included.
     *
     * @return array<string, string>
     */
    public function locales(): array
    {
        $locales = [Defaults::LANGUAGE_SYSTEM => 'en-GB'];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(language.id)) AS id, locale.code AS locale
             FROM language LEFT JOIN locale ON locale.id = COALESCE(language.translation_code_id, language.locale_id)',
        ) as $row) {
            $locales[(string) $row['id']] = \is_string($row['locale']) ? $row['locale'] : '';
        }

        return $locales;
    }

    /**
     * The existing delivery times of exactly X minutes (unit minute, min = max = X) by X, the
     * oldest when there are several.
     *
     * @return array<int, string>
     */
    public function existing(): array
    {
        $ids = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(id)) AS id, min, max FROM delivery_time WHERE unit = :unit ORDER BY created_at, id',
            ['unit' => self::UNIT],
        ) as $row) {
            $max = (int) $row['max'];
            if ((int) $row['min'] === $max && !isset($ids[$max])) {
                $ids[$max] = (string) $row['id'];
            }
        }

        return $ids;
    }

    /**
     * Creates a delivery time of exactly that many minutes, named in every language
     * ("20 Minuten" / "20 minutes"), and returns its id.
     *
     * @param array<string, string> $locales locale code by language id, see locales()
     */
    public function create(int $minutes, array $locales): string
    {
        $id = Uuid::randomBytes();
        $now = self::now();
        $this->connection->executeStatement(
            'INSERT INTO delivery_time (id, min, max, unit, created_at) VALUES (:id, :min, :max, :unit, :now)',
            ['id' => $id, 'min' => $minutes, 'max' => $minutes, 'unit' => self::UNIT, 'now' => $now],
        );
        foreach ($locales as $languageId => $locale) {
            $this->connection->executeStatement(
                'INSERT INTO delivery_time_translation (delivery_time_id, language_id, name, created_at) VALUES (:id, :language, :name, :now)',
                [
                    'id' => $id,
                    'language' => Uuid::fromHexToBytes($languageId),
                    'name' => ShippingMethodFulfillment::deliveryTimeName($minutes, $locale),
                    'now' => $now,
                ],
            );
        }

        return Uuid::fromBytesToHex($id);
    }

    /**
     * Records that a shipping method was moved to a minute delivery time.
     *
     * @param string|null $previousDeliveryTimeId the method's delivery time before, null when that
     *                                            was a minute one too (the recorded one is kept)
     */
    public function remember(string $shippingMethodId, ?string $previousDeliveryTimeId, string $minuteDeliveryTimeId): void
    {
        $this->connection->executeStatement(
            'INSERT INTO ' . self::TABLE . ' (shipping_method_id, previous_delivery_time_id, minute_delivery_time_id, created_at)
             VALUES (:method, :previous, :minute, :now)
             ON DUPLICATE KEY UPDATE
                previous_delivery_time_id = COALESCE(VALUES(previous_delivery_time_id), previous_delivery_time_id),
                minute_delivery_time_id = VALUES(minute_delivery_time_id),
                updated_at = VALUES(created_at)',
            [
                'method' => Uuid::fromHexToBytes($shippingMethodId),
                'previous' => $previousDeliveryTimeId === null ? null : Uuid::fromHexToBytes($previousDeliveryTimeId),
                'minute' => Uuid::fromHexToBytes($minuteDeliveryTimeId),
                'now' => self::now(),
            ],
        );
    }

    /**
     * The delivery time id and unit of shipping methods.
     *
     * @param list<string> $shippingMethodIds
     *
     * @return array<string, array{id: string, unit: string|null}>
     */
    public function current(array $shippingMethodIds): array
    {
        if ($shippingMethodIds === []) {
            return [];
        }
        $current = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(shipping_method.id)) AS id, LOWER(HEX(shipping_method.delivery_time_id)) AS delivery_time_id, delivery_time.unit
             FROM shipping_method LEFT JOIN delivery_time ON delivery_time.id = shipping_method.delivery_time_id
             WHERE shipping_method.id IN (:ids)',
            ['ids' => array_map(Uuid::fromHexToBytes(...), $shippingMethodIds)],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::BINARY],
        ) as $row) {
            $current[(string) $row['id']] = ['id' => (string) $row['delivery_time_id'], 'unit' => \is_string($row['unit']) ? $row['unit'] : null];
        }

        return $current;
    }

    /**
     * Puts the previous delivery time back on every shipping method the plugin moved to minutes.
     * Refused (nothing changed) while a shipping method without a remembered previous delivery
     * time or a product uses a minute delivery time, since Shopware cannot calculate their carts
     * without the plugin.
     *
     * @throws MinuteDeliveryTimesException
     */
    public function deactivate(): void
    {
        $blockers = $this->blockers();
        if ($blockers !== []) {
            throw MinuteDeliveryTimesException::stillInUse($blockers);
        }
        $this->connection->executeStatement(
            'UPDATE shipping_method
             INNER JOIN ' . self::TABLE . ' remembered ON remembered.shipping_method_id = shipping_method.id
             INNER JOIN delivery_time ON delivery_time.id = shipping_method.delivery_time_id
             SET shipping_method.delivery_time_id = remembered.previous_delivery_time_id
             WHERE delivery_time.unit = \'minute\' AND remembered.previous_delivery_time_id IS NOT NULL',
        );
    }

    /**
     * Reassigns the minute delivery times to the methods that are still on the delivery time
     * deactivate() put back.
     */
    public function activate(): void
    {
        $this->connection->executeStatement(
            'UPDATE shipping_method
             INNER JOIN ' . self::TABLE . ' remembered ON remembered.shipping_method_id = shipping_method.id
             SET shipping_method.delivery_time_id = remembered.minute_delivery_time_id
             WHERE shipping_method.delivery_time_id = remembered.previous_delivery_time_id',
        );
    }

    public function dropTable(): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS ' . self::TABLE);
    }

    /**
     * What would keep a minute delivery time after deactivate(): shipping methods without a
     * remembered previous delivery time and products (live version).
     *
     * @return list<string>
     */
    private function blockers(): array
    {
        $blockers = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT COALESCE(
                    (SELECT name FROM shipping_method_translation WHERE shipping_method_id = shipping_method.id AND language_id = :language),
                    shipping_method.technical_name,
                    LOWER(HEX(shipping_method.id))
                ) AS name
             FROM shipping_method
             INNER JOIN delivery_time ON delivery_time.id = shipping_method.delivery_time_id
             LEFT JOIN ' . self::TABLE . ' remembered ON remembered.shipping_method_id = shipping_method.id
             WHERE delivery_time.unit = :unit AND remembered.previous_delivery_time_id IS NULL
             ORDER BY name LIMIT ' . self::MAX_LISTED,
            ['language' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM), 'unit' => self::UNIT],
        ) as $row) {
            $blockers[] = \sprintf('shipping method "%s"', (string) $row['name']);
        }
        foreach ($this->connection->fetchAllAssociative(
            'SELECT product.product_number AS name
             FROM product INNER JOIN delivery_time ON delivery_time.id = product.delivery_time_id
             WHERE delivery_time.unit = :unit AND product.version_id = :version
             ORDER BY name LIMIT ' . self::MAX_LISTED,
            ['unit' => self::UNIT, 'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)],
        ) as $row) {
            $blockers[] = \sprintf('product "%s"', (string) $row['name']);
        }

        return $blockers;
    }

    private static function now(): string
    {
        return new \DateTimeImmutable()->format(Defaults::STORAGE_DATE_TIME_FORMAT);
    }
}
