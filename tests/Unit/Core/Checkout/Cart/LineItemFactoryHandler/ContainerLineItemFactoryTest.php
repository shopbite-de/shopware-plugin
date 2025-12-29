<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Core\Checkout\Cart\LineItemFactoryHandler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ShopBite\Core\Checkout\Cart\LineItemFactoryHandler\ContainerLineItemFactory;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

#[CoversClass(ContainerLineItemFactory::class)]
class ContainerLineItemFactoryTest extends TestCase
{
    private ContainerLineItemFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new ContainerLineItemFactory();
    }

    public function testSupports(): void
    {
        $this->assertTrue($this->factory->supports('container'));
        $this->assertFalse($this->factory->supports('product'));
    }

    public function testCreate(): void
    {
        $context = $this->createMock(SalesChannelContext::class);
        $data = [
            'id' => 'container-id',
            'label' => 'Container Label',
            'payload' => ['foo' => 'bar'],
            'quantity' => 1,
            'children' => [
                ['id' => 'child-1', 'quantity' => 1],
                ['id' => 'child-2', 'referencedId' => 'ref-2', 'quantity' => 2],
            ],
        ];

        $lineItem = $this->factory->create($data, $context);

        $this->assertSame('container-id', $lineItem->getId());
        $this->assertSame('container', $lineItem->getType());
        $this->assertSame('Container Label', $lineItem->getLabel());
        $this->assertSame(['foo' => 'bar'], $lineItem->getPayload());
        $this->assertSame(1, $lineItem->getQuantity());
        $this->assertTrue($lineItem->isRemovable());
        $this->assertTrue($lineItem->isStackable());

        $children = $lineItem->getChildren();
        $this->assertCount(2, $children);

        $child1 = $children->get('child-1');
        $this->assertNotNull($child1);
        $this->assertSame('child-1', $child1->getId());
        $this->assertSame('product', $child1->getType());
        $this->assertSame(1, $child1->getQuantity());

        $child2 = $children->get('child-2');
        $this->assertNotNull($child2);
        $this->assertSame('child-2', $child2->getId());
        $this->assertSame('ref-2', $child2->getReferencedId());
        $this->assertSame(2, $child2->getQuantity());
    }

    public function testUpdate(): void
    {
        $context = $this->createMock(SalesChannelContext::class);
        $lineItem = new LineItem('container-id', 'container');
        $lineItem->setStackable(true);

        $data = [
            'payload' => ['updated' => true],
            'quantity' => 5,
        ];

        $this->factory->update($lineItem, $data, $context);

        $this->assertSame(['updated' => true], $lineItem->getPayload());
        $this->assertSame(5, $lineItem->getQuantity());
    }
}
