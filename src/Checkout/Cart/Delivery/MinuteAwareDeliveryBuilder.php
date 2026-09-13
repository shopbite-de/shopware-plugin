<?php

declare(strict_types=1);

namespace ShopBite\Checkout\Cart\Delivery;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartException;
use Shopware\Core\Checkout\Cart\Delivery\DeliveryBuilder;
use Shopware\Core\Checkout\Cart\Delivery\DeliveryProcessor;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryCollection;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryInformation;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryTime;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\System\DeliveryTime\DeliveryTimeEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Adds a "minute" unit to Shopware's delivery times.
 *
 * Shopware's DeliveryDate only understands hour/day/week/month/year and throws for
 * anything else, which breaks cart calculation as soon as a shipping method or product
 * uses a delivery time in minutes. Since DeliveryDate normalises every date to 16:00 the
 * exact minute count cannot be represented anyway, so minute-based delivery times are
 * converted to the next full hour for the duration of the core calculation only. The
 * original delivery information on the line items is restored afterwards so the cart and
 * Store API keep reporting the configured unit.
 *
 * @psalm-suppress UnusedClass
 */
final class MinuteAwareDeliveryBuilder extends DeliveryBuilder
{
    public const string UNIT_MINUTE = 'minute';

    private const int MINUTES_PER_HOUR = 60;

    public function __construct(
        private readonly DeliveryBuilder $inner,
    ) {
    }

    #[\Override]
    public function build(Cart $cart, CartDataCollection $data, SalesChannelContext $context, CartBehavior $cartBehavior): DeliveryCollection
    {
        $key = DeliveryProcessor::buildKey($context->getShippingMethod()->getId());

        if (!$data->has($key)) {
            throw CartException::shippingMethodNotFound($context->getShippingMethod()->getId());
        }

        /** @var ShippingMethodEntity $shippingMethod */
        $shippingMethod = $data->get($key);

        return $this->buildByUsingShippingMethod($cart, $shippingMethod, $context);
    }

    #[\Override]
    public function buildByUsingShippingMethod(Cart $cart, ShippingMethodEntity $shippingMethod, SalesChannelContext $context): DeliveryCollection
    {
        $restore = $this->replaceMinuteDeliveryTimes($cart);

        try {
            return $this->inner->buildByUsingShippingMethod(
                $cart,
                $this->withHourBasedDeliveryTime($shippingMethod),
                $context
            );
        } finally {
            foreach ($restore as [$information, $deliveryTime]) {
                $information->setDeliveryTime($deliveryTime);
            }
        }
    }

    /**
     * Temporarily swaps minute-based delivery times on all (nested) line items.
     *
     * @return list<array{DeliveryInformation, DeliveryTime}> the original values to restore
     */
    private function replaceMinuteDeliveryTimes(Cart $cart): array
    {
        $restore = [];

        foreach ($cart->getLineItems()->getFlat() as $lineItem) {
            $information = $lineItem->getDeliveryInformation();
            $deliveryTime = $information?->getDeliveryTime();

            if ($information === null || $deliveryTime === null || $deliveryTime->getUnit() !== self::UNIT_MINUTE) {
                continue;
            }

            $converted = clone $deliveryTime;
            $converted->setUnit(DeliveryTimeEntity::DELIVERY_TIME_HOUR);
            $converted->setMin(self::minutesToHours($deliveryTime->getMin()));
            $converted->setMax(self::minutesToHours($deliveryTime->getMax()));

            $information->setDeliveryTime($converted);
            $restore[] = [$information, $deliveryTime];
        }

        return $restore;
    }

    private function withHourBasedDeliveryTime(ShippingMethodEntity $shippingMethod): ShippingMethodEntity
    {
        $deliveryTime = $shippingMethod->getDeliveryTime();

        if ($deliveryTime === null || $deliveryTime->getUnit() !== self::UNIT_MINUTE) {
            return $shippingMethod;
        }

        $converted = clone $deliveryTime;
        $converted->setUnit(DeliveryTimeEntity::DELIVERY_TIME_HOUR);
        $converted->setMin(self::minutesToHours($deliveryTime->getMin()));
        $converted->setMax(self::minutesToHours($deliveryTime->getMax()));

        $shippingMethod = clone $shippingMethod;
        $shippingMethod->setDeliveryTime($converted);

        return $shippingMethod;
    }

    private static function minutesToHours(int $minutes): int
    {
        return (int) ceil($minutes / self::MINUTES_PER_HOUR);
    }
}
