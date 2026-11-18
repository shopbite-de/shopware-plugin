<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Checkout\Cart\Dish;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ShopBite\Checkout\Cart\Dish\DishLineItemCollector;
use ShopBite\Checkout\Cart\Dish\DishText;
use ShopBite\Service\CustomFieldsInstaller;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
use Shopware\Core\Content\Property\PropertyGroupEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

#[CoversClass(DishLineItemCollector::class)]
#[CoversClass(DishText::class)]
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

        $this->collect($cart, $this->pizza());

        $this->assertSame('Pizza Margherita +Salami +Oliven -Zwiebeln -Rote Paprika', $dish->getLabel());
        $this->assertSame(['productNumber' => 'SW-10001', 'without' => ['Zwiebeln', 'Rote Paprika']], $dish->getPayload());
    }

    public function testDishGetsNoReceiptPrintType(): void
    {
        // As before: the receipt printer prints the label of a dish, the print type of the
        // product (e.g. "number") would replace the product name.
        $dish = $this->dish(['without' => [], CustomFieldsInstaller::SHOPBITE_RECEIPT_PRINT_TYPE => 'number']);
        $cart = $this->cart($dish);

        $this->collect($cart, $this->pizza(customFields: [CustomFieldsInstaller::SHOPBITE_RECEIPT_PRINT_TYPE => 'number']));

        $this->assertArrayNotHasKey(CustomFieldsInstaller::SHOPBITE_RECEIPT_PRINT_TYPE, $dish->getPayload());
    }

    public function testPayloadKeysFromTheRequestAreRemoved(): void
    {
        $dish = $this->dish(['without' => [], 'productNumber' => 'SW-FAKE', 'other' => 'value']);
        $cart = $this->cart($dish);

        $this->collect($cart, $this->pizza());

        $this->assertSame('Pizza Margherita +Salami +Oliven', $dish->getLabel());
        $this->assertSame(['productNumber' => 'SW-10001', 'without' => []], $dish->getPayload());
    }

    public function testIngredientsToLeaveOutAreReadFromTheStorefrontLabel(): void
    {
        $dish = $this->dish([]);
        $dish->setLabel('Pizza Margherita +Salami +Oliven -Zwiebeln -Unbekannt -Rote Paprika');
        $cart = $this->cart($dish);

        $this->collect($cart, $this->pizza());

        $this->assertSame('Pizza Margherita +Salami +Oliven -Zwiebeln -Rote Paprika', $dish->getLabel());
        $this->assertSame(['Zwiebeln', 'Rote Paprika'], $dish->getPayloadValue('without'));
    }

    public function testNamesWithDashesAreReadAsAWhole(): void
    {
        $dish = $this->dish([], mainLabel: 'Pizza - Spezial', extras: [self::SALAMI_ID => 'Käse - extra']);
        $dish->setLabel('Pizza - Spezial +Käse - extra -Tomaten - frisch -Zwiebeln');
        $cart = $this->cart($dish);

        $this->collect($cart, $this->pizza('Pizza - Spezial', ['Tomaten - frisch', 'Tomaten', 'Zwiebeln', 'frisch']));

        $this->assertSame(['Tomaten - frisch', 'Zwiebeln'], $dish->getPayloadValue('without'));
        $this->assertSame('Pizza - Spezial +Käse - extra -Tomaten - frisch -Zwiebeln', $dish->getLabel());
    }

    public function testExtraWhoseNameLooksLikeARemovalIsNotOne(): void
    {
        $dish = $this->dish([], extras: [self::SALAMI_ID => 'Extra -Zwiebeln']);
        $dish->setLabel('Pizza Margherita +Extra -Zwiebeln');
        $cart = $this->cart($dish);

        $this->collect($cart, $this->pizza());

        $this->assertSame([], $dish->getPayloadValue('without'));
    }

    public function testCartFromBeforeTheUpdateKeepsTheRemovedIngredients(): void
    {
        // Stored before the dish had a referenced id and a `without` list: only the label knows.
        $dish = $this->dish(['productNumber' => 'SW-10001']);
        $dish->setReferencedId(null);
        $dish->setLabel('Pizza Margherita +Salami +Oliven -Käse');
        $cart = $this->cart($dish);

        $this->collect($cart, $this->pizza(ingredients: ['Käse', 'Tomatensoße']));

        $this->assertSame(self::PIZZA_ID, $dish->getReferencedId());
        $this->assertSame('Pizza Margherita +Salami +Oliven -Käse', $dish->getLabel());
        $this->assertSame(['productNumber' => 'SW-10001', 'without' => ['Käse']], $dish->getPayload());
    }

    public function testMissingProductsAreLoaded(): void
    {
        // Shopware's product collector does not reload products it already calculated, e.g. on
        // a quantity or payload update; the dish must still be checked.
        $dish = $this->dish(['without' => ['Zwiebeln', 'Unbekannt']]);
        $cart = $this->cart($dish);

        $repository = $this->createMock(SalesChannelRepository::class);
        $repository->expects($this->once())->method('search')
            ->with($this->callback(static fn (Criteria $criteria): bool => $criteria->getIds() === [self::PIZZA_ID]
                && $criteria->hasAssociation('properties')))
            ->willReturn($this->searchResult([$this->pizza()]));

        new DishLineItemCollector($repository)->collect(new CartDataCollection(), $cart, $this->createStub(SalesChannelContext::class), new CartBehavior());

        $this->assertSame('Pizza Margherita +Salami +Oliven -Zwiebeln', $dish->getLabel());
        $this->assertSame(['productNumber' => 'SW-10001', 'without' => ['Zwiebeln']], $dish->getPayload());
    }

    public function testDishWithoutItsProductIsRemoved(): void
    {
        $dish = $this->dish(['without' => []]);
        $dish->getChildren()->remove('main');
        $cart = $this->cart($dish);

        $this->collect($cart, $this->pizza());

        $this->assertCount(0, $cart->getLineItems());
    }

    public function testDishIsLeftAloneWhenTheProductCannotBeLoaded(): void
    {
        $dish = $this->dish(['without' => ['Zwiebeln']]);
        $dish->setLabel('Pizza Margherita -Zwiebeln');
        $cart = $this->cart($dish);

        $repository = $this->createStub(SalesChannelRepository::class);
        $repository->method('search')->willReturn($this->searchResult([]));
        new DishLineItemCollector($repository)->collect(new CartDataCollection(), $cart, $this->createStub(SalesChannelContext::class), new CartBehavior());

        $this->assertCount(1, $cart->getLineItems());
        $this->assertSame('Pizza Margherita -Zwiebeln', $dish->getLabel());
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
     * @param array<string, mixed>       $payload
     * @param array<string, string>|null $extras  product id => label
     */
    private function dish(array $payload, string $mainLabel = 'Pizza Margherita', ?array $extras = null): LineItem
    {
        $dish = new LineItem('dish', 'container', self::PIZZA_ID);
        $dish->setStackable(true);
        $dish->setPayload($payload);

        $main = new LineItem('main', LineItem::PRODUCT_LINE_ITEM_TYPE, self::PIZZA_ID);
        $main->setLabel($mainLabel);
        $children = [$main];
        foreach ($extras ?? [self::SALAMI_ID => 'Salami', self::OLIVES_ID => 'Oliven'] as $id => $label) {
            $extra = new LineItem($id, LineItem::PRODUCT_LINE_ITEM_TYPE, $id);
            $extra->setLabel($label);
            $children[] = $extra;
        }
        $dish->setChildren(new LineItemCollection($children));

        return $dish;
    }

    private function cart(LineItem $dish): Cart
    {
        $cart = new Cart('token');
        $cart->add($dish);

        return $cart;
    }

    /**
     * @param list<string>         $ingredients options of the group "Hauptzutaten"
     * @param array<string, mixed> $customFields
     */
    private function pizza(
        string $name = 'Pizza Margherita',
        array $ingredients = ['Zwiebeln', 'Rote Paprika'],
        array $customFields = [],
    ): SalesChannelProductEntity {
        $product = new SalesChannelProductEntity();
        $product->setId(self::PIZZA_ID);
        $product->setProductNumber('SW-10001');
        $product->setName($name);
        $product->setTranslated(['name' => $name, 'customFields' => $customFields]);
        $product->setCustomFields($customFields);

        $group = new PropertyGroupEntity();
        $group->setId('group-ingredients');
        $group->setName('Hauptzutaten');
        $group->setTranslated(['name' => 'Hauptzutaten']);

        $allergens = new PropertyGroupEntity();
        $allergens->setId('group-allergens');
        $allergens->setName('Allergene');
        $allergens->setTranslated(['name' => 'Allergene']);

        $options = [$this->option('allergen', 'Unbekannt', $allergens)];
        foreach ($ingredients as $index => $ingredient) {
            $options[] = $this->option('o' . $index, $ingredient, $group);
        }
        $product->setProperties(new PropertyGroupOptionCollection($options));

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

    /**
     * @param list<SalesChannelProductEntity> $products
     *
     * @return EntitySearchResult<ProductCollection>
     */
    private function searchResult(array $products): EntitySearchResult
    {
        $result = $this->createStub(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new ProductCollection($products));

        return $result;
    }

    private function collect(Cart $cart, SalesChannelProductEntity $product): void
    {
        $data = new CartDataCollection();
        $data->set('product-' . $product->getId(), $product);

        $repository = $this->createMock(SalesChannelRepository::class);
        $repository->expects($this->never())->method('search');

        new DishLineItemCollector($repository)->collect(
            $data,
            $cart,
            $this->createStub(SalesChannelContext::class),
            new CartBehavior(),
        );
    }
}
