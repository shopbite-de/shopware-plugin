<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Fulfillment;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShopBite\Fulfillment\ShippingMethodFulfillment;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\System\DeliveryTime\DeliveryTimeEntity;

#[CoversClass(ShippingMethodFulfillment::class)]
class ShippingMethodFulfillmentTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>|null, string}>
     */
    public static function fulfillments(): iterable
    {
        yield 'delivery' => [['shopbite_fulfillment' => 'delivery'], 'delivery'];
        yield 'pickup' => [['shopbite_fulfillment' => 'pickup'], 'pickup'];
        yield 'unset is delivery' => [null, 'delivery'];
        yield 'no custom fields is delivery' => [[], 'delivery'];
        yield 'unknown value is delivery' => [['shopbite_fulfillment' => 'drone'], 'delivery'];
        yield 'other case is delivery' => [['shopbite_fulfillment' => 'PICKUP'], 'delivery'];
    }

    /**
     * @param array<string, mixed>|null $customFields
     */
    #[DataProvider('fulfillments')]
    public function testFulfillmentIsTheCustomFieldElseDelivery(?array $customFields, string $expected): void
    {
        // No guessing from the name at runtime: a method named "Abholung" without the field is delivery.
        $this->assertSame($expected, ShippingMethodFulfillment::fulfillment($this->method($customFields, 'shipping_pickup', 'Abholung')));
    }

    /**
     * @return iterable<string, array{string|null, int, int, int, bool}>
     */
    public static function deliveryTimes(): iterable
    {
        // [unit, min, max, expected minutes, set]
        yield 'minutes: the maximum' => ['minute', 10, 45, 45, true];
        yield 'minute minimum' => ['minute', 1, 1, 1, true];
        yield 'zero minutes are one' => ['minute', 0, 0, 1, true];
        yield 'hours' => ['hour', 0, 1, 60, true];
        yield 'days' => ['day', 1, 1, 1440, true];
        yield 'more than a day is clamped' => ['hour', 24, 48, 1440, true];
        yield 'Shopware default 1-3 days' => ['day', 1, 3, 1440, true];
        yield 'week' => ['week', 1, 2, 1440, true];
        yield 'month' => ['month', 1, 1, 1440, true];
        yield 'year' => ['year', 1, 1, 1440, true];
        yield 'zero days are one minute' => ['day', 0, 0, 1, true];
        yield 'unknown unit is 30' => ['fortnight', 1, 1, 30, false];
        yield 'no delivery time is 30' => [null, 0, 0, 30, false];
    }

    #[DataProvider('deliveryTimes')]
    public function testLeadTimeIsTheMaximumOfTheDeliveryTimeInMinutes(?string $unit, int $min, int $max, int $expected, bool $set): void
    {
        $method = $this->method(null);
        if ($unit !== null) {
            $method->setDeliveryTime(self::deliveryTime($unit, $min, $max));
        }

        $this->assertSame($expected, ShippingMethodFulfillment::leadTimeMinutes($method));
        $this->assertSame($set, ShippingMethodFulfillment::isLeadTimeSet($method));
    }

    public function testTheRemovedCustomFieldIsIgnored(): void
    {
        $method = $this->method(['shopbite_lead_time_minutes' => 45]);
        $method->setDeliveryTime(self::deliveryTime('minute', 20, 20));

        $this->assertSame(20, ShippingMethodFulfillment::leadTimeMinutes($method));
        $this->assertSame(30, ShippingMethodFulfillment::leadTimeMinutes($this->method(['shopbite_lead_time_minutes' => 45])));
    }

    /**
     * @return iterable<string, array{int, string, string}>
     */
    public static function names(): iterable
    {
        yield 'German' => [20, 'de-DE', '20 Minuten'];
        yield 'German singular' => [1, 'de-CH', '1 Minute'];
        yield 'English' => [45, 'en-GB', '45 minutes'];
        yield 'English singular' => [1, 'en-US', '1 minute'];
        yield 'other languages get English' => [15, 'nl-NL', '15 minutes'];
    }

    #[DataProvider('names')]
    public function testDeliveryTimeNames(int $minutes, string $locale, string $expected): void
    {
        $this->assertSame($expected, ShippingMethodFulfillment::deliveryTimeName($minutes, $locale));
    }

    public function testTranslatedCustomFieldsWin(): void
    {
        // The DAL hydrates translated fields with the language fallback chain into `translated`.
        $method = $this->method(['shopbite_fulfillment' => 'delivery']);
        $method->setTranslated(['customFields' => ['shopbite_fulfillment' => 'pickup'], 'name' => 'Lieferung']);

        $this->assertSame('pickup', ShippingMethodFulfillment::fulfillment($method));
    }

    public static function deliveryTime(string $unit, int $min, int $max): DeliveryTimeEntity
    {
        $deliveryTime = new DeliveryTimeEntity();
        $deliveryTime->setId(md5($unit . $min . $max));
        $deliveryTime->setUnit($unit);
        $deliveryTime->setMin($min);
        $deliveryTime->setMax($max);

        return $deliveryTime;
    }

    public function testTranslatedNameIsUsed(): void
    {
        $method = $this->method(null, 'shipping_standard', 'Pickup');
        $method->setTranslated(['name' => 'Abholung']);

        $this->assertSame('Abholung', ShippingMethodFulfillment::name($method));
        $this->assertSame('Pickup', ShippingMethodFulfillment::name($this->method(null, 'shipping_standard', 'Pickup')));
        $this->assertSame('shipping_standard', ShippingMethodFulfillment::name($this->method(null, 'shipping_standard', null)));
    }

    /**
     * @param array<string, mixed>|null $customFields
     */
    private function method(?array $customFields, string $technicalName = 'shipping_standard', ?string $name = 'Standard'): ShippingMethodEntity
    {
        $method = new ShippingMethodEntity();
        $method->setId('0190000000007000800000000000d001');
        $method->setTechnicalName($technicalName);
        if ($name !== null) {
            $method->setName($name);
        }
        $method->setCustomFields($customFields);

        return $method;
    }
}
