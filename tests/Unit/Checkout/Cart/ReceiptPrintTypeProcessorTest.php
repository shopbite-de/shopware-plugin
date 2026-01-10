<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Checkout\Cart;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ShopBite\Checkout\Cart\ReceiptPrintTypeProcessor;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

#[CoversClass(ReceiptPrintTypeProcessor::class)]
class ReceiptPrintTypeProcessorTest extends TestCase
{
    public function testCollect(): void
    {
        $productId = 'product-id';
        $lineItem = new LineItem($productId, LineItem::PRODUCT_LINE_ITEM_TYPE, $productId);

        $cart = new Cart('test');
        $cart->addLineItems(new LineItemCollection([$lineItem]));

        $product = new ProductEntity();
        $product->setId($productId);

        $repository = $this->createMock(EntityRepository::class);
        $result = $this->createMock(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new ProductCollection([$product]));

        $repository->expects($this->once())
            ->method('search')
            ->with($this->callback(function (Criteria $criteria) use ($productId) {
                return in_array($productId, $criteria->getIds(), true);
            }))
            ->willReturn($result);

        $processor = new ReceiptPrintTypeProcessor($repository);
        $data = new CartDataCollection();
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        $processor->collect($data, $cart, $context, new CartBehavior());

        $this->assertTrue($data->has('shopbite-products' . $productId));
        $this->assertSame($product, $data->get('shopbite-products' . $productId));
    }

    public function testCollectWithContainer(): void
    {
        $productId = 'product-id';
        $childItem = new LineItem($productId, LineItem::PRODUCT_LINE_ITEM_TYPE, $productId);

        $containerItem = new LineItem('container', 'container');
        $containerItem->setChildren(new LineItemCollection([$childItem]));

        $cart = new Cart('test');
        $cart->addLineItems(new LineItemCollection([$containerItem]));

        $product = new ProductEntity();
        $product->setId($productId);

        $repository = $this->createMock(EntityRepository::class);
        $result = $this->createMock(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new ProductCollection([$product]));

        $repository->expects($this->once())
            ->method('search')
            ->with($this->callback(function (Criteria $criteria) use ($productId) {
                return in_array($productId, $criteria->getIds(), true);
            }))
            ->willReturn($result);

        $processor = new ReceiptPrintTypeProcessor($repository);
        $data = new CartDataCollection();
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        $processor->collect($data, $cart, $context, new CartBehavior());

        $this->assertTrue($data->has('shopbite-products' . $productId));
        $this->assertSame($product, $data->get('shopbite-products' . $productId));
    }

    public function testProcess(): void
    {
        $productId = 'product-id';
        $lineItem = new LineItem($productId, LineItem::PRODUCT_LINE_ITEM_TYPE, $productId);

        $cart = new Cart('test');
        $cart->addLineItems(new LineItemCollection([$lineItem]));

        $product = new ProductEntity();
        $product->setId($productId);
        $product->setCustomFields(['shopbite_receipt_print_type' => 'number']);

        $repository = $this->createMock(EntityRepository::class);
        $processor = new ReceiptPrintTypeProcessor($repository);

        $data = new CartDataCollection();
        $data->set('shopbite-products' . $productId, $product);

        $context = $this->createMock(SalesChannelContext::class);

        $processor->process($data, $cart, $cart, $context, new CartBehavior());

        $this->assertSame('number', $lineItem->getPayloadValue('shopbite_receipt_print_type'));
    }

    public function testProcessWithContainer(): void
    {
        $productId = 'product-id';
        $childItem = new LineItem($productId, LineItem::PRODUCT_LINE_ITEM_TYPE, $productId);

        $containerItem = new LineItem('container', 'container');
        $containerItem->setChildren(new LineItemCollection([$childItem]));

        $cart = new Cart('test');
        $cart->addLineItems(new LineItemCollection([$containerItem]));

        $product = new ProductEntity();
        $product->setId($productId);
        $product->setCustomFields(['shopbite_receipt_print_type' => 'number']);

        $repository = $this->createMock(EntityRepository::class);
        $processor = new ReceiptPrintTypeProcessor($repository);

        $data = new CartDataCollection();
        $data->set('shopbite-products' . $productId, $product);

        $context = $this->createMock(SalesChannelContext::class);

        $processor->process($data, $cart, $cart, $context, new CartBehavior());

        $this->assertSame('number', $childItem->getPayloadValue('shopbite_receipt_print_type'));
    }
}
