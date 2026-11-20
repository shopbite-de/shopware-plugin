<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Fulfillment;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ShopBite\Fulfillment\MinuteDeliveryTimes;
use ShopBite\Fulfillment\ShippingMethodFulfillment;
use ShopBite\Fulfillment\ShippingMethodLeadTimes;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\DeliveryTime\DeliveryTimeCollection;
use Shopware\Core\System\DeliveryTime\DeliveryTimeEntity;

#[CoversClass(ShippingMethodLeadTimes::class)]
#[UsesClass(ShippingMethodFulfillment::class)]
#[UsesClass(MinuteDeliveryTimes::class)]
#[AllowMockObjectsWithoutExpectations]
class ShippingMethodLeadTimesTest extends TestCase
{
    public const string CHANNEL = '0190000000007000800000000000b001';

    public const string OTHER_CHANNEL = '0190000000007000800000000000b002';

    public const string DELIVERY = '0190000000007000800000000000d001';

    public const string PICKUP = '0190000000007000800000000000d002';

    /** @var EntityRepository<ShippingMethodCollection>&MockObject */
    private EntityRepository&MockObject $repository;

    /** @var EntityRepository<DeliveryTimeCollection>&MockObject */
    private EntityRepository&MockObject $deliveryTimes;

    private Connection&MockObject $connection;

    private ?Criteria $criteria = null;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(EntityRepository::class);
        $this->repository->method('search')->willReturnCallback(function (Criteria $criteria): EntitySearchResult {
            $this->criteria = $criteria;

            return self::searchResult(self::methods());
        });
        $this->deliveryTimes = $this->createMock(EntityRepository::class);
        $this->connection = $this->createMock(Connection::class);
    }

    public function testStorefrontGetsTheActiveMethodsOfTheChannel(): void
    {
        $methods = $this->service()->storefront(self::CHANNEL, Context::createDefaultContext());

        $this->assertSame([
            ['id' => self::DELIVERY, 'fulfillment' => 'delivery', 'leadTimeMinutes' => 45],
            ['id' => self::PICKUP, 'fulfillment' => 'pickup', 'leadTimeMinutes' => 30],
        ], $methods);
        $this->assertFiltersActiveMethodsOfTheChannel();
        $this->assertTrue($this->criteria?->hasAssociation('deliveryTime'));
    }

    public function testBackofficeGetsNamesAndWhetherTheLeadTimeIsSet(): void
    {
        $this->connection->expects($this->never())->method('fetchAllAssociative');

        $methods = $this->service()->backoffice(self::CHANNEL, Context::createDefaultContext(), null);

        $this->assertSame([
            ['id' => self::DELIVERY, 'name' => 'Lieferung', 'fulfillment' => 'delivery', 'leadTimeMinutes' => 45, 'leadTimeMinutesSet' => true, 'editable' => true],
            ['id' => self::PICKUP, 'name' => 'Abholung', 'fulfillment' => 'pickup', 'leadTimeMinutes' => 30, 'leadTimeMinutesSet' => false, 'editable' => true],
        ], $methods);
        $this->assertFiltersActiveMethodsOfTheChannel();
    }

    public function testBoundUserMayEditOnlyMethodsUsedByTheirBranchesAlone(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([
            ['shipping_method_id' => self::DELIVERY, 'sales_channel_id' => self::CHANNEL],
            ['shipping_method_id' => self::DELIVERY, 'sales_channel_id' => self::OTHER_CHANNEL],
            ['shipping_method_id' => self::PICKUP, 'sales_channel_id' => self::CHANNEL],
        ]);

        $methods = $this->service()->backoffice(self::CHANNEL, Context::createDefaultContext(), [self::CHANNEL]);
        $this->assertSame([false, true], array_column($methods, 'editable'));

        $methods = $this->service()->backoffice(self::CHANNEL, Context::createDefaultContext(), [self::CHANNEL, self::OTHER_CHANNEL]);
        $this->assertSame([true, true], array_column($methods, 'editable'));
    }

    public function testSalesChannelsOfShippingMethods(): void
    {
        $this->connection->expects($this->once())->method('fetchAllAssociative')
            ->with(
                $this->stringContains('sales_channel_shipping_method'),
                ['ids' => [Uuid::fromHexToBytes(self::DELIVERY), Uuid::fromHexToBytes(self::PICKUP)]],
                ['ids' => \Doctrine\DBAL\ArrayParameterType::BINARY],
            )
            ->willReturn([
                ['shipping_method_id' => self::DELIVERY, 'sales_channel_id' => self::CHANNEL],
                ['shipping_method_id' => self::DELIVERY, 'sales_channel_id' => self::OTHER_CHANNEL],
                ['shipping_method_id' => self::PICKUP, 'sales_channel_id' => self::CHANNEL],
            ]);

        $this->assertSame(
            [self::DELIVERY => [self::CHANNEL, self::OTHER_CHANNEL], self::PICKUP => [self::CHANNEL]],
            $this->service()->salesChannelIds([self::DELIVERY, self::PICKUP]),
        );
    }

    public function testNoShippingMethodsNeedNoQuery(): void
    {
        $this->connection->expects($this->never())->method('fetchAllAssociative');

        $this->assertSame([], $this->service()->salesChannelIds([]));
    }

    public function testLeadTimeOfALoadedDeliveryTimeNeedsNoQuery(): void
    {
        $this->deliveryTimes->expects($this->never())->method('search');
        $method = self::methods()->get(self::DELIVERY);
        $this->assertNotNull($method);

        $this->assertSame(45, $this->service()->leadTimeMinutes($method, Context::createDefaultContext()));
    }

    public function testLeadTimeLoadsTheDeliveryTimeOfTheMethod(): void
    {
        // The sales channel context loads its shipping method without the delivery time.
        $method = new ShippingMethodEntity();
        $method->setId(self::DELIVERY);
        $method->setDeliveryTimeId('0190000000007000800000000000f001');
        $this->deliveryTimes->expects($this->once())->method('search')
            ->with($this->callback(static fn (Criteria $criteria): bool => $criteria->getIds() === ['0190000000007000800000000000f001']))
            ->willReturn(self::deliveryTimeResult([ShippingMethodFulfillmentTest::deliveryTime('minute', 20, 25)]));

        $this->assertSame(25, $this->service()->leadTimeMinutes($method, Context::createDefaultContext()));
    }

    public function testLeadTimeWithoutDeliveryTimeIs30Minutes(): void
    {
        $method = new ShippingMethodEntity();
        $method->setId(self::DELIVERY);
        $method->setDeliveryTimeId('0190000000007000800000000000f001');
        $this->deliveryTimes->method('search')->willReturn(self::deliveryTimeResult([]));

        $this->assertSame(30, $this->service()->leadTimeMinutes($method, Context::createDefaultContext()));
    }

    public function testWriteReusesAnExactMinuteDeliveryTimeAndCreatesTheMissingOnes(): void
    {
        $german = '0190000000007000800000000000e001';
        $existing = '0190000000007000800000000000f050';
        $hour = '0190000000007000800000000000f060';
        $third = '0190000000007000800000000000d003';
        $statements = [];
        $this->connection->method('fetchAllAssociative')->willReturnCallback(static fn (string $sql): array => match (true) {
            str_contains($sql, 'FROM language') => [['id' => $german, 'locale' => 'de-DE']],
            // a range does not count
            str_contains($sql, 'FROM delivery_time WHERE unit') => [
                ['id' => '0190000000007000800000000000f015', 'min' => 10, 'max' => 15],
                ['id' => $existing, 'min' => 50, 'max' => 50],
            ],
            str_contains($sql, 'FROM shipping_method LEFT JOIN delivery_time') => [
                ['id' => self::DELIVERY, 'delivery_time_id' => $hour, 'unit' => 'hour'],
                ['id' => self::PICKUP, 'delivery_time_id' => $existing, 'unit' => 'minute'],
                ['id' => $third, 'delivery_time_id' => $hour, 'unit' => 'hour'],
            ],
            default => throw new \LogicException($sql),
        });
        $this->connection->method('executeStatement')->willReturnCallback(static function (string $sql, array $params = []) use (&$statements): int {
            $statements[] = [$sql, $params];

            return 1;
        });
        // existing delivery times are never changed: they may be shared with other methods
        $this->deliveryTimes->expects($this->never())->method('update');
        $this->deliveryTimes->expects($this->never())->method('upsert');
        $updates = [];
        $this->repository->expects($this->once())->method('update')
            ->willReturnCallback(function (array $data, Context $context) use (&$updates) {
                static::assertInstanceOf(SystemSource::class, $context->getSource());
                $updates = $data;

                return $this->createStub(\Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent::class);
            });

        $this->service()->write([self::DELIVERY => 50, self::PICKUP => 15, $third => 15]);

        $created = array_values(array_filter($statements, static fn (array $statement): bool => str_starts_with($statement[0], 'INSERT INTO delivery_time ')));
        $this->assertCount(1, $created, 'one new delivery time for both methods with 15 minutes');
        $id = Uuid::fromBytesToHex((string) $created[0][1]['id']);
        $this->assertSame([15, 15, 'minute'], [$created[0][1]['min'], $created[0][1]['max'], $created[0][1]['unit']]);
        $names = [];
        $remembered = [];
        foreach ($statements as [$sql, $params]) {
            if (str_starts_with($sql, 'INSERT INTO delivery_time_translation')) {
                $names[Uuid::fromBytesToHex((string) $params['language'])] = $params['name'];
            }
            if (str_contains($sql, 'INSERT INTO shopbite_shipping_method_delivery_time')) {
                $remembered[Uuid::fromBytesToHex((string) $params['method'])] = [
                    $params['previous'] === null ? null : Uuid::fromBytesToHex((string) $params['previous']),
                    Uuid::fromBytesToHex((string) $params['minute']),
                ];
            }
        }
        $this->assertSame([Defaults::LANGUAGE_SYSTEM => '15 minutes', $german => '15 Minuten'], $names);
        $this->assertSame([
            ['id' => self::DELIVERY, 'deliveryTimeId' => $existing],
            ['id' => self::PICKUP, 'deliveryTimeId' => $id],
            ['id' => $third, 'deliveryTimeId' => $id],
        ], $updates);
        // the core delivery time before is kept for deactivating the plugin; none for a minute one
        $this->assertSame([
            self::DELIVERY => [$hour, $existing],
            self::PICKUP => [null, $id],
            $third => [$hour, $id],
        ], $remembered);
    }

    public function testWriteWithoutMissingDeliveryTimesCreatesNone(): void
    {
        $existing = '0190000000007000800000000000f020';
        $this->connection->method('fetchAllAssociative')->willReturnCallback(static fn (string $sql): array => str_contains($sql, 'FROM delivery_time WHERE unit')
            ? [['id' => $existing, 'min' => 20, 'max' => 20]]
            : []);
        $this->connection->expects($this->once())->method('executeStatement')
            ->with($this->stringContains('INSERT INTO shopbite_shipping_method_delivery_time'));
        $this->repository->expects($this->once())->method('update')
            ->with([['id' => self::DELIVERY, 'deliveryTimeId' => $existing]]);

        $this->service()->write([self::DELIVERY => 20]);
    }

    public function testEmptyWriteDoesNothing(): void
    {
        $this->connection->expects($this->never())->method('fetchAllAssociative');
        $this->repository->expects($this->never())->method('update');

        $this->service()->write([]);
    }

    private function assertFiltersActiveMethodsOfTheChannel(): void
    {
        $this->assertNotNull($this->criteria);
        $filters = [];
        foreach ($this->criteria->getFilters() as $filter) {
            if ($filter instanceof EqualsFilter) {
                $filters[$filter->getField()] = $filter->getValue();
            }
        }
        $this->assertSame(['salesChannels.id' => self::CHANNEL, 'active' => true], $filters);
    }

    private function service(): ShippingMethodLeadTimes
    {
        return new ShippingMethodLeadTimes($this->repository, $this->deliveryTimes, $this->connection, new MinuteDeliveryTimes($this->connection));
    }

    public static function methods(): ShippingMethodCollection
    {
        $delivery = new ShippingMethodEntity();
        $delivery->setId(self::DELIVERY);
        $delivery->setTechnicalName('shipping_standard');
        $delivery->setName('Lieferung');
        $delivery->setCustomFields(['shopbite_fulfillment' => 'delivery']);
        $delivery->setDeliveryTime(ShippingMethodFulfillmentTest::deliveryTime('minute', 30, 45));

        $pickup = new ShippingMethodEntity();
        $pickup->setId(self::PICKUP);
        $pickup->setTechnicalName('shipping_pickup');
        $pickup->setName('Abholung');
        $pickup->setCustomFields(['shopbite_fulfillment' => 'pickup']);

        return new ShippingMethodCollection([$delivery, $pickup]);
    }

    /**
     * @return EntitySearchResult<ShippingMethodCollection>
     */
    public static function searchResult(ShippingMethodCollection $methods): EntitySearchResult
    {
        return new EntitySearchResult('shipping_method', $methods->count(), $methods, null, new Criteria(), Context::createDefaultContext());
    }

    /**
     * @param list<DeliveryTimeEntity> $deliveryTimes
     *
     * @return EntitySearchResult<DeliveryTimeCollection>
     */
    public static function deliveryTimeResult(array $deliveryTimes): EntitySearchResult
    {
        return new EntitySearchResult('delivery_time', \count($deliveryTimes), new DeliveryTimeCollection($deliveryTimes), null, new Criteria(), Context::createDefaultContext());
    }
}
