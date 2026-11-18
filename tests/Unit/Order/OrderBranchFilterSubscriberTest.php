<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Order;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShopBite\Order\OrderBranchFilterSubscriber;
use ShopBite\Staff\BranchBinding;
use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Checkout\Document\DocumentDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Event\BeforeEntityAggregationEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntitySearchedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;

#[CoversClass(OrderBranchFilterSubscriber::class)]
class OrderBranchFilterSubscriberTest extends TestCase
{
    private const string USER = '0190000000007000800000000000a001';
    private const string BRANCH = '0190000000007000800000000000b001';

    public function testListensToSearchesAndAggregations(): void
    {
        $this->assertSame(
            [EntitySearchedEvent::class, BeforeEntityAggregationEvent::class],
            array_keys(OrderBranchFilterSubscriber::getSubscribedEvents()),
        );
    }

    /**
     * @return iterable<string, array{class-string<EntityDefinition>, string}>
     */
    public static function orderEntities(): iterable
    {
        yield 'order' => [OrderDefinition::class, 'salesChannelId'];
        yield 'line item' => [OrderLineItemDefinition::class, 'order.salesChannelId'];
        yield 'delivery' => [OrderDeliveryDefinition::class, 'order.salesChannelId'];
        yield 'transaction' => [OrderTransactionDefinition::class, 'order.salesChannelId'];
        yield 'customer' => [OrderCustomerDefinition::class, 'order.salesChannelId'];
        yield 'address' => [OrderAddressDefinition::class, 'order.salesChannelId'];
        yield 'document' => [DocumentDefinition::class, 'order.salesChannelId'];
    }

    /**
     * @param class-string<EntityDefinition> $definition
     */
    #[DataProvider('orderEntities')]
    public function testBoundUserSeesOnlyOrdersOfTheirBranches(string $definition, string $field): void
    {
        $criteria = new Criteria();

        $this->subscriber([self::BRANCH])->onSearch(
            new EntitySearchedEvent($criteria, new $definition(), $this->userContext()),
        );

        $filters = $criteria->getFilters();
        $this->assertCount(1, $filters);
        $this->assertInstanceOf(EqualsAnyFilter::class, $filters[0]);
        $this->assertSame($field, $filters[0]->getField());
        $this->assertSame([self::BRANCH], $filters[0]->getValue());
    }

    public function testAggregationsAreFilteredToo(): void
    {
        $criteria = new Criteria();

        $this->subscriber([self::BRANCH])->onSearch(
            new BeforeEntityAggregationEvent($criteria, new OrderDefinition(), $this->userContext()),
        );

        $this->assertCount(1, $criteria->getFilters());
    }

    public function testOtherEntitiesAreNotFiltered(): void
    {
        $criteria = new Criteria();

        $this->subscriber([self::BRANCH], expectLookup: false)->onSearch(
            new EntitySearchedEvent($criteria, new CustomerDefinition(), $this->userContext()),
        );

        $this->assertSame([], $criteria->getFilters());
    }

    public function testUnboundUserIsNotFiltered(): void
    {
        $criteria = new Criteria();

        $this->subscriber(null)->onSearch(new EntitySearchedEvent($criteria, new OrderDefinition(), $this->userContext()));

        $this->assertSame([], $criteria->getFilters());
    }

    public function testSystemScopeIsNotFiltered(): void
    {
        $criteria = new Criteria();
        $context = $this->userContext();

        $context->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($criteria): void {
            $this->subscriber([self::BRANCH], expectLookup: false)->onSearch(
                new EntitySearchedEvent($criteria, new OrderDefinition(), $context),
            );
        });

        $this->assertSame([], $criteria->getFilters());
    }

    private function userContext(): Context
    {
        return new Context(new AdminApiSource(self::USER));
    }

    /**
     * @param list<string>|null $branches
     */
    private function subscriber(?array $branches, bool $expectLookup = true): OrderBranchFilterSubscriber
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($expectLookup ? $this->once() : $this->never())
            ->method('fetchOne')
            ->willReturn($branches === null ? null : json_encode(['shopbite_sales_channel_ids' => $branches]));

        return new OrderBranchFilterSubscriber(new BranchBinding($connection));
    }
}
