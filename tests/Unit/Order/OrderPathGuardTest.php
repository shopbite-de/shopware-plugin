<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Order;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShopBite\Order\OrderFamily;
use ShopBite\Order\OrderPathGuard;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\TermsAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\SumAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Query\ScoreQuery;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

#[CoversClass(OrderPathGuard::class)]
#[CoversClass(OrderFamily::class)]
class OrderPathGuardTest extends TestCase
{
    use OrderDefinitions;

    /**
     * @return iterable<string, array{string, \Closure(): Criteria, string}>
     */
    public static function forbidden(): iterable
    {
        yield 'sales channel with its orders' => ['sales_channel', static fn (): Criteria => new Criteria()->addAssociation('orders'), 'orders'];
        yield 'nested below the orders' => ['sales_channel', static fn (): Criteria => new Criteria()->addAssociation('orders.lineItems'), 'orders'];
        yield 'order to the orders of its sales channel' => ['order', static fn (): Criteria => new Criteria()->addAssociation('salesChannel.orders'), 'salesChannel.orders'];
        yield 'order to the orders of its customer' => ['order', static fn (): Criteria => new Criteria()->addAssociation('orderCustomer.customer.orderCustomers'), 'orderCustomer.customer.orderCustomers'];
        yield 'line item to other line items of its product' => ['order_line_item', static fn (): Criteria => new Criteria()->addAssociation('product.orderLineItems'), 'product.orderLineItems'];
        yield 'state to its orders' => ['state_machine_state', static fn (): Criteria => new Criteria()->addAssociation('orders'), 'orders'];
        yield 'filter as oracle' => ['sales_channel', static fn (): Criteria => new Criteria()->addFilter(new EqualsFilter('orders.orderNumber', '10001')), 'orders.orderNumber'];
        yield 'filter with entity prefix' => ['sales_channel', static fn (): Criteria => new Criteria()->addFilter(new EqualsFilter('sales_channel.orders.id', 'x')), 'sales_channel.orders.id'];
        yield 'post filter' => ['sales_channel', static fn (): Criteria => new Criteria()->addPostFilter(new EqualsFilter('orders.id', 'x')), 'orders.id'];
        yield 'sorting' => ['product', static fn (): Criteria => new Criteria()->addSorting(new FieldSorting('orderLineItems.quantity')), 'orderLineItems.quantity'];
        yield 'aggregation' => ['sales_channel', static fn (): Criteria => new Criteria()->addAggregation(new SumAggregation('sum', 'orders.amountTotal')), 'orders.amountTotal'];
        yield 'nested aggregation' => ['sales_channel', static fn (): Criteria => new Criteria()->addAggregation(new TermsAggregation('t', 'id', null, null, new SumAggregation('sum', 'orders.amountTotal'))), 'orders.amountTotal'];
        yield 'query' => ['sales_channel', static fn (): Criteria => new Criteria()->addQuery(new ScoreQuery(new EqualsFilter('orders.id', 'x'), 10)), 'orders.id'];
        yield 'filter inside an association' => ['sales_channel', static fn (): Criteria => (static function (): Criteria {
            $criteria = new Criteria();
            $criteria->getAssociation('orders')->addFilter(new EqualsFilter('id', 'x'));

            return $criteria;
        })(), 'orders'];
        yield 'order filter through its sales channel' => ['order', static fn (): Criteria => new Criteria()->addFilter(new EqualsFilter('salesChannel.orders.id', 'x')), 'salesChannel.orders.id'];
    }

    /**
     * @param \Closure(): Criteria $criteria
     */
    #[DataProvider('forbidden')]
    public function testPathsIntoOtherOrdersAreFound(string $root, \Closure $criteria, string $path): void
    {
        $registry = self::registry($this);

        static::assertSame($path, OrderPathGuard::forbiddenPath($registry->getByEntityName($root), $criteria()));
    }

    /**
     * @return iterable<string, array{string, \Closure(): Criteria}>
     */
    public static function allowed(): iterable
    {
        // what the backoffice asks for
        yield 'order with its own parts' => ['order', static fn (): Criteria => new Criteria()
            ->addAssociation('stateMachineState')
            ->addAssociation('orderCustomer')
            ->addAssociation('lineItems')
            ->addFilter(new EqualsFilter('salesChannelId', 'x'))
            ->addSorting(new FieldSorting('orderDateTime'))];
        yield 'line item with its order' => ['order_line_item', static fn (): Criteria => new Criteria()->addAssociation('order.lineItems')];
        yield 'order with customer' => ['order', static fn (): Criteria => new Criteria()->addAssociation('orderCustomer.customer')];
        yield 'sales channel without orders' => ['sales_channel', static fn (): Criteria => new Criteria()->addFilter(new EqualsFilter('active', true))->addSorting(new FieldSorting('name'))];
        yield 'product' => ['product', static fn (): Criteria => new Criteria()->addFilter(new EqualsFilter('productNumber', 'x'))->addSorting(new FieldSorting('name'))];
        yield 'json field path' => ['order', static fn (): Criteria => new Criteria()->addFilter(new EqualsFilter('customFields.shopbite_printed_at', null))];
    }

    /**
     * @param \Closure(): Criteria $criteria
     */
    #[DataProvider('allowed')]
    public function testOwnPathsAreAllowed(string $root, \Closure $criteria): void
    {
        $registry = self::registry($this);

        static::assertNull(OrderPathGuard::forbiddenPath($registry->getByEntityName($root), $criteria()));
    }
}
