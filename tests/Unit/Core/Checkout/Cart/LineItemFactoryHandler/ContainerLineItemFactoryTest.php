<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Core\Checkout\Cart\LineItemFactoryHandler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShopBite\Checkout\Cart\Dish\DishLineItemException;
use ShopBite\Checkout\Cart\Dish\DishText;
use ShopBite\Checkout\Cart\LineItemFactoryHandler\ContainerLineItemFactory;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

#[CoversClass(ContainerLineItemFactory::class)]
#[CoversClass(DishLineItemException::class)]
#[CoversClass(DishText::class)]
class ContainerLineItemFactoryTest extends TestCase
{
    private const string PIZZA_ID = '0190a1b2c3d4e5f60718293a4b5c6d7e';
    private const string SALAMI_ID = '0190a1b2c3d4e5f60718293a4b5c6d7f';
    private const string MAIN_CHILD_ID = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private ContainerLineItemFactory $factory;

    private SalesChannelContext $context;

    protected function setUp(): void
    {
        $this->factory = new ContainerLineItemFactory();
        $this->context = $this->createStub(SalesChannelContext::class);
    }

    public function testSupports(): void
    {
        $this->assertTrue($this->factory->supports('container'));
        $this->assertFalse($this->factory->supports('product'));
    }

    public function testCreateFromStorefrontRequest(): void
    {
        $lineItem = $this->factory->create($this->storefrontRequest(quantity: 2), $this->context);

        $this->assertSame('5f0c1e4b-6f43-5b3c-9a3f-0c1d2e3f4a5b', $lineItem->getId());
        $this->assertSame('container', $lineItem->getType());
        $this->assertSame(self::PIZZA_ID, $lineItem->getReferencedId());
        $this->assertSame(2, $lineItem->getQuantity());
        $this->assertTrue($lineItem->isRemovable());
        $this->assertTrue($lineItem->isStackable());

        $children = $lineItem->getChildren();
        $this->assertCount(2, $children);

        $main = $children->get(self::MAIN_CHILD_ID);
        $this->assertNotNull($main);
        $this->assertSame('product', $main->getType());
        $this->assertSame(self::PIZZA_ID, $main->getReferencedId());
        $this->assertSame(2, $main->getQuantity());

        $extra = $children->get(self::SALAMI_ID);
        $this->assertNotNull($extra);
        $this->assertSame(self::SALAMI_ID, $extra->getReferencedId());
        $this->assertSame(2, $extra->getQuantity());
    }

    public function testChildQuantityFollowsTheDish(): void
    {
        $data = $this->storefrontRequest(quantity: 3);
        $data['children'] = [['id' => self::PIZZA_ID, 'quantity' => 1]];

        $lineItem = $this->factory->create($data, $this->context);

        $this->assertSame(3, $lineItem->getChildren()->get(self::PIZZA_ID)?->getQuantity());
    }

    public function testLabelIsKeptOnlyAsCleanedHint(): void
    {
        // DishLineItemCollector replaces it; until then it carries the storefront's "-ingredient" parts.
        $lineItem = $this->factory->create($this->storefrontRequest(label: "Pizza\u{202E} +Salami\n -Käse"), $this->context);

        $this->assertSame('Pizza +Salami -Käse', $lineItem->getLabel());
        $this->assertSame([], $lineItem->getPayload());
    }

    public function testPayloadKeepsOnlyTheIngredientsToLeaveOut(): void
    {
        $data = $this->storefrontRequest();
        $data['payload'] = [
            'productNumber' => 'SW-FAKE',
            'shopbite_receipt_print_type' => 'label',
            'without' => ['Zwiebeln'],
            'other' => 'value',
        ];

        $lineItem = $this->factory->create($data, $this->context);

        $this->assertSame(['without' => ['Zwiebeln']], $lineItem->getPayload());
    }

    public function testIngredientTextIsCleanedAndDeduplicated(): void
    {
        $data = $this->storefrontRequest();
        $data['payload'] = ['without' => [" Zwie\nbeln\t", "Zwie\u{202E}beln\u{2028}", "Zwie\u{200B}beln", 'Zwiebeln', "\x07", '']];

        $lineItem = $this->factory->create($data, $this->context);

        $this->assertSame(['without' => ['Zwiebeln']], $lineItem->getPayload());
    }

    public function testLabelHintIsCapped(): void
    {
        $lineItem = $this->factory->create($this->storefrontRequest(label: str_repeat('x', 2000)), $this->context);

        $this->assertSame(DishText::MAX_LABEL_LENGTH, mb_strlen((string) $lineItem->getLabel()));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidWithoutProvider(): iterable
    {
        yield 'not a list' => [['a' => 'Zwiebeln']];
        yield 'a string' => ['Zwiebeln'];
        yield 'no string entry' => [[['Zwiebeln']]];
        yield 'too many entries' => [array_map(static fn (int $i): string => 'Zutat' . $i, range(1, 21))];
        yield 'too long entry' => [[str_repeat('x', 101)]];
    }

    #[DataProvider('invalidWithoutProvider')]
    public function testInvalidIngredientListIsRejected(mixed $without): void
    {
        $data = $this->storefrontRequest();
        $data['payload'] = ['without' => $without];

        $this->expectException(DishLineItemException::class);

        $this->factory->create($data, $this->context);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidChildrenProvider(): iterable
    {
        $main = ['id' => self::MAIN_CHILD_ID, 'type' => 'product', 'referencedId' => self::PIZZA_ID, 'quantity' => 1];

        yield 'no children' => [['children' => []]];
        yield 'children no list' => [['children' => 'x']];
        yield 'child no array' => [['children' => ['x']]];
        yield 'child of another type' => [['children' => [['type' => 'credit'] + $main]]];
        yield 'child without id' => [['children' => [['id' => null] + $main]]];
        yield 'child id with odd characters' => [['children' => [['id' => 'a b'] + $main]]];
        yield 'referenced id no uuid' => [['children' => [['referencedId' => 'not-a-uuid'] + $main]]];
        yield 'id no uuid without referenced id' => [['children' => [['id' => 'child-1', 'quantity' => 1]]]];
        yield 'quantity zero' => [['children' => [['quantity' => 0] + $main]]];
        yield 'quantity no int' => [['children' => [['quantity' => '1'] + $main]]];
        yield 'duplicate child' => [['children' => [$main, $main]]];
        yield 'too many children' => [['children' => array_merge([$main], array_map(
            static fn (int $i): array => ['id' => \sprintf('%032x', $i), 'quantity' => 1],
            range(1, 30),
        ))]];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('invalidChildrenProvider')]
    public function testInvalidChildrenAreRejected(array $override): void
    {
        $this->expectException(DishLineItemException::class);

        $this->factory->create($override + $this->storefrontRequest(), $this->context);
    }

    public function testThirtyChildrenAreAccepted(): void
    {
        $data = $this->storefrontRequest();
        $data['children'] = array_map(
            static fn (int $i): array => ['id' => \sprintf('%032x', $i), 'quantity' => 1],
            range(1, 30),
        );

        $this->assertCount(30, $this->factory->create($data, $this->context)->getChildren());
    }

    public function testInvalidQuantityIsRejected(): void
    {
        $this->expectException(DishLineItemException::class);

        $this->factory->create(['quantity' => 0] + $this->storefrontRequest(), $this->context);
    }

    public function testUpdateChangesQuantityAndKeepsChildren(): void
    {
        $lineItem = $this->factory->create($this->storefrontRequest(), $this->context);

        $this->factory->update($lineItem, ['id' => $lineItem->getId(), 'quantity' => 5], $this->context);

        $this->assertSame(5, $lineItem->getQuantity());
        $this->assertCount(2, $lineItem->getChildren());
        foreach ($lineItem->getChildren() as $child) {
            $this->assertSame(5, $child->getQuantity());
        }
    }

    public function testUpdateWhitelistsThePayload(): void
    {
        $lineItem = new LineItem('container-id', 'container', self::PIZZA_ID);
        $lineItem->setStackable(true);
        $lineItem->setChildren(new LineItemCollection([new LineItem(self::MAIN_CHILD_ID, 'product', self::PIZZA_ID)]));

        $this->factory->update($lineItem, [
            'label' => 'Fake',
            'payload' => ['productNumber' => 'SW-FAKE', 'without' => ['Zwiebeln']],
        ], $this->context);

        $this->assertSame('Fake', $lineItem->getLabel());
        $this->assertSame(['without' => ['Zwiebeln']], $lineItem->getPayload());
    }

    public function testLabelOnlyUpdateDropsTheOldIngredientList(): void
    {
        $lineItem = $this->factory->create($this->storefrontRequest(), $this->context);
        $lineItem->setPayloadValue('without', ['Zwiebeln']);

        $this->factory->update($lineItem, ['label' => 'Pizza Margherita -Käse'], $this->context);

        // the collector reads the new label instead of the old list
        $this->assertSame('Pizza Margherita -Käse', $lineItem->getLabel());
        $this->assertArrayNotHasKey('without', $lineItem->getPayload());
    }

    public function testUpdateValidatesNewChildren(): void
    {
        $lineItem = $this->factory->create($this->storefrontRequest(), $this->context);

        $this->expectException(DishLineItemException::class);

        $this->factory->update($lineItem, ['children' => [['id' => 'x', 'type' => 'custom']]], $this->context);
    }

    /**
     * What the storefront posts for a dish with extras or left-out ingredients.
     *
     * @return array<string, mixed>
     */
    private function storefrontRequest(int $quantity = 1, string $label = 'Pizza Margherita +Salami'): array
    {
        return [
            'id' => '5f0c1e4b-6f43-5b3c-9a3f-0c1d2e3f4a5b',
            'quantity' => $quantity,
            'type' => 'container',
            'label' => $label,
            'payload' => ['productNumber' => 'SW-10001'],
            'children' => [
                ['id' => self::MAIN_CHILD_ID, 'quantity' => $quantity, 'type' => 'product', 'referencedId' => self::PIZZA_ID],
                ['id' => self::SALAMI_ID, 'type' => 'product', 'quantity' => $quantity],
            ],
        ];
    }
}
