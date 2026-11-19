<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Migration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ShopBite\Fulfillment\ShippingMethodFulfillment;
use ShopBite\Migration\Migration1795564800ShippingMethodFulfillment;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

#[CoversClass(Migration1795564800ShippingMethodFulfillment::class)]
#[UsesClass(ShippingMethodFulfillment::class)]
class Migration1795564800ShippingMethodFulfillmentTest extends TestCase
{
    private const string CHANNEL = '0190000000007000800000000000b001';

    private const string OTHER_CHANNEL = '0190000000007000800000000000b002';

    private const string DELIVERY = '0190000000007000800000000000d001';

    private const string PICKUP = '0190000000007000800000000000d002';

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function names(): iterable
    {
        yield 'technical name pickup' => ['shipping_pickup', ['Standard'], 'pickup'];
        yield 'name Abholung' => ['shipping_standard', ['Abholung'], 'pickup'];
        yield 'name Selbstabholung, any case' => ['shipping_standard', ['SELBSTABHOLUNG'], 'pickup'];
        yield 'English translation Pickup' => ['shipping_standard', ['Lieferung', 'Pickup'], 'pickup'];
        yield 'delivery' => ['shipping_standard', ['Lieferung', 'Delivery'], 'delivery'];
        yield 'Pick-up is no match' => ['shipping_standard', ['Pick-up'], 'delivery'];
    }

    /**
     * @param list<string> $names
     */
    #[DataProvider('names')]
    public function testFulfillmentIsGuessedFromTheNames(string $technicalName, array $names, string $expected): void
    {
        $fields = Migration1795564800ShippingMethodFulfillment::backfill(null, $technicalName, $names, 30);

        $this->assertSame($expected, $fields['shopbite_fulfillment']);
    }

    public function testOnlyEmptyOrInvalidFieldsAreFilled(): void
    {
        $this->assertSame(
            ['other' => 'x', 'shopbite_fulfillment' => 'delivery', 'shopbite_lead_time_minutes' => 20],
            Migration1795564800ShippingMethodFulfillment::backfill(['other' => 'x', 'shopbite_fulfillment' => 'delivery', 'shopbite_lead_time_minutes' => 20], 'shipping_pickup', ['Abholung'], 45),
        );
        $this->assertSame(
            ['shopbite_fulfillment' => 'pickup', 'shopbite_lead_time_minutes' => 45],
            Migration1795564800ShippingMethodFulfillment::backfill(['shopbite_fulfillment' => 'drone', 'shopbite_lead_time_minutes' => 0], 'shipping_pickup', [], 45),
        );
    }

    public function testMinutesComeFromTheDeliveryTimeOfTheMethodsChannels(): void
    {
        $values = [self::CHANNEL => 45, self::OTHER_CHANNEL => 50];

        $this->assertSame(45, Migration1795564800ShippingMethodFulfillment::minutes([self::CHANNEL], $values, 40));
        $this->assertSame(40, Migration1795564800ShippingMethodFulfillment::minutes([self::CHANNEL, self::OTHER_CHANNEL], $values, 40), 'differing channels: the global value');
        $this->assertSame(40, Migration1795564800ShippingMethodFulfillment::minutes(['0190000000007000800000000000b003'], $values, 40), 'channel without own value: the global value');
        $this->assertSame(45, Migration1795564800ShippingMethodFulfillment::minutes([self::CHANNEL, '0190000000007000800000000000b003'], $values, 40), 'channels without own value do not count');
        $this->assertSame(45, Migration1795564800ShippingMethodFulfillment::minutes([self::CHANNEL, '0190000000007000800000000000b003'], [self::CHANNEL => 45, self::OTHER_CHANNEL => 50], null));
        $this->assertSame(40, Migration1795564800ShippingMethodFulfillment::minutes([], $values, 40));
        $this->assertSame(30, Migration1795564800ShippingMethodFulfillment::minutes([], [], null), 'nothing configured: 30');
    }

    public function testUpdateWritesEveryTranslationAndDropsTheOldSetting(): void
    {
        $german = '0190000000007000800000000000e001';
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(static fn (string $sql): array => match (true) {
            str_contains($sql, 'system_config') => [
                ['sales_channel_id' => null, 'value' => '{"_value": 40}'],
                ['sales_channel_id' => self::CHANNEL, 'value' => '{"_value": 25}'],
            ],
            str_contains($sql, 'sales_channel_shipping_method') => [
                ['shipping_method_id' => self::DELIVERY, 'sales_channel_id' => self::CHANNEL],
                ['shipping_method_id' => self::DELIVERY, 'sales_channel_id' => '0190000000007000800000000000b003'],
                ['shipping_method_id' => self::PICKUP, 'sales_channel_id' => self::OTHER_CHANNEL],
            ],
            str_contains($sql, 'shipping_method_translation') => [
                ['id' => self::DELIVERY, 'language_id' => Defaults::LANGUAGE_SYSTEM, 'name' => 'Lieferung', 'custom_fields' => null],
                ['id' => self::PICKUP, 'language_id' => Defaults::LANGUAGE_SYSTEM, 'name' => 'Standard', 'custom_fields' => '{"shopbite_lead_time_minutes": 15}'],
                ['id' => self::PICKUP, 'language_id' => $german, 'name' => 'Abholung', 'custom_fields' => '{"other": 1, "shopbite_lead_time_minutes": 99}'],
            ],
            str_contains($sql, 'FROM shipping_method') => [
                ['id' => self::DELIVERY, 'technical_name' => 'shipping_standard'],
                ['id' => self::PICKUP, 'technical_name' => 'shipping_standard'],
            ],
            default => throw new \LogicException($sql),
        });

        $updates = [];
        $deleted = false;
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql, array $params) use (&$updates, &$deleted): int {
            if (str_contains($sql, 'DELETE FROM system_config')) {
                static::assertSame(['key' => 'ShopBitePlugin.config.defaultDeliveryTime'], $params);
                $deleted = true;

                return 1;
            }
            static::assertStringContainsString('UPDATE shipping_method_translation', $sql);
            $updates[Uuid::fromBytesToHex((string) $params['id']) . '/' . Uuid::fromBytesToHex((string) $params['language'])] = json_decode((string) $params['customFields'], true);

            return 1;
        });

        new Migration1795564800ShippingMethodFulfillment()->update($connection);

        $this->assertSame([
            // the shop channel's own value; the second channel has none and does not count
            self::DELIVERY . '/' . Defaults::LANGUAGE_SYSTEM => ['shopbite_fulfillment' => 'delivery', 'shopbite_lead_time_minutes' => 25],
            self::PICKUP . '/' . Defaults::LANGUAGE_SYSTEM => ['shopbite_lead_time_minutes' => 15, 'shopbite_fulfillment' => 'pickup'],
            // other languages get the same values, so they cannot shadow the system language
            self::PICKUP . '/' . $german => ['other' => 1, 'shopbite_lead_time_minutes' => 15, 'shopbite_fulfillment' => 'pickup'],
        ], $updates);
        $this->assertTrue($deleted);
    }

    public function testUpdateLeavesCompleteMethodsAlone(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(static fn (string $sql): array => match (true) {
            str_contains($sql, 'shipping_method_translation') => [
                ['id' => self::DELIVERY, 'language_id' => Defaults::LANGUAGE_SYSTEM, 'name' => 'Abholung', 'custom_fields' => '{"shopbite_fulfillment": "delivery", "shopbite_lead_time_minutes": 50}'],
                ['id' => self::DELIVERY, 'language_id' => '0190000000007000800000000000e001', 'name' => 'Abholung', 'custom_fields' => '{"shopbite_lead_time_minutes": 50, "shopbite_fulfillment": "delivery"}'],
            ],
            str_contains($sql, 'FROM shipping_method') && !str_contains($sql, 'sales_channel') => [
                ['id' => self::DELIVERY, 'technical_name' => 'shipping_pickup'],
            ],
            default => [],
        });
        $connection->expects($this->once())->method('executeStatement')->with($this->stringContains('DELETE FROM system_config'));

        new Migration1795564800ShippingMethodFulfillment()->update($connection);
    }
}
