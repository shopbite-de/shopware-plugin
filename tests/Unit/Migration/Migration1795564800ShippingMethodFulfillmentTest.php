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
        $fields = Migration1795564800ShippingMethodFulfillment::backfill(null, $technicalName, $names);

        $this->assertSame($expected, $fields['shopbite_fulfillment']);
    }

    public function testOnlyAnEmptyOrInvalidFulfillmentIsFilled(): void
    {
        $this->assertSame(
            ['other' => 'x', 'shopbite_fulfillment' => 'delivery'],
            Migration1795564800ShippingMethodFulfillment::backfill(['other' => 'x', 'shopbite_fulfillment' => 'delivery'], 'shipping_pickup', ['Abholung']),
        );
        $this->assertSame(
            ['shopbite_fulfillment' => 'pickup'],
            Migration1795564800ShippingMethodFulfillment::backfill(['shopbite_fulfillment' => 'drone'], 'shipping_pickup', []),
        );
    }

    public function testUpdateWritesTheFulfillmentIntoEveryTranslation(): void
    {
        $german = '0190000000007000800000000000e001';
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(static fn (string $sql): array => match (true) {
            str_contains($sql, 'shipping_method_translation') => [
                ['id' => self::DELIVERY, 'language_id' => Defaults::LANGUAGE_SYSTEM, 'name' => 'Lieferung', 'custom_fields' => null],
                ['id' => self::PICKUP, 'language_id' => Defaults::LANGUAGE_SYSTEM, 'name' => 'Standard', 'custom_fields' => '{"shopbite_lead_time_minutes": 15}'],
                ['id' => self::PICKUP, 'language_id' => $german, 'name' => 'Abholung', 'custom_fields' => '{"other": 1}'],
            ],
            str_contains($sql, 'FROM shipping_method') => [
                ['id' => self::DELIVERY, 'technical_name' => 'shipping_standard'],
                ['id' => self::PICKUP, 'technical_name' => 'shipping_standard'],
            ],
            default => throw new \LogicException($sql),
        });

        $updates = [];
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql, array $params) use (&$updates): int {
            // the lead time and the former setting are left to Migration1795651200ShippingMethodDeliveryTime
            static::assertStringContainsString('UPDATE shipping_method_translation', $sql);
            $updates[Uuid::fromBytesToHex((string) $params['id']) . '/' . Uuid::fromBytesToHex((string) $params['language'])] = json_decode((string) $params['customFields'], true);

            return 1;
        });

        new Migration1795564800ShippingMethodFulfillment()->update($connection);

        $this->assertSame([
            self::DELIVERY . '/' . Defaults::LANGUAGE_SYSTEM => ['shopbite_fulfillment' => 'delivery'],
            self::PICKUP . '/' . Defaults::LANGUAGE_SYSTEM => ['shopbite_lead_time_minutes' => 15, 'shopbite_fulfillment' => 'pickup'],
            // other languages get the same value, so they cannot shadow the system language
            self::PICKUP . '/' . $german => ['other' => 1, 'shopbite_fulfillment' => 'pickup'],
        ], $updates);
    }

    public function testUpdateLeavesCompleteMethodsAlone(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(static fn (string $sql): array => match (true) {
            str_contains($sql, 'shipping_method_translation') => [
                ['id' => self::DELIVERY, 'language_id' => Defaults::LANGUAGE_SYSTEM, 'name' => 'Abholung', 'custom_fields' => '{"shopbite_fulfillment": "delivery"}'],
                ['id' => self::DELIVERY, 'language_id' => '0190000000007000800000000000e001', 'name' => 'Abholung', 'custom_fields' => '{"shopbite_fulfillment": "delivery"}'],
            ],
            str_contains($sql, 'FROM shipping_method') => [
                ['id' => self::DELIVERY, 'technical_name' => 'shipping_pickup'],
            ],
            default => [],
        });
        $connection->expects($this->never())->method('executeStatement');

        new Migration1795564800ShippingMethodFulfillment()->update($connection);
    }
}
