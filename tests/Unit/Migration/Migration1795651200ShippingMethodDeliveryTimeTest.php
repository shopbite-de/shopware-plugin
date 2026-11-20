<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Migration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ShopBite\Fulfillment\MinuteDeliveryTimes;
use ShopBite\Fulfillment\ShippingMethodFulfillment;
use ShopBite\Migration\Migration1795651200ShippingMethodDeliveryTime;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

#[CoversClass(Migration1795651200ShippingMethodDeliveryTime::class)]
#[UsesClass(ShippingMethodFulfillment::class)]
#[UsesClass(MinuteDeliveryTimes::class)]
class Migration1795651200ShippingMethodDeliveryTimeTest extends TestCase
{
    private const string CHANNEL = '0190000000007000800000000000b001';

    private const string OTHER_CHANNEL = '0190000000007000800000000000b002';

    private const string THIRD_CHANNEL = '0190000000007000800000000000b003';

    private const string DELIVERY = '0190000000007000800000000000d001';

    private const string PICKUP = '0190000000007000800000000000d002';

    private const string EXPRESS = '0190000000007000800000000000d003';

    private const string MINUTES = '0190000000007000800000000000d004';

    private const string UNASSIGNED = '0190000000007000800000000000d005';

    private const string GERMAN = '0190000000007000800000000000e001';

    private const string HOUR_RANGE = '0190000000007000800000000000f001';

    private const string DAYS = '0190000000007000800000000000f002';

    private const string MINUTES_25 = '0190000000007000800000000000f003';

    private const string MINUTES_RANGE = '0190000000007000800000000000f004';

    /** @var list<array{string, array<string, mixed>}> */
    private array $statements = [];

    public function testMinutesComeFromTheDeliveryTimeOfTheMethodsChannels(): void
    {
        $values = [self::CHANNEL => 45, self::OTHER_CHANNEL => 50];

        $this->assertSame(45, Migration1795651200ShippingMethodDeliveryTime::minutes([self::CHANNEL], $values, 40));
        $this->assertSame(40, Migration1795651200ShippingMethodDeliveryTime::minutes([self::CHANNEL, self::OTHER_CHANNEL], $values, 40), 'differing channels: the global value');
        $this->assertSame(40, Migration1795651200ShippingMethodDeliveryTime::minutes([self::THIRD_CHANNEL], $values, 40), 'channel without own value: the global value');
        $this->assertSame(45, Migration1795651200ShippingMethodDeliveryTime::minutes([self::CHANNEL, self::THIRD_CHANNEL], $values, 40), 'channels without own value do not count');
        $this->assertSame(45, Migration1795651200ShippingMethodDeliveryTime::minutes([self::CHANNEL, self::THIRD_CHANNEL], $values, null));
        $this->assertSame(40, Migration1795651200ShippingMethodDeliveryTime::minutes([], $values, 40));
        $this->assertSame(30, Migration1795651200ShippingMethodDeliveryTime::minutes([], [], null), 'nothing configured: 30');
    }

    public function testMethodsWithoutMinuteDeliveryTimeGetOneFromTheFormerSettingOrCustomField(): void
    {
        $this->migrate(
            methods: [
                // "0-1 hour", shared: each method gets its own minute delivery time
                ['id' => self::DELIVERY, 'delivery_time_id' => self::HOUR_RANGE, 'unit' => 'hour'],
                ['id' => self::PICKUP, 'delivery_time_id' => self::HOUR_RANGE, 'unit' => 'hour'],
                // Shopware's default "1-3 days"
                ['id' => self::EXPRESS, 'delivery_time_id' => self::DAYS, 'unit' => 'day'],
                // already minute based: kept, also when the removed custom field says otherwise
                ['id' => self::MINUTES, 'delivery_time_id' => self::MINUTES_RANGE, 'unit' => 'minute'],
                // in no sales channel: stays as it is
                ['id' => self::UNASSIGNED, 'delivery_time_id' => self::DAYS, 'unit' => 'day'],
            ],
            translations: [
                ['id' => self::DELIVERY, 'language_id' => Defaults::LANGUAGE_SYSTEM, 'custom_fields' => '{"shopbite_fulfillment": "delivery"}'],
                ['id' => self::PICKUP, 'language_id' => Defaults::LANGUAGE_SYSTEM, 'custom_fields' => null],
                // the removed custom field (a demo with the unreleased version) wins over the setting
                ['id' => self::EXPRESS, 'language_id' => Defaults::LANGUAGE_SYSTEM, 'custom_fields' => '{"shopbite_fulfillment": "delivery", "shopbite_lead_time_minutes": 15}'],
                ['id' => self::EXPRESS, 'language_id' => self::GERMAN, 'custom_fields' => '{"shopbite_lead_time_minutes": 15}'],
                ['id' => self::MINUTES, 'language_id' => Defaults::LANGUAGE_SYSTEM, 'custom_fields' => '{"shopbite_lead_time_minutes": 99, "other": 1}'],
            ],
        );

        $created = $this->createdDeliveryTimes();
        // DELIVERY: the only channel with an own value says 25 -> the existing "25 Minuten" is reused.
        // PICKUP: its channel has no own value -> the global 40. EXPRESS: the custom field, 15.
        $this->assertSame([40, 15], array_column($created, 'max'));
        foreach ($created as $deliveryTime) {
            $this->assertSame('minute', $deliveryTime['unit']);
            $this->assertSame($deliveryTime['max'], $deliveryTime['min']);
        }
        $this->assertSame([
            self::PICKUP . '/' . Defaults::LANGUAGE_SYSTEM => '40 minutes',
            self::PICKUP . '/' . self::GERMAN => '40 Minuten',
            self::EXPRESS . '/' . Defaults::LANGUAGE_SYSTEM => '15 minutes',
            self::EXPRESS . '/' . self::GERMAN => '15 Minuten',
        ], $this->createdNames([self::PICKUP => $created[0]['id'], self::EXPRESS => $created[1]['id']]));

        $this->assertSame([
            self::DELIVERY => self::MINUTES_25,
            self::PICKUP => $created[0]['id'],
            self::EXPRESS => $created[1]['id'],
        ], $this->assignedDeliveryTimes());

        // the previous delivery times are remembered for deactivate()
        $this->assertSame([
            self::DELIVERY => [self::HOUR_RANGE, self::MINUTES_25],
            self::PICKUP => [self::HOUR_RANGE, $created[0]['id']],
            self::EXPRESS => [self::DAYS, $created[1]['id']],
        ], $this->remembered());
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `shopbite_shipping_method_delivery_time`', $this->statements[0][0]);
        preg_match_all('/`([^`]+)`/', $this->statements[0][0], $identifiers);
        foreach ($identifiers[1] as $identifier) {
            $this->assertLessThanOrEqual(64, \strlen($identifier), 'MariaDB identifiers have at most 64 characters: ' . $identifier);
        }

        // the key of the removed field is stripped from every translation that has it
        $this->assertSame([
            self::EXPRESS . '/' . Defaults::LANGUAGE_SYSTEM => ['shopbite_fulfillment' => 'delivery'],
            self::EXPRESS . '/' . self::GERMAN => null,
            self::MINUTES . '/' . Defaults::LANGUAGE_SYSTEM => ['other' => 1],
        ], $this->customFieldUpdates());

        $this->assertDeleted('DELETE FROM custom_field', ['name' => 'shopbite_lead_time_minutes']);
        $this->assertDeleted('DELETE FROM system_config', ['key' => 'ShopBitePlugin.config.defaultDeliveryTime']);
    }

    public function testASecondRunChangesNothing(): void
    {
        $this->migrate(
            methods: [
                ['id' => self::DELIVERY, 'delivery_time_id' => self::MINUTES_25, 'unit' => 'minute'],
            ],
            translations: [
                ['id' => self::DELIVERY, 'language_id' => Defaults::LANGUAGE_SYSTEM, 'custom_fields' => '{"shopbite_fulfillment": "delivery"}'],
            ],
            settings: [],
        );

        $this->assertSame([], $this->createdDeliveryTimes());
        $this->assertSame([], $this->assignedDeliveryTimes());
        $this->assertSame([], $this->remembered());
        $this->assertSame([], $this->customFieldUpdates());
    }

    public function testMethodWithoutDeliveryTimeGetsOne(): void
    {
        $this->migrate(
            methods: [['id' => self::DELIVERY, 'delivery_time_id' => null, 'unit' => null]],
            translations: [],
            settings: [],
        );

        $this->assertSame([30], array_column($this->createdDeliveryTimes(), 'max'));
        $this->assertArrayHasKey(self::DELIVERY, $this->assignedDeliveryTimes());
    }

    /**
     * @param list<array{id: string, delivery_time_id: string|null, unit: string|null}>  $methods
     * @param list<array{id: string, language_id: string, custom_fields: string|null}>   $translations
     * @param list<array{sales_channel_id: string|null, value: string}>|null              $settings
     */
    private function migrate(array $methods, array $translations, ?array $settings = null): void
    {
        $settings ??= [
            ['sales_channel_id' => null, 'value' => '{"_value": 40}'],
            ['sales_channel_id' => self::CHANNEL, 'value' => '{"_value": "25"}'],
            ['sales_channel_id' => self::OTHER_CHANNEL, 'value' => '{"_value": 0}'],
        ];
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(static fn (string $sql): array => match (true) {
            str_contains($sql, 'FROM system_config') => $settings,
            str_contains($sql, 'FROM sales_channel_shipping_method') => [
                ['shipping_method_id' => self::DELIVERY, 'sales_channel_id' => self::CHANNEL],
                ['shipping_method_id' => self::DELIVERY, 'sales_channel_id' => self::THIRD_CHANNEL],
                ['shipping_method_id' => self::PICKUP, 'sales_channel_id' => self::OTHER_CHANNEL],
                ['shipping_method_id' => self::EXPRESS, 'sales_channel_id' => self::CHANNEL],
            ],
            str_contains($sql, 'FROM shipping_method_translation') => $translations,
            str_contains($sql, 'FROM shipping_method') => $methods,
            str_contains($sql, 'FROM delivery_time') => [
                ['id' => self::MINUTES_RANGE, 'min' => 10, 'max' => 25],
                ['id' => self::MINUTES_25, 'min' => '25', 'max' => '25'],
            ],
            str_contains($sql, 'FROM language') => [
                ['id' => Defaults::LANGUAGE_SYSTEM, 'locale' => 'en-GB'],
                ['id' => self::GERMAN, 'locale' => 'de-DE'],
            ],
            default => throw new \LogicException($sql),
        });
        $connection->method('executeStatement')->willReturnCallback(function (string $sql, array $params = []): int {
            $this->statements[] = [$sql, $params];

            return 1;
        });

        new Migration1795651200ShippingMethodDeliveryTime()->update($connection);
    }

    /**
     * @return list<array{id: string, unit: mixed, min: mixed, max: mixed}>
     */
    private function createdDeliveryTimes(): array
    {
        $created = [];
        foreach ($this->statements as [$sql, $params]) {
            if (str_starts_with($sql, 'INSERT INTO delivery_time ')) {
                $created[] = ['id' => Uuid::fromBytesToHex((string) $params['id']), 'unit' => $params['unit'], 'min' => $params['min'], 'max' => $params['max']];
            }
        }

        return $created;
    }

    /**
     * @param array<string, string> $deliveryTimeByMethod
     *
     * @return array<string, mixed> name by method/language
     */
    private function createdNames(array $deliveryTimeByMethod): array
    {
        $methodByDeliveryTime = array_flip($deliveryTimeByMethod);
        $names = [];
        foreach ($this->statements as [$sql, $params]) {
            if (str_starts_with($sql, 'INSERT INTO delivery_time_translation')) {
                $method = $methodByDeliveryTime[Uuid::fromBytesToHex((string) $params['id'])];
                $names[$method . '/' . Uuid::fromBytesToHex((string) $params['language'])] = $params['name'];
            }
        }

        return $names;
    }

    /**
     * @return array<string, string>
     */
    private function assignedDeliveryTimes(): array
    {
        $assigned = [];
        foreach ($this->statements as [$sql, $params]) {
            if (str_starts_with($sql, 'UPDATE shipping_method SET delivery_time_id')) {
                $assigned[Uuid::fromBytesToHex((string) $params['id'])] = Uuid::fromBytesToHex((string) $params['deliveryTime']);
            }
        }

        return $assigned;
    }

    /**
     * @return array<string, array{string|null, string}>
     */
    private function remembered(): array
    {
        $remembered = [];
        foreach ($this->statements as [$sql, $params]) {
            if (str_contains($sql, 'INSERT INTO shopbite_shipping_method_delivery_time')) {
                $remembered[Uuid::fromBytesToHex((string) $params['method'])] = [
                    $params['previous'] === null ? null : Uuid::fromBytesToHex((string) $params['previous']),
                    Uuid::fromBytesToHex((string) $params['minute']),
                ];
            }
        }

        return $remembered;
    }

    /**
     * @return array<string, mixed>
     */
    private function customFieldUpdates(): array
    {
        $updates = [];
        foreach ($this->statements as [$sql, $params]) {
            if (str_starts_with($sql, 'UPDATE shipping_method_translation')) {
                $updates[Uuid::fromBytesToHex((string) $params['id']) . '/' . Uuid::fromBytesToHex((string) $params['language'])]
                    = $params['customFields'] === null ? null : json_decode((string) $params['customFields'], true);
            }
        }

        return $updates;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function assertDeleted(string $statement, array $params): void
    {
        foreach ($this->statements as [$sql, $actual]) {
            if (str_starts_with($sql, $statement)) {
                $this->assertSame($params, $actual);

                return;
            }
        }
        $this->fail($statement . ' was not executed');
    }
}
