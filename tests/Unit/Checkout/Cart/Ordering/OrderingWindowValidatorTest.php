<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Checkout\Cart\Ordering;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use ShopBite\BusinessHour\BusinessHourCollection;
use ShopBite\BusinessHour\BusinessHourEntity;
use ShopBite\Checkout\Cart\Ordering\OrderingClosedError;
use ShopBite\Checkout\Cart\Ordering\OrderingWindow;
use ShopBite\Checkout\Cart\Ordering\OrderingWindowValidator;
use ShopBite\Fulfillment\MinuteDeliveryTimes;
use ShopBite\Fulfillment\ShippingMethodFulfillment;
use ShopBite\Fulfillment\ShippingMethodLeadTimes;
use ShopBite\Holiday\HolidayCollection;
use ShopBite\Settings\RestaurantSettings;
use ShopBite\Tests\Unit\Fulfillment\ShippingMethodFulfillmentTest;
use ShopBite\Tests\Unit\Fulfillment\ShippingMethodLeadTimesTest;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Delivery\Struct\Delivery;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryCollection;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryDate;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryPositionCollection;
use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Api\Context\ContextSource;
use Shopware\Core\Framework\Api\Context\SalesChannelApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\DeliveryTime\DeliveryTimeEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;

#[CoversClass(OrderingWindowValidator::class)]
#[CoversClass(OrderingClosedError::class)]
#[UsesClass(ShippingMethodFulfillment::class)]
#[UsesClass(ShippingMethodLeadTimes::class)]
#[AllowMockObjectsWithoutExpectations]
class OrderingWindowValidatorTest extends TestCase
{
    private const string CHANNEL = '0190000000007000800000000000b001';

    /** @var EntityRepository<BusinessHourCollection>&MockObject */
    private EntityRepository&MockObject $businessHours;

    /** @var EntityRepository<HolidayCollection>&MockObject */
    private EntityRepository&MockObject $holidays;

    private SystemConfigService&MockObject $systemConfig;

    /** @var array<string, mixed> */
    private array $config = [];

    /** @var list<DeliveryTimeEntity> what the delivery time repository finds */
    private array $deliveryTimes = [];

    protected function setUp(): void
    {
        $this->systemConfig = $this->createMock(SystemConfigService::class);
        $this->systemConfig->method('get')->willReturnCallback(fn (string $key) => $this->config[$key] ?? null);

        $this->businessHours = $this->createMock(EntityRepository::class);
        $this->businessHours->method('search')->willReturnCallback(
            fn (Criteria $criteria) => $this->searchResult(new BusinessHourCollection([$this->hour(1, '11:30', '22:00')])),
        );
        $this->holidays = $this->createMock(EntityRepository::class);
        $this->holidays->method('search')->willReturnCallback(fn () => $this->searchResult(new HolidayCollection()));
    }

    public function testOpenShopAddsNoError(): void
    {
        $errors = $this->validate('2026-11-16 11:00 UTC');

        $this->assertCount(0, $errors);
    }

    public function testPausedOrderingBlocksTheOrder(): void
    {
        $this->config[RestaurantSettings::ORDERING_OPEN] = false;

        $errors = $this->validate('2026-11-16 11:00 UTC');

        $this->assertBlockedWith(OrderingWindow::PAUSED, $errors);
    }

    public function testClosedShopBlocksTheOrder(): void
    {
        $errors = $this->validate('2026-11-17 11:00 UTC');

        $this->assertBlockedWith(OrderingWindow::CLOSED, $errors);
    }

    public function testLeadTimeOfTheSelectedShippingMethodIsUsed(): void
    {
        // 20:30 UTC = 21:30 Berlin; 60 minutes delivery ends after 22:00, 15 minutes pickup before.
        $this->assertBlockedWith(OrderingWindow::CLOSED, $this->validate('2026-11-16 20:30 UTC', shippingMethod: $this->shippingMethod(ShippingMethodFulfillmentTest::deliveryTime('minute', 45, 60))));
        $this->assertCount(0, $this->validate('2026-11-16 20:30 UTC', shippingMethod: $this->shippingMethod(ShippingMethodFulfillmentTest::deliveryTime('minute', 15, 15))));
        $this->assertBlockedWith(OrderingWindow::CLOSED, $this->validate('2026-11-16 20:30 UTC', shippingMethod: $this->shippingMethod(ShippingMethodFulfillmentTest::deliveryTime('hour', 0, 1))));
    }

    public function testDeliveryTimeIsLoadedWhenTheContextHasNone(): void
    {
        // The sales channel context loads its shipping method without the delivery time association.
        $method = $this->shippingMethod(null);
        $method->setDeliveryTimeId('0190000000007000800000000000f001');
        $this->deliveryTimes = [ShippingMethodFulfillmentTest::deliveryTime('minute', 60, 60)];

        $this->assertBlockedWith(OrderingWindow::CLOSED, $this->validate('2026-11-16 20:30 UTC', shippingMethod: $method));
    }

    public function testDeliveryTimeOfTheCartDeliveryIsUsed(): void
    {
        // The cart's delivery carries the shipping method with its delivery time: no extra query.
        $cart = new Cart('token');
        $cart->add(new LineItem('item', LineItem::PRODUCT_LINE_ITEM_TYPE, '0190000000007000800000000000c001'));
        $cart->setDeliveries(new DeliveryCollection([new Delivery(
            new DeliveryPositionCollection(),
            new DeliveryDate(new \DateTimeImmutable(), new \DateTimeImmutable()),
            $this->shippingMethod(ShippingMethodFulfillmentTest::deliveryTime('minute', 60, 60)),
            $this->createStub(ShippingLocation::class),
            new CalculatedPrice(0, 0, new CalculatedTaxCollection(), new TaxRuleCollection()),
        )]));
        $method = $this->shippingMethod(null);
        $method->setDeliveryTimeId('0190000000007000800000000000f001');

        $this->assertBlockedWith(OrderingWindow::CLOSED, $this->validate('2026-11-16 20:30 UTC', cart: $cart, shippingMethod: $method));
    }

    public function testShippingMethodWithoutLeadTimeTakes30Minutes(): void
    {
        // 21:30 Berlin + 30 - 5 grace = 21:55, before 22:00; 21:40 + 25 = 22:05 is after.
        $this->assertCount(0, $this->validate('2026-11-16 20:30 UTC', shippingMethod: $this->shippingMethod(null)));
        $this->assertBlockedWith(OrderingWindow::CLOSED, $this->validate('2026-11-16 20:40 UTC', shippingMethod: $this->shippingMethod(null)));
    }

    public function testBusinessTimeZoneOfTheSalesChannelIsUsed(): void
    {
        $salesChannel = $this->salesChannel();
        if (!method_exists($salesChannel, 'setBusinessTimeZone')) {
            $this->markTestSkipped('Sales channels have a business time zone since Shopware 6.7.13.');
        }
        // 20:00 in New York is 01:00 UTC the next day.
        $salesChannel->setBusinessTimeZone('America/New_York');

        $this->assertCount(0, $this->validate('2026-11-17 01:00 UTC', salesChannel: $salesChannel));
        $this->assertBlockedWith(OrderingWindow::CLOSED, $this->validate('2026-11-17 01:00 UTC'));
    }

    public function testDataIsLoadedForTheSalesChannel(): void
    {
        $this->businessHours = $this->createMock(EntityRepository::class);
        $this->businessHours->expects($this->once())->method('search')
            ->with($this->callback(fn (Criteria $criteria): bool => $this->filtersChannel($criteria)))
            ->willReturn($this->searchResult(new BusinessHourCollection()));
        $this->holidays = $this->createMock(EntityRepository::class);
        $this->holidays->expects($this->once())->method('search')
            ->with($this->callback(fn (Criteria $criteria): bool => $this->filtersChannel($criteria)))
            ->willReturn($this->searchResult(new HolidayCollection()));

        $this->validate('2026-11-16 11:00 UTC');
    }

    public function testEmptyCartIsNotChecked(): void
    {
        $this->config[RestaurantSettings::ORDERING_OPEN] = false;

        $this->assertCount(0, $this->validate('2026-11-17 11:00 UTC', cart: new Cart('token')));
    }

    public function testAdministrationIsNotChecked(): void
    {
        $this->config[RestaurantSettings::ORDERING_OPEN] = false;

        $this->assertCount(0, $this->validate('2026-11-17 11:00 UTC', source: new AdminApiSource(null)));
        $this->assertCount(0, $this->validate('2026-11-17 11:00 UTC', permissions: ['allowProductPriceOverwrites' => true]));
    }

    private function assertBlockedWith(string $key, ErrorCollection $errors): void
    {
        $this->assertCount(1, $errors);
        $error = $errors->first();
        $this->assertInstanceOf(OrderingClosedError::class, $error);
        $this->assertSame($key, $error->getMessageKey());
        $this->assertSame($key, $error->getId());
        $this->assertTrue($error->blockOrder());
        $this->assertTrue($errors->blockOrder());
    }

    /**
     * @param array<string, bool> $permissions
     */
    private function validate(
        string $now,
        ?Cart $cart = null,
        ?SalesChannelEntity $salesChannel = null,
        ?ContextSource $source = null,
        array $permissions = [],
        ?ShippingMethodEntity $shippingMethod = null,
    ): ErrorCollection {
        if ($cart === null) {
            $cart = new Cart('token');
            $cart->add(new LineItem('item', LineItem::PRODUCT_LINE_ITEM_TYPE, '0190000000007000800000000000c001'));
        }

        $context = $this->createStub(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn(self::CHANNEL);
        $context->method('getSalesChannel')->willReturn($salesChannel ?? $this->salesChannel());
        $context->method('getContext')->willReturn(new Context($source ?? new SalesChannelApiSource(self::CHANNEL)));
        $context->method('getShippingMethod')->willReturn($shippingMethod ?? $this->shippingMethod(null));
        $context->method('hasPermission')->willReturnCallback(fn (string $permission): bool => $permissions[$permission] ?? false);

        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable($now));

        $deliveryTimes = $this->createStub(EntityRepository::class);
        $deliveryTimes->method('search')->willReturn(ShippingMethodLeadTimesTest::deliveryTimeResult($this->deliveryTimes));

        $validator = new OrderingWindowValidator(
            new RestaurantSettings($this->systemConfig),
            new ShippingMethodLeadTimes($this->createStub(EntityRepository::class), $deliveryTimes, $this->createStub(Connection::class), new MinuteDeliveryTimes($this->createStub(Connection::class))),
            $this->businessHours,
            $this->holidays,
            $clock,
        );

        $errors = new ErrorCollection();
        $validator->validate($cart, $errors, $context);

        return $errors;
    }

    private function shippingMethod(?DeliveryTimeEntity $deliveryTime): ShippingMethodEntity
    {
        $method = new ShippingMethodEntity();
        $method->setId('0190000000007000800000000000d001');
        $method->setTechnicalName('shipping_standard');
        if ($deliveryTime !== null) {
            $method->setDeliveryTime($deliveryTime);
            $method->setDeliveryTimeId($deliveryTime->getId());
        }

        return $method;
    }

    private function salesChannel(): SalesChannelEntity
    {
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId(self::CHANNEL);
        $salesChannel->setNavigationCategoryId('211cf9b2d1725130a517bbadf753a43c');

        return $salesChannel;
    }

    private function hour(int $dayOfWeek, string $opening, string $closing): BusinessHourEntity
    {
        $hour = new BusinessHourEntity();
        $hour->setId(bin2hex(random_bytes(16)));
        $hour->setDayOfWeek($dayOfWeek);
        $hour->setOpeningTime($opening);
        $hour->setClosingTime($closing);

        return $hour;
    }

    private function filtersChannel(Criteria $criteria): bool
    {
        foreach ($criteria->getFilters() as $filter) {
            if ($filter instanceof EqualsFilter && $filter->getField() === 'salesChannelId' && $filter->getValue() === self::CHANNEL) {
                return true;
            }
        }

        return false;
    }

    /**
     * @template T of \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection
     *
     * @param T $collection
     *
     * @return EntitySearchResult<T>
     */
    private function searchResult(object $collection): EntitySearchResult
    {
        $result = $this->createStub(EntitySearchResult::class);
        $result->method('getEntities')->willReturn($collection);

        return $result;
    }
}
