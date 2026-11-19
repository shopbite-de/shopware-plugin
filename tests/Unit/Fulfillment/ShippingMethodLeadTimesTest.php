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
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

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

    private Connection&MockObject $connection;

    private ?Criteria $criteria = null;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(EntityRepository::class);
        $this->repository->method('search')->willReturnCallback(function (Criteria $criteria): EntitySearchResult {
            $this->criteria = $criteria;

            return self::searchResult(self::methods());
        });
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
    }

    public function testBackofficeGetsNamesAndWhetherTheLeadTimeIsSet(): void
    {
        $methods = $this->service()->backoffice(self::CHANNEL, Context::createDefaultContext());

        $this->assertSame([
            ['id' => self::DELIVERY, 'name' => 'Lieferung', 'fulfillment' => 'delivery', 'leadTimeMinutes' => 45, 'leadTimeMinutesSet' => true],
            ['id' => self::PICKUP, 'name' => 'Abholung', 'fulfillment' => 'pickup', 'leadTimeMinutes' => 30, 'leadTimeMinutesSet' => false],
        ], $methods);
        $this->assertFiltersActiveMethodsOfTheChannel();
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

    public function testWritesTheLeadTimeCustomFieldOnly(): void
    {
        $this->repository->expects($this->once())->method('update')->with(
            [
                ['id' => self::DELIVERY, 'customFields' => ['shopbite_lead_time_minutes' => 50]],
                ['id' => self::PICKUP, 'customFields' => ['shopbite_lead_time_minutes' => 15]],
            ],
            $this->isInstanceOf(Context::class),
        );

        $this->service()->write([self::DELIVERY => 50, self::PICKUP => 15]);
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
        return new ShippingMethodLeadTimes($this->repository, $this->connection);
    }

    public static function methods(): ShippingMethodCollection
    {
        $delivery = new ShippingMethodEntity();
        $delivery->setId(self::DELIVERY);
        $delivery->setTechnicalName('shipping_standard');
        $delivery->setName('Lieferung');
        $delivery->setCustomFields(['shopbite_fulfillment' => 'delivery', 'shopbite_lead_time_minutes' => 45]);

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
}
