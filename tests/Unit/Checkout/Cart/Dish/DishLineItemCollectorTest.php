<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Checkout\Cart\Dish;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ShopBite\Checkout\Cart\Dish\DishLineItemCollector;
use ShopBite\Service\CustomFieldsInstaller;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
use Shopware\Core\Content\Property\PropertyGroupEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

#[CoversClass(DishLineItemCollector::class)]
class DishLineItemCollectorTest extends TestCase
{
    private const string PIZZA_ID = '0190a1b2c3d4e5f60718293a4b5c6d7e';
    private const string SALAMI_ID = '0190a1b2c3d4e5f60718293a4b5c6d7f';
    private const string OLIVES_ID = '0190a1b2c3d4e5f60718293a4b5c6d80';

    public function testLabelAndPayloadComeFromTheProducts(): void
    {
        $dish = $this->dish(['without' => ['Zwiebeln', 'Unbekannt', 'Rote Paprika']]);
        $dish->setLabel('Free pizza');
        $cart = $this->cart($dish);

        $this->collect($cart, $this->pizza(['shopbite_receipt_print_type' => 'number']));

        $this->assertSame('Pizza Margherita +Salami +Oliven -Zwiebeln -Rote Paprika', $dish->getLabel());
        $this->assertSame([
            'productNumber' => 'SW-10001',
            'without' => ['Zwiebeln', 'Rote Paprika'],
            CustomFieldsInstaller::SHOPBITE_RECEIPT_PRINT_TYPE => 'number',
        ], $dish->getPayload());
    }

    public function testPayloadKeysFromTheRequestAreRemoved(): void
    {
        $dish = $this->dish([
            'without' => [],
            'productNumber' => 'SW-FAKE',
            CustomFieldsInstaller::SHOPBITE_RECEIPT_PRINT_TYPE => 'label',
            'other' => 'value',
        ]);
        $cart = $this->cart($dish);

        $this->collect($cart, $this->pizza());

        $this->assertSame('Pizza Margherita +Salami +Oliven', $dish->getLabel());
        $this->assertSame(['productNumber' => 'SW-10001', 'without' => []], $dish->getPayload());
    }

    public function testDishWithoutItsProductIsRemoved(): void
    {
        $dish = $this->dish(['without' => []]);
        $dish->getChildren()->remove('main');
        $cart = $this->cart($dish);

        $this->collect($cart, $this->pizza());

        $this->assertCount(0, $cart->getLineItems());
    }

    public function testDishFromBeforeTheUpdateUsesItsFirstChild(): void
    {
        $dish = $this->dish(['productNumber' => 'SW-OLD']);
        $dish->setReferencedId(null);
        $cart = $this->cart($dish);

        $this->collect($cart, $this->pizza());

        $this->assertSame(self::PIZZA_ID, $dish->getReferencedId());
        $this->assertSame('Pizza Margherita +Salami +Oliven', $dish->getLabel());
        $this->assertSame(['productNumber' => 'SW-10001', 'without' => []], $dish->getPayload());
    }

    public function testDishIsLeftAloneWithoutProductData(): void
    {
        $dish = $this->dish(['without' => ['Zwiebeln']]);
        $cart = $this->cart($dish);

        new DishLineItemCollector()->collect(
            new CartDataCollection(),
            $cart,
            $this->createStub(SalesChannelContext::class),
            new CartBehavior(),
        );

        $this->assertCount(1, $cart->getLineItems());
        $this->assertNull($dish->getLabel());
        $this->assertSame(['without' => ['Zwiebeln']], $dish->getPayload());
    }

    public function testOtherLineItemsAreNotTouched(): void
    {
        $product = new LineItem(self::PIZZA_ID, LineItem::PRODUCT_LINE_ITEM_TYPE, self::PIZZA_ID);
        $product->setLabel('Pizza Margherita');
        $product->setPayloadValue('productNumber', 'SW-10001');
        $cart = new Cart('token');
        $cart->add($product);

        $this->collect($cart, $this->pizza());

        $this->assertSame('Pizza Margherita', $product->getLabel());
        $this->assertSame(['productNumber' => 'SW-10001'], $product->getPayload());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function dish(array $payload): LineItem
    {
        $dish = new LineItem('dish', 'container', self::PIZZA_ID);
        $dish->setStackable(true);
        $dish->setPayload($payload);

        $main = new LineItem('main', LineItem::PRODUCT_LINE_ITEM_TYPE, self::PIZZA_ID);
        $main->setLabel('Pizza Margherita');
        $salami = new LineItem(self::SALAMI_ID, LineItem::PRODUCT_LINE_ITEM_TYPE, self::SALAMI_ID);
        $salami->setLabel('Salami');
        $olives = new LineItem(self::OLIVES_ID, LineItem::PRODUCT_LINE_ITEM_TYPE, self::OLIVES_ID);
        $olives->setLabel('Oliven');
        $dish->setChildren(new LineItemCollection([$main, $salami, $olives]));

        return $dish;
    }

    private function cart(LineItem $dish): Cart
    {
        $cart = new Cart('token');
        $cart->add($dish);

        return $cart;
    }

    /**
     * @param array<string, mixed> $customFields
     */
    private function pizza(array $customFields = []): SalesChannelProductEntity
    {
        $product = new SalesChannelProductEntity();
        $product->setId(self::PIZZA_ID);
        $product->setProductNumber('SW-10001');
        $product->setName('Pizza Margherita');
        $product->setTranslated(['name' => 'Pizza Margherita', 'customFields' => $customFields]);
        $product->setCustomFields($customFields);

        $ingredients = new PropertyGroupEntity();
        $ingredients->setId('group-ingredients');
        $ingredients->setName('Hauptzutaten');
        $ingredients->setTranslated(['name' => 'Hauptzutaten']);

        $allergens = new PropertyGroupEntity();
        $allergens->setId('group-allergens');
        $allergens->setName('Allergene');
        $allergens->setTranslated(['name' => 'Allergene']);

        $product->setProperties(new PropertyGroupOptionCollection([
            $this->option('o1', 'Zwiebeln', $ingredients),
            $this->option('o2', 'Rote Paprika', $ingredients),
            $this->option('o3', 'Unbekannt', $allergens),
        ]));

        return $product;
    }

    private function option(string $id, string $name, PropertyGroupEntity $group): PropertyGroupOptionEntity
    {
        $option = new PropertyGroupOptionEntity();
        $option->setId($id);
        $option->setName($name);
        $option->setTranslated(['name' => $name]);
        $option->setGroup($group);

        return $option;
    }

    private function collect(Cart $cart, SalesChannelProductEntity $product): void
    {
        $data = new CartDataCollection();
        $data->set('product-' . $product->getId(), $product);

        new DishLineItemCollector()->collect(
            $data,
            $cart,
            $this->createStub(SalesChannelContext::class),
            new CartBehavior(),
        );
    }
}
