<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Fulfillment;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ShopBite\Fulfillment\MinuteDeliveryTimes;
use ShopBite\Fulfillment\MinuteDeliveryTimesException;
use ShopBite\Fulfillment\ShippingMethodFulfillment;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

#[CoversClass(MinuteDeliveryTimes::class)]
#[CoversClass(MinuteDeliveryTimesException::class)]
#[UsesClass(ShippingMethodFulfillment::class)]
class MinuteDeliveryTimesTest extends TestCase
{
    private const string GERMAN = '0190000000007000800000000000e001';

    private const string METHOD = '0190000000007000800000000000d001';

    private const string PREVIOUS = '0190000000007000800000000000f001';

    private const string MINUTES = '0190000000007000800000000000f002';

    /** @var list<array{string, array<string, mixed>}> */
    private array $statements = [];

    /** @var array<string, list<array<string, mixed>>> rows by a part of the query */
    private array $rows = [];

    private bool $table = true;

    private ?\Closure $onQuery = null;

    public function testLocalesOfAllLanguagesWithTheSystemLanguage(): void
    {
        $this->rows['FROM language'] = [['id' => self::GERMAN, 'locale' => 'de-DE']];

        $this->assertSame([Defaults::LANGUAGE_SYSTEM => 'en-GB', self::GERMAN => 'de-DE'], $this->service()->locales());
    }

    public function testExistingAreTheOldestExactMinuteDeliveryTimes(): void
    {
        $this->rows['FROM delivery_time'] = [
            ['id' => 'a', 'min' => '10', 'max' => '20'],
            ['id' => 'b', 'min' => '20', 'max' => '20'],
            ['id' => 'c', 'min' => 20, 'max' => 20],
            ['id' => 'd', 'min' => 15, 'max' => 15],
        ];

        $this->assertSame([20 => 'b', 15 => 'd'], $this->service()->existing());
    }

    public function testCreateInsertsADeliveryTimeWithANameInEveryLanguage(): void
    {
        $id = $this->service()->create(20, [Defaults::LANGUAGE_SYSTEM => 'en-GB', self::GERMAN => 'de-DE']);

        $this->assertTrue(Uuid::isValid($id));
        [$sql, $params] = $this->statements[0];
        $this->assertStringStartsWith('INSERT INTO delivery_time ', $sql);
        $this->assertSame([$id, 20, 20, 'minute'], [Uuid::fromBytesToHex((string) $params['id']), $params['min'], $params['max'], $params['unit']]);
        $names = [];
        foreach (\array_slice($this->statements, 1) as [$sql, $params]) {
            $this->assertStringStartsWith('INSERT INTO delivery_time_translation', $sql);
            $names[Uuid::fromBytesToHex((string) $params['language'])] = $params['name'];
        }
        $this->assertSame([Defaults::LANGUAGE_SYSTEM => '20 minutes', self::GERMAN => '20 Minuten'], $names);
    }

    public function testRememberKeepsThePreviousCoreDeliveryTime(): void
    {
        $this->service()->remember(self::METHOD, self::PREVIOUS, self::MINUTES);
        $this->service()->remember(self::METHOD, null, self::MINUTES);

        [$sql, $params] = $this->statements[0];
        $this->assertStringContainsString('INSERT INTO shopbite_shipping_method_delivery_time', $sql);
        // a method that was already on a minute delivery time keeps the recorded previous one
        $this->assertStringContainsString('COALESCE(VALUES(previous_delivery_time_id), previous_delivery_time_id)', $sql);
        $this->assertSame(self::PREVIOUS, Uuid::fromBytesToHex((string) $params['previous']));
        $this->assertSame(self::MINUTES, Uuid::fromBytesToHex((string) $params['minute']));
        $this->assertNull($this->statements[1][1]['previous']);
    }

    public function testDeactivateKeepsTheCurrentMinuteDeliveryTimeAndRestoresThePreviousOne(): void
    {
        $this->service()->deactivate();

        // first the current minute delivery time (also one changed in the Admin) is remembered for
        // activate(), then the previous one is put back: two statements, no assignment order risk
        $this->assertCount(2, $this->statements);
        [$remember, $restore] = $this->statements;
        $this->assertStringStartsWith('UPDATE shopbite_shipping_method_delivery_time', ltrim($remember[0]));
        $this->assertStringContainsString('SET remembered.minute_delivery_time_id = shipping_method.delivery_time_id', $remember[0]);
        $this->assertStringContainsString("delivery_time.unit = 'minute'", $remember[0]);
        $this->assertStringContainsString('SET shipping_method.delivery_time_id = remembered.previous_delivery_time_id', $restore[0]);
        $this->assertStringContainsString('shipping_method.updated_at = :now', $restore[0]);
        $this->assertStringContainsString("delivery_time.unit = 'minute'", $restore[0]);
    }

    public function testDeactivateIsRefusedWhileOtherShippingMethodsOrProductsUseMinutes(): void
    {
        $this->rows['FROM shipping_method'] = [['name' => 'Kurier', 'total' => 1]];
        $this->rows['FROM product'] = [['name' => 'P1', 'total' => 23]];

        try {
            $this->service()->deactivate();
            $this->fail('deactivate must be refused');
        } catch (MinuteDeliveryTimesException $exception) {
            $this->assertStringStartsWith('ShopBite cannot be deactivated', $exception->getMessage());
            $this->assertStringContainsString('shipping method "Kurier"', $exception->getMessage());
            $this->assertStringContainsString('product "P1", 22 more products', $exception->getMessage());
            $this->assertStringContainsString('unit hour, day, week, month or year', $exception->getMessage());
        }
        $this->assertSame([], $this->statements, 'nothing is changed when refused');
    }

    public function testUninstallCheckNamesTheUninstall(): void
    {
        $this->rows['FROM product'] = [['name' => 'P1', 'total' => 1]];

        $this->expectException(MinuteDeliveryTimesException::class);
        $this->expectExceptionMessage('ShopBite cannot be uninstalled');

        $this->service()->assertNotInUse('uninstalled');
    }

    public function testWithoutTheTableEveryMinuteShippingMethodBlocksAndNothingIsRestored(): void
    {
        $this->table = false;
        $queries = [];
        $this->rows['FROM shipping_method'] = [];
        $this->onQuery = static function (string $sql) use (&$queries): void {
            $queries[] = $sql;
        };

        $this->service()->deactivate();
        $this->service()->activate();

        $this->assertSame([], $this->statements);
        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('shopbite_shipping_method_delivery_time', $sql);
        }
    }

    public function testActivateReassignsTheMinuteDeliveryTimes(): void
    {
        $this->service()->activate();

        $this->assertCount(1, $this->statements);
        $this->assertStringContainsString('SET shipping_method.delivery_time_id = remembered.minute_delivery_time_id', $this->statements[0][0]);
        $this->assertStringContainsString('shipping_method.updated_at = :now', $this->statements[0][0]);
        $this->assertStringContainsString('shipping_method.delivery_time_id = remembered.previous_delivery_time_id', $this->statements[0][0]);
    }

    public function testDropTable(): void
    {
        $this->service()->dropTable();

        $this->assertSame('DROP TABLE IF EXISTS shopbite_shipping_method_delivery_time', $this->statements[0][0]);
    }

    private function service(): MinuteDeliveryTimes
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(fn (string $sql): mixed => str_contains($sql, 'SHOW TABLES') && $this->table ? 'shopbite_shipping_method_delivery_time' : false);
        $connection->method('fetchAllAssociative')->willReturnCallback(function (string $sql): array {
            if ($this->onQuery !== null) {
                ($this->onQuery)($sql);
            }
            foreach ($this->rows as $part => $rows) {
                if (str_contains($sql, $part)) {
                    return $rows;
                }
            }

            return [];
        });
        $connection->method('executeStatement')->willReturnCallback(function (string $sql, array $params = []): int {
            $this->statements[] = [$sql, $params];

            return 1;
        });

        return new MinuteDeliveryTimes($connection);
    }
}
