<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Order;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ShopBite\Order\BranchAccessException;
use ShopBite\Order\OrderBranchRouteSubscriber;
use ShopBite\Order\OrderBranchWriteSubscriber;
use ShopBite\Order\OrderSalesChannelResolver;
use ShopBite\Staff\BranchBinding;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWriteEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\DeleteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteContext;
use Shopware\Core\Framework\Routing\KernelListenerPriorities;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

#[CoversClass(OrderBranchWriteSubscriber::class)]
#[CoversClass(OrderBranchRouteSubscriber::class)]
#[CoversClass(OrderSalesChannelResolver::class)]
class OrderBranchWriteSubscriberTest extends TestCase
{
    private const string USER = '0190000000007000800000000000a001';
    private const string OWN = '0190000000007000800000000000b001';
    private const string OTHER = '0190000000007000800000000000b002';
    private const string OWN_ORDER = '0190000000007000800000000000c001';
    private const string OTHER_ORDER = '0190000000007000800000000000c002';
    private const string OWN_ITEM = '0190000000007000800000000000d001';
    private const string OTHER_ITEM = '0190000000007000800000000000d002';
    private const string OTHER_DELIVERY = '0190000000007000800000000000e002';

    public function testUpdateOfAnOwnOrderPasses(): void
    {
        $this->write([$this->command(UpdateCommand::class, 'order', ['id' => self::OWN_ORDER], ['custom_fields' => '{}'])]);
        $this->addToAssertionCount(1);
    }

    public function testUpdateOfAnotherBranchesOrderIsRefused(): void
    {
        $this->expectException(BranchAccessException::class);

        $this->write([$this->command(UpdateCommand::class, 'order', ['id' => self::OTHER_ORDER], ['custom_fields' => '{}'])]);
    }

    public function testMovingAnOrderToAnotherBranchIsRefused(): void
    {
        $this->expectException(BranchAccessException::class);

        $this->write([$this->command(UpdateCommand::class, 'order', ['id' => self::OWN_ORDER], ['sales_channel_id' => Uuid::fromHexToBytes(self::OTHER)])]);
    }

    public function testNewOrderForAnotherBranchIsRefused(): void
    {
        $this->expectException(BranchAccessException::class);

        $this->write([$this->command(InsertCommand::class, 'order', ['id' => '0190000000007000800000000000c009'], ['sales_channel_id' => Uuid::fromHexToBytes(self::OTHER)])]);
    }

    public function testLineItemOfAnotherBranchesOrderIsRefused(): void
    {
        $this->expectException(BranchAccessException::class);

        $this->write([$this->command(UpdateCommand::class, 'order_line_item', ['id' => self::OTHER_ITEM], ['label' => 'x'])]);
    }

    public function testNewLineItemInAnotherBranchesOrderIsRefused(): void
    {
        $this->expectException(BranchAccessException::class);

        $this->write([$this->command(InsertCommand::class, 'order_line_item', ['id' => '0190000000007000800000000000d009'], ['order_id' => Uuid::fromHexToBytes(self::OTHER_ORDER)])]);
    }

    public function testMovingAnOwnLineItemToAnotherOrderIsRefused(): void
    {
        $this->expectException(BranchAccessException::class);

        $this->write([$this->command(UpdateCommand::class, 'order_line_item', ['id' => self::OWN_ITEM], ['order_id' => Uuid::fromHexToBytes(self::OTHER_ORDER)])]);
    }

    public function testDeletingADeliveryPositionOfAnotherBranchIsRefused(): void
    {
        $this->expectException(BranchAccessException::class);

        $this->write([$this->command(DeleteCommand::class, 'order_delivery_position', ['id' => '0190000000007000800000000000f002'], [])]);
    }

    public function testOrderTagOfAnotherBranchIsRefused(): void
    {
        $this->expectException(BranchAccessException::class);

        $this->write([$this->command(InsertCommand::class, 'order_tag', ['order_id' => self::OTHER_ORDER, 'tag_id' => self::USER], [])]);
    }

    public function testOtherEntitiesAndUnboundUsersAreNotChecked(): void
    {
        $this->write([$this->command(UpdateCommand::class, 'product', ['id' => self::OTHER_ORDER], ['active' => 0])], expectLookup: false);
        $this->write([$this->command(UpdateCommand::class, 'order', ['id' => self::OTHER_ORDER], ['custom_fields' => '{}'])], binding: null);
        $this->addToAssertionCount(1);
    }

    public function testSystemScopeIsNotChecked(): void
    {
        $context = $this->context();
        $context->scope(Context::SYSTEM_SCOPE, function (Context $context): void {
            $this->write([$this->command(UpdateCommand::class, 'order', ['id' => self::OTHER_ORDER], [])], context: $context, expectLookup: false);
        });
        $this->addToAssertionCount(1);
    }

    public function testStateTransitionOfAnotherBranchesOrderIsRefused(): void
    {
        $this->expectException(BranchAccessException::class);

        $this->route(['orderId' => self::OTHER_ORDER, 'transition' => 'cancel']);
    }

    public function testGenericStateTransitionOfAnotherBranchesDeliveryIsRefused(): void
    {
        $this->expectException(BranchAccessException::class);

        $this->route(['entityName' => 'order_delivery', 'entityId' => self::OTHER_DELIVERY, 'transition' => 'ship']);
    }

    public function testNewOrderWithItsPartsInOneWritePasses(): void
    {
        $order = '0190000000007000800000000000c00a';
        $delivery = '0190000000007000800000000000e00a';
        $this->write([
            $this->command(InsertCommand::class, 'order', ['id' => $order], ['sales_channel_id' => Uuid::fromHexToBytes(self::OWN)]),
            $this->command(InsertCommand::class, 'order_line_item', ['id' => '0190000000007000800000000000d00a'], ['order_id' => Uuid::fromHexToBytes($order)]),
            $this->command(InsertCommand::class, 'order_delivery', ['id' => $delivery], ['order_id' => Uuid::fromHexToBytes($order)]),
            $this->command(InsertCommand::class, 'order_delivery_position', ['id' => '0190000000007000800000000000f00a'], ['order_delivery_id' => Uuid::fromHexToBytes($delivery)]),
        ]);
        $this->addToAssertionCount(1);
    }

    public function testNewOrderOfAnotherBranchWithItsPartsIsRefused(): void
    {
        $order = '0190000000007000800000000000c00b';
        $this->expectException(BranchAccessException::class);

        $this->write([
            $this->command(InsertCommand::class, 'order_line_item', ['id' => '0190000000007000800000000000d00b'], ['order_id' => Uuid::fromHexToBytes($order)]),
            $this->command(InsertCommand::class, 'order', ['id' => $order], ['sales_channel_id' => Uuid::fromHexToBytes(self::OTHER)]),
        ]);
    }

    public function testRouteCheckRunsAfterTheContextIsResolved(): void
    {
        // Shopware sets the request's context in ContextResolverListener at this priority.
        $events = OrderBranchRouteSubscriber::getSubscribedEvents();
        $listener = $events[KernelEvents::CONTROLLER];
        static::assertIsArray($listener);
        static::assertSame('onController', $listener[0]);
        static::assertLessThan(KernelListenerPriorities::KERNEL_CONTROLLER_EVENT_CONTEXT_RESOLVE, $listener[1]);
    }

    public function testOwnOrderRoutesAndOtherRoutesPass(): void
    {
        $this->route(['orderId' => self::OWN_ORDER, 'transition' => 'complete']);
        $this->route(['entityName' => 'product', 'entityId' => self::OTHER_ORDER]);
        $this->route(['orderId' => self::OTHER_ORDER], path: '/store-api/order');
        $this->addToAssertionCount(1);
    }

    public function testUnknownOrderOnARouteIsRefused(): void
    {
        $this->expectException(BranchAccessException::class);

        $this->route(['orderId' => '0190000000007000800000000000c0ff']);
    }

    /**
     * @param list<WriteCommand> $commands
     * @param list<string>|null  $binding
     */
    private function write(array $commands, ?array $binding = [self::OWN], ?Context $context = null, bool $expectLookup = true): void
    {
        $event = EntityWriteEvent::create(WriteContext::createFromContext($context ?? $this->context()), $commands);
        $this->writeSubscriber($binding, $expectLookup)->onWrite($event);
    }

    /**
     * @param list<string>|null $binding
     */
    private function writeSubscriber(?array $binding, bool $expectLookup = true): OrderBranchWriteSubscriber
    {
        $connection = $this->connection($binding, $expectLookup);

        return new OrderBranchWriteSubscriber(new BranchBinding($connection), new OrderSalesChannelResolver($connection));
    }

    /**
     * @param array<string, string> $params
     */
    private function route(array $params, string $path = '/api/_action/order/x/state/y'): void
    {
        $request = Request::create($path, 'POST');
        $request->attributes->set('_route_params', $params);
        $request->attributes->set(PlatformRequest::ATTRIBUTE_CONTEXT_OBJECT, $this->context());

        $connection = $this->connection([self::OWN], null);
        new OrderBranchRouteSubscriber(new BranchBinding($connection), new OrderSalesChannelResolver($connection))->onController(
            new ControllerEvent($this->createStub(HttpKernelInterface::class), static fn () => null, $request, HttpKernelInterface::MAIN_REQUEST),
        );
    }

    /**
     * Two branches; the user's own order with one line item, the other branch's order with a
     * line item and a delivery with a position.
     *
     * @param list<string>|null $binding
     * @param bool|null         $expectLookup null: any number of lookups
     */
    private function connection(?array $binding, ?bool $expectLookup = true): Connection
    {
        $rows = [
            'order:' . self::OWN_ORDER => self::OWN,
            'order:' . self::OTHER_ORDER => self::OTHER,
            'order_line_item:' . self::OWN_ITEM => Uuid::fromHexToBytes(self::OWN_ORDER),
            'order_line_item:' . self::OTHER_ITEM => Uuid::fromHexToBytes(self::OTHER_ORDER),
            'order_delivery:' . self::OTHER_DELIVERY => Uuid::fromHexToBytes(self::OTHER_ORDER),
            'order_delivery_position:0190000000007000800000000000f002' => Uuid::fromHexToBytes(self::OTHER_DELIVERY),
        ];
        $connection = $expectLookup === null ? $this->createStub(Connection::class) : $this->createMock(Connection::class);
        $method = $connection instanceof MockObject
            ? $connection->expects($expectLookup ? $this->atLeastOnce() : $this->never())->method('fetchOne')
            : $connection->method('fetchOne');
        $method->willReturnCallback(
            static function (string $sql, array $params) use ($rows, $binding): mixed {
                if (str_contains($sql, 'FROM `user`')) {
                    return $binding === null ? null : json_encode(['shopbite_sales_channel_ids' => $binding]);
                }
                preg_match('/FROM `([a-z_]+)`/', $sql, $match);

                return $rows[$match[1] . ':' . Uuid::fromBytesToHex($params['id'])] ?? false;
            },
        );

        return $connection;
    }

    private function context(): Context
    {
        return new Context(new AdminApiSource(self::USER));
    }

    /**
     * @param class-string<WriteCommand> $class
     * @param array<string, string>      $primaryKey hex ids
     * @param array<string, mixed>       $payload
     */
    private function command(string $class, string $entity, array $primaryKey, array $payload): WriteCommand
    {
        $command = $this->createStub($class);
        $command->method('getEntityName')->willReturn($entity);
        $command->method('getPrimaryKey')->willReturn(array_map(Uuid::fromHexToBytes(...), $primaryKey));
        $command->method('getPayload')->willReturn($payload);

        return $command;
    }
}
