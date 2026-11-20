<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Fulfillment;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
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
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\DeliveryTime\DeliveryTimeCollection;
use Shopware\Core\System\DeliveryTime\DeliveryTimeEntity;

#[CoversClass(ShippingMethodLeadTimes::class)]
#[UsesClass(ShippingMethodFulfillment::class)]
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
        $existing = ShippingMethodFulfillmentTest::deliveryTime('minute', 50, 50);
        $this->connection->method('fetchAllAssociative')->willReturnCallback(static function (string $sql) use ($german): array {
            static::assertStringContainsString('FROM language', $sql);

            return [
                ['id' => Defaults::LANGUAGE_SYSTEM, 'locale' => 'en-GB'],
                ['id' => $german, 'locale' => 'de-DE'],
            ];
        });
        $this->deliveryTimes->expects($this->once())->method('search')
            ->with($this->callback(static function (Criteria $criteria): bool {
                $filters = [];
                foreach ($criteria->getFilters() as $filter) {
                    if ($filter instanceof EqualsFilter || $filter instanceof EqualsAnyFilter) {
                        $filters[$filter->getField()] = $filter->getValue();
                    }
                }

                return $filters === ['unit' => 'minute', 'max' => [50, 15]];
            }), $this->callback(static fn (Context $context): bool => $context->getSource() instanceof SystemSource))
            // a range and another unit with the same maximum do not count
            ->willReturn(self::deliveryTimeResult([
                ShippingMethodFulfillmentTest::deliveryTime('minute', 15, 50),
                ShippingMethodFulfillmentTest::deliveryTime('hour', 15, 15),
                $existing,
            ]));
        $created = [];
        $this->deliveryTimes->expects($this->once())->method('create')
            ->willReturnCallback(function (array $data, Context $context) use (&$created) {
                static::assertInstanceOf(SystemSource::class, $context->getSource());
                $created = $data;

                return $this->createStub(\Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent::class);
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

        $this->service()->write([self::DELIVERY => 50, self::PICKUP => 15, '0190000000007000800000000000d003' => 15]);

        $this->assertCount(1, $created, 'one new delivery time for both methods with 15 minutes');
        $id = $created[0]['id'];
        $this->assertIsString($id);
        $this->assertTrue(Uuid::isValid($id));
        $this->assertSame([
            'id' => $id,
            'unit' => 'minute',
            'min' => 15,
            'max' => 15,
            'translations' => [
                Defaults::LANGUAGE_SYSTEM => ['name' => '15 minutes'],
                $german => ['name' => '15 Minuten'],
            ],
        ], $created[0]);
        $this->assertSame([
            ['id' => self::DELIVERY, 'deliveryTimeId' => $existing->getId()],
            ['id' => self::PICKUP, 'deliveryTimeId' => $id],
            ['id' => '0190000000007000800000000000d003', 'deliveryTimeId' => $id],
        ], $updates);
    }

    public function testWriteWithoutMissingDeliveryTimesCreatesNone(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([['id' => Defaults::LANGUAGE_SYSTEM, 'locale' => 'de-DE']]);
        $existing = ShippingMethodFulfillmentTest::deliveryTime('minute', 20, 20);
        $this->deliveryTimes->method('search')->willReturn(self::deliveryTimeResult([$existing]));
        $this->deliveryTimes->expects($this->never())->method('create');
        $this->repository->expects($this->once())->method('update')
            ->with([['id' => self::DELIVERY, 'deliveryTimeId' => $existing->getId()]]);

        $this->service()->write([self::DELIVERY => 20]);
    }

    public function testEmptyWriteDoesNothing(): void
    {
        $this->deliveryTimes->expects($this->never())->method('search');
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
        return new ShippingMethodLeadTimes($this->repository, $this->deliveryTimes, $this->connection);
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
