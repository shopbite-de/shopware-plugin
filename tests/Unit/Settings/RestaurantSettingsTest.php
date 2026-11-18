<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Settings;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ShopBite\Settings\Api\SettingsRoute;
use ShopBite\Settings\RestaurantSettings;
use ShopBite\Staff\BranchBinding;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(RestaurantSettings::class)]
#[CoversClass(SettingsRoute::class)]
#[CoversClass(BranchBinding::class)]
#[AllowMockObjectsWithoutExpectations]
class RestaurantSettingsTest extends TestCase
{
    private const string CHANNEL = '0190000000007000800000000000b001';

    private const string NAVIGATION = '211cf9b2d1725130a517bbadf753a43c';

    private const string MENU = '018dadb399837af3aa669c8fff4f4d6c';

    private const string OTHER_CHANNEL = '0190000000007000800000000000b002';

    private const string USER = '0190000000007000800000000000a001';

    private SystemConfigService&MockObject $systemConfig;

    private Connection&MockObject $connection;

    private SettingsRoute $route;

    protected function setUp(): void
    {
        $this->systemConfig = $this->createMock(SystemConfigService::class);
        $this->connection = $this->createMock(Connection::class);
        $this->route = new SettingsRoute(new RestaurantSettings($this->systemConfig), $this->connection, new BranchBinding($this->connection));
    }

    public function testReadsTheChannelValuesWithFallback(): void
    {
        $this->channelExists(true);
        $this->systemConfig->method('get')->willReturnMap([
            [RestaurantSettings::ORDERING_OPEN, self::CHANNEL, false],
            [RestaurantSettings::DELIVERY_MINUTES, self::CHANNEL, 45],
            [RestaurantSettings::MENU_CATEGORY, self::CHANNEL, self::MENU],
        ]);

        $response = $this->route->read(self::CHANNEL, $this->admin());

        static::assertSame(
            ['orderingOpen' => false, 'deliveryMinutes' => 45, 'menuCategoryId' => self::MENU],
            json_decode((string) $response->getContent(), true),
        );
    }

    public function testUnsetMeansOpenWithoutDeliveryTime(): void
    {
        $this->systemConfig->method('get')->willReturn(null);

        static::assertSame(
            ['orderingOpen' => true, 'deliveryMinutes' => null, 'menuCategoryId' => self::NAVIGATION],
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

        static::assertSame(['channels' => [self::CHANNEL => ['orderingOpen' => true, 'deliveryMinutes' => null, 'menuCategoryId' => self::NAVIGATION]]], $body);
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
            ->with(RestaurantSettings::DELIVERY_MINUTES, 60, self::CHANNEL);

        $response = $this->route->update(self::CHANNEL, new Request(request: ['deliveryMinutes' => 60]), $this->admin());

        static::assertSame(200, $response->getStatusCode());
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
        yield 'delivery time zero' => [['deliveryMinutes' => 0]];
        yield 'delivery time too long' => [['deliveryMinutes' => 1441]];
        yield 'delivery time as float' => [['deliveryMinutes' => 30.5]];
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

    private function channelExists(bool $exists): void
    {
        $this->connection->method('fetchAllAssociative')
            ->willReturn($exists ? [['id' => self::CHANNEL, 'navigation' => self::NAVIGATION]] : []);
    }
}
