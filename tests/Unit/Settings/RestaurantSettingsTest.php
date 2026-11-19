<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Settings;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ShopBite\Fulfillment\ShippingMethodFulfillment;
use ShopBite\Fulfillment\ShippingMethodLeadTimes;
use ShopBite\Settings\Api\SettingsRoute;
use ShopBite\Settings\RestaurantSettings;
use ShopBite\Staff\BranchBinding;
use ShopBite\Tests\Unit\Fulfillment\ShippingMethodLeadTimesTest;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(RestaurantSettings::class)]
#[CoversClass(SettingsRoute::class)]
#[CoversClass(BranchBinding::class)]
#[CoversClass(ShippingMethodLeadTimes::class)]
#[CoversClass(ShippingMethodFulfillment::class)]
#[AllowMockObjectsWithoutExpectations]
class RestaurantSettingsTest extends TestCase
{
    private const string CHANNEL = '0190000000007000800000000000b001';

    private const string NAVIGATION = '211cf9b2d1725130a517bbadf753a43c';

    private const string MENU = '018dadb399837af3aa669c8fff4f4d6c';

    private const string OTHER_CHANNEL = '0190000000007000800000000000b002';

    private const string USER = '0190000000007000800000000000a001';

    private const array SHIPPING_METHODS = [
        ['id' => ShippingMethodLeadTimesTest::DELIVERY, 'name' => 'Lieferung', 'fulfillment' => 'delivery', 'leadTimeMinutes' => 45, 'leadTimeMinutesSet' => true, 'editable' => true],
        ['id' => ShippingMethodLeadTimesTest::PICKUP, 'name' => 'Abholung', 'fulfillment' => 'pickup', 'leadTimeMinutes' => 30, 'leadTimeMinutesSet' => false, 'editable' => true],
    ];

    private SystemConfigService&MockObject $systemConfig;

    private Connection&MockObject $connection;

    /** @var EntityRepository<ShippingMethodCollection>&MockObject */
    private EntityRepository&MockObject $shippingMethods;

    private Connection&MockObject $assignments;

    private SettingsRoute $route;

    private bool $inTransaction = false;

    protected function setUp(): void
    {
        $this->systemConfig = $this->createMock(SystemConfigService::class);
        $this->connection = $this->createMock(Connection::class);
        $this->shippingMethods = $this->createMock(EntityRepository::class);
        $this->shippingMethods->method('search')->willReturn(ShippingMethodLeadTimesTest::searchResult(ShippingMethodLeadTimesTest::methods()));
        $this->assignments = $this->createMock(Connection::class);
        $this->connection->method('transactional')->willReturnCallback(function (\Closure $writes): mixed {
            $this->inTransaction = true;
            try {
                return $writes($this->connection);
            } finally {
                $this->inTransaction = false;
            }
        });
        $this->route = new SettingsRoute(
            new RestaurantSettings($this->systemConfig),
            $this->connection,
            new BranchBinding($this->connection),
            new ShippingMethodLeadTimes($this->shippingMethods, $this->assignments),
        );
    }

    public function testReadsTheChannelValuesWithFallback(): void
    {
        $this->channelExists(true);
        $this->systemConfig->method('get')->willReturnMap([
            [RestaurantSettings::ORDERING_OPEN, self::CHANNEL, false],
            [RestaurantSettings::MENU_CATEGORY, self::CHANNEL, self::MENU],
        ]);

        $response = $this->route->read(self::CHANNEL, $this->admin());

        static::assertSame(
            ['orderingOpen' => false, 'menuCategoryId' => self::MENU, 'shippingMethods' => self::SHIPPING_METHODS],
            json_decode((string) $response->getContent(), true),
        );
    }

    public function testUnsetMeansOpen(): void
    {
        $this->systemConfig->method('get')->willReturn(null);

        static::assertSame(
            ['orderingOpen' => true, 'menuCategoryId' => self::NAVIGATION],
            new RestaurantSettings($this->systemConfig)->read(self::CHANNEL, self::NAVIGATION),
        );
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function orderingValues(): iterable
    {
        yield 'unset' => [null, true];
        yield 'true' => [true, true];
        yield 'false' => [false, false];
        yield 'string false (bin/console system:config:set)' => ['false', false];
        yield 'string 0' => ['0', false];
        yield 'int 0' => [0, false];
        yield 'string 1' => ['1', true];
        yield 'unknown value' => ['maybe', true];
    }

    #[DataProvider('orderingValues')]
    public function testOrderingOpenIsReadLikeTheStorefrontConfig(mixed $stored, bool $open): void
    {
        $this->systemConfig->method('get')->willReturnCallback(
            static fn (string $key): mixed => $key === RestaurantSettings::ORDERING_OPEN ? $stored : null,
        );

        static::assertSame($open, new RestaurantSettings($this->systemConfig)->read(self::CHANNEL, self::NAVIGATION)['orderingOpen']);
    }

    public function testListsEverySalesChannel(): void
    {
        $this->channelExists(true);
        $this->systemConfig->method('get')->willReturn(null);

        $body = json_decode((string) $this->route->list($this->admin())->getContent(), true);

        static::assertSame(['channels' => [self::CHANNEL => ['orderingOpen' => true, 'menuCategoryId' => self::NAVIGATION, 'shippingMethods' => self::SHIPPING_METHODS]]], $body);
    }

    public function testMenuCategoryFallsBackToTheNavigation(): void
    {
        static::assertSame(self::MENU, RestaurantSettings::menuCategoryId(self::MENU, self::NAVIGATION));
        static::assertSame(self::NAVIGATION, RestaurantSettings::menuCategoryId(null, self::NAVIGATION));
        static::assertSame(self::NAVIGATION, RestaurantSettings::menuCategoryId('', self::NAVIGATION));
        static::assertSame(self::NAVIGATION, RestaurantSettings::menuCategoryId('not-an-id', self::NAVIGATION));
    }

    public function testWritesOnlyTheGivenValuesForTheChannel(): void
    {
        $this->channelExists(true);
        $this->systemConfig->expects($this->once())
            ->method('set')
            ->with(RestaurantSettings::ORDERING_OPEN, false, self::CHANNEL);
        $this->shippingMethods->expects($this->never())->method('update');

        $response = $this->route->update(self::CHANNEL, new Request(request: ['orderingOpen' => false]), $this->admin());

        static::assertSame(200, $response->getStatusCode());
    }

    public function testWritesOrderingAndLeadTimesInOneTransaction(): void
    {
        $this->channelExists(true);
        $this->assigned([ShippingMethodLeadTimesTest::DELIVERY => [self::CHANNEL, self::OTHER_CHANNEL], ShippingMethodLeadTimesTest::PICKUP => [self::CHANNEL]]);
        $this->systemConfig->expects($this->once())->method('set')
            ->willReturnCallback(fn () => static::assertTrue($this->inTransaction));
        $this->shippingMethods->expects($this->once())->method('update')
            ->with($this->callback(static fn (array $updates): bool => array_column($updates, 'id') === [ShippingMethodLeadTimesTest::DELIVERY, ShippingMethodLeadTimesTest::PICKUP]))
            ->willReturnCallback(function () {
                static::assertTrue($this->inTransaction);

                return $this->createStub(\Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent::class);
            });

        $response = $this->route->update(self::CHANNEL, new Request(request: ['orderingOpen' => false, 'leadTimes' => [
            ShippingMethodLeadTimesTest::DELIVERY => 50,
            strtoupper(ShippingMethodLeadTimesTest::PICKUP) => 15,
        ]]), $this->admin());

        static::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        static::assertIsArray($body);
        static::assertArrayHasKey('shippingMethods', $body);
    }

    public function testLeadTimeOfAShippingMethodOfAnotherChannelIsRejected(): void
    {
        $this->channelExists(true);
        $this->assigned([ShippingMethodLeadTimesTest::DELIVERY => [self::CHANNEL], ShippingMethodLeadTimesTest::PICKUP => [self::OTHER_CHANNEL]]);
        $this->systemConfig->expects($this->never())->method('set');
        $this->shippingMethods->expects($this->never())->method('update');

        $response = $this->route->update(self::CHANNEL, new Request(request: [
            'orderingOpen' => false,
            'leadTimes' => [ShippingMethodLeadTimesTest::DELIVERY => 50, ShippingMethodLeadTimesTest::PICKUP => 15],
        ]), $this->admin());

        static::assertSame(400, $response->getStatusCode());
    }

    public function testBoundUserChangesOnlyLeadTimesOfMethodsUsedByTheirBranchesAlone(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturnCallback(
            static fn (string $sql, array $params = []): array => [['id' => strtolower(bin2hex((string) $params['id'])), 'navigation' => self::NAVIGATION]],
        );
        $this->connection->method('fetchOne')->willReturn(json_encode(['shopbite_sales_channel_ids' => [self::CHANNEL]]));
        $this->systemConfig->method('get')->willReturn(null);
        $this->assigned([ShippingMethodLeadTimesTest::DELIVERY => [self::CHANNEL, self::OTHER_CHANNEL], ShippingMethodLeadTimesTest::PICKUP => [self::CHANNEL]]);
        $this->shippingMethods->expects($this->once())->method('update')
            ->with($this->callback(static fn (array $updates): bool => array_column($updates, 'id') === [ShippingMethodLeadTimesTest::PICKUP]));
        $context = new Context(new AdminApiSource(self::USER));

        $body = json_decode((string) $this->route->read(self::CHANNEL, $context)->getContent(), true);
        static::assertIsArray($body);
        static::assertSame([false, true], array_column($body['shippingMethods'], 'editable'));

        // Delivery is shared with a channel the user is not bound to: changing it would change that branch too.
        static::assertSame(403, $this->route->update(self::CHANNEL, new Request(request: ['leadTimes' => [ShippingMethodLeadTimesTest::DELIVERY => 50, ShippingMethodLeadTimesTest::PICKUP => 15]]), $context)->getStatusCode());
        static::assertSame(200, $this->route->update(self::CHANNEL, new Request(request: ['leadTimes' => [ShippingMethodLeadTimesTest::PICKUP => 15]]), $context)->getStatusCode());
    }

    public function testUserBoundToAllChannelsOfAMethodMayChangeIt(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturnCallback(
            static fn (string $sql, array $params = []): array => [['id' => strtolower(bin2hex((string) $params['id'])), 'navigation' => self::NAVIGATION]],
        );
        $this->connection->method('fetchOne')->willReturn(json_encode(['shopbite_sales_channel_ids' => [self::CHANNEL, self::OTHER_CHANNEL]]));
        $this->systemConfig->method('get')->willReturn(null);
        $this->assigned([ShippingMethodLeadTimesTest::DELIVERY => [self::CHANNEL, self::OTHER_CHANNEL]]);
        $this->shippingMethods->expects($this->once())->method('update');

        static::assertSame(200, $this->route->update(self::CHANNEL, new Request(request: ['leadTimes' => [ShippingMethodLeadTimesTest::DELIVERY => 50]]), new Context(new AdminApiSource(self::USER)))->getStatusCode());
    }

    public function testUnknownSalesChannelIsNotFound(): void
    {
        $this->channelExists(false);
        $this->systemConfig->expects($this->never())->method('set');

        static::assertSame(404, $this->route->update(self::CHANNEL, new Request(request: ['orderingOpen' => true]), $this->admin())->getStatusCode());
        static::assertSame(404, $this->route->read(self::CHANNEL, $this->admin())->getStatusCode());
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testRejectsInvalidChanges(array $body): void
    {
        $this->channelExists(true);
        $this->systemConfig->expects($this->never())->method('set');

        static::assertSame(400, $this->route->update(self::CHANNEL, new Request(request: $body), $this->admin())->getStatusCode());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'empty' => [[]];
        yield 'other config key' => [['passkeyRpId' => 'evil.example']];
        yield 'menu category (plugin configuration only)' => [['menuCategoryId' => self::MENU]];
        yield 'ordering as string' => [['orderingOpen' => 'false']];
        yield 'delivery minutes (removed, lead time per shipping method)' => [['deliveryMinutes' => 30]];
        yield 'lead times not an object' => [['leadTimes' => 30]];
        yield 'lead times empty' => [['leadTimes' => []]];
        yield 'lead time zero' => [['leadTimes' => [ShippingMethodLeadTimesTest::DELIVERY => 0]]];
        yield 'lead time too long' => [['leadTimes' => [ShippingMethodLeadTimesTest::DELIVERY => 1441]]];
        yield 'lead time as float' => [['leadTimes' => [ShippingMethodLeadTimesTest::DELIVERY => 30.5]]];
        yield 'lead time as string' => [['leadTimes' => [ShippingMethodLeadTimesTest::DELIVERY => '30']]];
        yield 'lead time of no id' => [['leadTimes' => ['standard' => 30]]];
        yield 'lead times as list' => [['leadTimes' => [30]]];
    }

    public function testBoundUserReadsAndWritesOnlyTheirBranches(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturnCallback(
            fn (string $sql, array $params = []): array => $params === []
                ? [['id' => self::CHANNEL, 'navigation' => self::NAVIGATION], ['id' => self::OTHER_CHANNEL, 'navigation' => self::NAVIGATION]]
                : [['id' => strtolower(bin2hex((string) $params['id'])), 'navigation' => self::NAVIGATION]],
        );
        $this->connection->method('fetchOne')->willReturn(json_encode(['shopbite_sales_channel_ids' => [self::CHANNEL]]));
        $this->systemConfig->method('get')->willReturn(null);
        $this->systemConfig->expects($this->once())->method('set')->with(RestaurantSettings::ORDERING_OPEN, false, self::CHANNEL);

        $context = new Context(new AdminApiSource(self::USER));

        static::assertSame(403, $this->route->read(self::OTHER_CHANNEL, $context)->getStatusCode());
        static::assertSame(403, $this->route->update(self::OTHER_CHANNEL, new Request(request: ['orderingOpen' => false]), $context)->getStatusCode());
        static::assertSame(200, $this->route->read(self::CHANNEL, $context)->getStatusCode());
        static::assertSame(200, $this->route->update(self::CHANNEL, new Request(request: ['orderingOpen' => false]), $context)->getStatusCode());

        $body = json_decode((string) $this->route->list($context)->getContent(), true);
        static::assertIsArray($body);
        static::assertSame([self::CHANNEL], array_keys($body['channels']));
    }

    public function testAdminAndUnboundUsersSeeAllBranches(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([
            ['id' => self::CHANNEL, 'navigation' => self::NAVIGATION],
            ['id' => self::OTHER_CHANNEL, 'navigation' => self::NAVIGATION],
        ]);
        $this->connection->method('fetchOne')->willReturn(json_encode(['shopbite_sales_channel_ids' => []]));
        $this->systemConfig->method('get')->willReturn(null);

        $admin = new AdminApiSource(self::USER);
        $admin->setIsAdmin(true);

        foreach ([new Context($admin), new Context(new AdminApiSource(self::USER))] as $context) {
            $body = json_decode((string) $this->route->list($context)->getContent(), true);
            static::assertIsArray($body);
            static::assertSame([self::CHANNEL, self::OTHER_CHANNEL], array_keys($body['channels']));
        }
    }

    private function admin(): Context
    {
        $source = new AdminApiSource(self::USER);
        $source->setIsAdmin(true);

        return new Context($source);
    }

    /**
     * @param array<string, list<string>> $channelsByMethod
     */
    private function assigned(array $channelsByMethod): void
    {
        $rows = [];
        foreach ($channelsByMethod as $method => $channels) {
            foreach ($channels as $channel) {
                $rows[] = ['shipping_method_id' => $method, 'sales_channel_id' => $channel];
            }
        }
        $this->assignments->method('fetchAllAssociative')->willReturnCallback(static fn (string $sql, array $params): array => str_contains($sql, 'shipping_method_translation')
            ? array_map(static fn (string $id): array => ['shipping_method_id' => bin2hex($id), 'language_id' => Defaults::LANGUAGE_SYSTEM], $params['ids'])
            : array_values(array_filter(
                $rows,
                static fn (array $row): bool => \in_array(hex2bin($row['shipping_method_id']), $params['ids'], true),
            )));
    }

    private function channelExists(bool $exists): void
    {
        $this->connection->method('fetchAllAssociative')
            ->willReturn($exists ? [['id' => self::CHANNEL, 'navigation' => self::NAVIGATION]] : []);
    }
}
