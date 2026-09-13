<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Checkout\Cart\Delivery;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ShopBite\Checkout\Cart\Delivery\MinuteAwareDeliveryBuilder;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartException;
use Shopware\Core\Checkout\Cart\Delivery\DeliveryBuilder;
use Shopware\Core\Checkout\Cart\Delivery\DeliveryProcessor;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryInformation;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryTime;
use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\System\DeliveryTime\DeliveryTimeEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

#[CoversClass(MinuteAwareDeliveryBuilder::class)]
class MinuteAwareDeliveryBuilderTest extends TestCase
{
    private const string SHIPPING_METHOD_ID = 'shipping-method-id';

    public function testCoreBuilderRejectsMinuteUnit(): void
    {
        $this->expectException(CartException::class);
        $this->expectExceptionMessage('Not supported unit minute');

        new DeliveryBuilder()->buildByUsingShippingMethod(
            $this->createCart($this->createMinuteDeliveryTime(30, 45)),
            $this->createShippingMethod(null),
            $this->createContext()
        );
    }

    public function testLineItemDeliveryTimeInMinutesIsConvertedToHours(): void
    {
        $deliveryTime = $this->createMinuteDeliveryTime(30, 90);
        $cart = $this->createCart($deliveryTime);

        $deliveries = $this->createBuilder()->buildByUsingShippingMethod(
            $cart,
            $this->createShippingMethod(null),
            $this->createContext()
        );

        $delivery = $deliveries->first();
        $this->assertNotNull($delivery);

        // 30 minutes are rounded up to 1 hour; DeliveryDate keeps only the day.
        // (The latest date is not asserted because the core builder adds a one-day buffer.)
        $expected = new \DateTimeImmutable()->add(new \DateInterval('PT1H'))->format('Y-m-d');
        $this->assertSame($expected, $delivery->getDeliveryDate()->getEarliest()->format('Y-m-d'));
    }

    public function testShippingMethodDeliveryTimeInMinutesIsUsedAsDefault(): void
    {
        $cart = $this->createCart(null);

        $entity = new DeliveryTimeEntity();
        $entity->setId('delivery-time-id');
        $entity->setUnit('minute');
        $entity->setMin(15);
        $entity->setMax(45);
        $shippingMethod = $this->createShippingMethod($entity);

        $deliveries = $this->createBuilder()->buildByUsingShippingMethod($cart, $shippingMethod, $this->createContext());

        $this->assertCount(1, $deliveries);
        $this->assertNotNull($deliveries->first());
        $this->assertCount(1, $deliveries->first()->getPositions());

        // the caller's shipping method must not be modified
        $this->assertSame('minute', $shippingMethod->getDeliveryTime()?->getUnit());
    }

    public function testOriginalLineItemDeliveryTimeIsRestored(): void
    {
        $deliveryTime = $this->createMinuteDeliveryTime(30, 45);
        $cart = $this->createCart($deliveryTime);

        $this->createBuilder()->buildByUsingShippingMethod($cart, $this->createShippingMethod(null), $this->createContext());

        $information = $cart->getLineItems()->first()?->getDeliveryInformation();
        $this->assertNotNull($information);
        $this->assertSame($deliveryTime, $information->getDeliveryTime());
        $this->assertSame('minute', $deliveryTime->getUnit());
        $this->assertSame(30, $deliveryTime->getMin());
        $this->assertSame(45, $deliveryTime->getMax());
    }

    public function testOtherUnitsArePassedThroughUntouched(): void
    {
        $deliveryTime = new DeliveryTime();
        $deliveryTime->setName('1-3 days');
        $deliveryTime->setUnit(DeliveryTimeEntity::DELIVERY_TIME_DAY);
        $deliveryTime->setMin(1);
        $deliveryTime->setMax(3);

        $cart = $this->createCart($deliveryTime);

        $deliveries = $this->createBuilder()->buildByUsingShippingMethod($cart, $this->createShippingMethod(null), $this->createContext());

        $this->assertCount(1, $deliveries);
        $this->assertSame(DeliveryTimeEntity::DELIVERY_TIME_DAY, $deliveryTime->getUnit());
    }

    public function testBuildResolvesShippingMethodFromCartData(): void
    {
        $cart = $this->createCart($this->createMinuteDeliveryTime(30, 45));
        $shippingMethod = $this->createShippingMethod(null);

        $data = new CartDataCollection();
        $data->set(DeliveryProcessor::buildKey(self::SHIPPING_METHOD_ID), $shippingMethod);

        $deliveries = $this->createBuilder()->build($cart, $data, $this->createContext(), new CartBehavior());

        $this->assertCount(1, $deliveries);
    }

    public function testBuildThrowsWhenShippingMethodIsMissing(): void
    {
        $this->expectException(CartException::class);

        $this->createBuilder()->build($this->createCart(null), new CartDataCollection(), $this->createContext(), new CartBehavior());
    }

    private function createBuilder(): MinuteAwareDeliveryBuilder
    {
        return new MinuteAwareDeliveryBuilder(new DeliveryBuilder());
    }

    private function createMinuteDeliveryTime(int $min, int $max): DeliveryTime
    {
        $deliveryTime = new DeliveryTime();
        $deliveryTime->setName($min . '-' . $max . ' min');
        $deliveryTime->setUnit('minute');
        $deliveryTime->setMin($min);
        $deliveryTime->setMax($max);

        return $deliveryTime;
    }

    private function createCart(?DeliveryTime $deliveryTime): Cart
    {
        $lineItem = new LineItem('line-item-id', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-id');
        $lineItem->setPrice(new CalculatedPrice(9.9, 9.9, new CalculatedTaxCollection(), new TaxRuleCollection()));
        $lineItem->setShippingCostAware(true);

        if ($deliveryTime !== null) {
            $lineItem->setDeliveryInformation(new DeliveryInformation(10, 0.5, false, null, $deliveryTime));
        }

        $cart = new Cart('token');
        $cart->add($lineItem);

        return $cart;
    }

    private function createShippingMethod(?DeliveryTimeEntity $deliveryTime): ShippingMethodEntity
    {
        $shippingMethod = new ShippingMethodEntity();
        $shippingMethod->setId(self::SHIPPING_METHOD_ID);

        if ($deliveryTime !== null) {
            $shippingMethod->setDeliveryTime($deliveryTime);
        }

        return $shippingMethod;
    }

    private function createContext(): SalesChannelContext
    {
        $shippingMethod = $this->createShippingMethod(null);

        $context = $this->createStub(SalesChannelContext::class);
        $context->method('getShippingMethod')->willReturn($shippingMethod);
        $context->method('getShippingLocation')->willReturn($this->createStub(ShippingLocation::class));

        return $context;
    }
}
