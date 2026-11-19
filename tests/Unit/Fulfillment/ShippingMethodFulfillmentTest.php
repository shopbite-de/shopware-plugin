<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Fulfillment;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShopBite\Fulfillment\ShippingMethodFulfillment;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;

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
     * @return iterable<string, array{mixed, int, bool}>
     */
    public static function leadTimes(): iterable
    {
        yield 'custom field' => [45, 45, true];
        yield 'minimum' => [1, 1, true];
        yield 'maximum' => [1440, 1440, true];
        yield 'unset is 30' => [null, 30, false];
        yield 'zero is 30' => [0, 30, false];
        yield 'too long is 30' => [1441, 30, false];
        yield 'float is 30' => [12.5, 30, false];
        yield 'string is 30' => ['20', 30, false];
    }

    #[DataProvider('leadTimes')]
    public function testLeadTimeIsTheCustomFieldElse30Minutes(mixed $customField, int $expected, bool $set): void
    {
        $method = $this->method($customField === null ? null : ['shopbite_lead_time_minutes' => $customField]);

        $this->assertSame($expected, ShippingMethodFulfillment::leadTimeMinutes($method));
        $this->assertSame($set, ShippingMethodFulfillment::isLeadTimeSet($method));
    }

    public function testTranslatedCustomFieldsWin(): void
    {
        // The DAL hydrates translated fields with the language fallback chain into `translated`.
        $method = $this->method(['shopbite_lead_time_minutes' => 50, 'shopbite_fulfillment' => 'delivery']);
        $method->setTranslated(['customFields' => ['shopbite_lead_time_minutes' => 15, 'shopbite_fulfillment' => 'pickup'], 'name' => 'Lieferung']);

        $this->assertSame(15, ShippingMethodFulfillment::leadTimeMinutes($method));
        $this->assertSame('pickup', ShippingMethodFulfillment::fulfillment($method));
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
