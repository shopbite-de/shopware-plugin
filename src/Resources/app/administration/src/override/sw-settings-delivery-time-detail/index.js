import enGB from './snippet/en-GB.json';
import deDE from './snippet/de-DE.json';

const { Component, Locale } = Shopware;

Locale.extend('en-GB', enGB);
Locale.extend('de-DE', deDE);

/**
 * Adds "minute" to the selectable delivery time units. The cart-side support for this
 * unit lives in ShopBite\Checkout\Cart\Delivery\MinuteAwareDeliveryBuilder.
 */
Component.override('sw-settings-delivery-time-detail', {
    computed: {
        deliveryTimeUnits() {
            return [
                {
                    value: 'minute',
                    label: this.$t('sw-settings-delivery-time.detail.selectionUnitMinute'),
                },
                ...this.$super('deliveryTimeUnits'),
            ];
        },
    },
});
