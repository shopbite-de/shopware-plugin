<?php

declare(strict_types=1);

namespace ShopBite\Fulfillment;

/**
 * Deactivating or uninstalling the plugin is refused while something Shopware core cannot
 * calculate without it still uses a delivery time in minutes.
 */
final class MinuteDeliveryTimesException extends \RuntimeException
{
    /**
     * @param 'deactivated'|'uninstalled' $action
     * @param list<string>                $blockers
     */
    public static function stillInUse(string $action, array $blockers): self
    {
        return new self(\sprintf(
            'ShopBite cannot be %s: Shopware does not know the delivery time unit "minute", so carts with it would fail. '
            . 'Still using a delivery time in minutes: %s. Give them a delivery time with unit hour, day, week, month or year '
            . '(shipping methods: Settings > Shipping > Delivery time, products: Deliverability > Delivery time), then try again.',
            $action,
            implode(', ', $blockers),
        ));
    }
}
