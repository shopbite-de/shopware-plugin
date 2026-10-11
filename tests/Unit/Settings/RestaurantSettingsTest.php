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
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(RestaurantSettings::class)]
#[CoversClass(SettingsRoute::class)]
#[AllowMockObjectsWithoutExpectations]
class RestaurantSettingsTest extends TestCase
{
    private const string CHANNEL = '0190000000007000800000000000b001';

    private const string NAVIGATION = '211cf9b2d1725130a517bbadf753a43c';

    private const string MENU = '018dadb399837af3aa669c8fff4f4d6c';

    private SystemConfigService&MockObject $systemConfig;

    private Connection&MockObject $connection;

    private SettingsRoute $route;

    protected function setUp(): void
    {
        $this->systemConfig = $this->createMock(SystemConfigService::class);
        $this->connection = $this->createMock(Connection::class);
        $this->route = new SettingsRoute(new RestaurantSettings($this->systemConfig), $this->connection);
    }

    public function testReadsTheChannelValuesWithFallback(): void
    {
        $this->channelExists(true);
        $this->systemConfig->method('get')->willReturnMap([
            [RestaurantSettings::ORDERING_OPEN, self::CHANNEL, false],
            [RestaurantSettings::DELIVERY_MINUTES, self::CHANNEL, 45],
            [RestaurantSettings::MENU_CATEGORY, self::CHANNEL, self::MENU],
        ]);

        $response = $this->route->read(self::CHANNEL);

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

    public function testListsEverySalesChannel(): void
    {
        $this->channelExists(true);
        $this->systemConfig->method('get')->willReturn(null);

        $body = json_decode((string) $this->route->list()->getContent(), true);

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

        $response = $this->route->update(self::CHANNEL, new Request(request: ['deliveryMinutes' => 60]));

        static::assertSame(200, $response->getStatusCode());
    }

    public function testUnknownSalesChannelIsNotFound(): void
    {
        $this->channelExists(false);
        $this->systemConfig->expects($this->never())->method('set');

        static::assertSame(404, $this->route->update(self::CHANNEL, new Request(request: ['orderingOpen' => true]))->getStatusCode());
        static::assertSame(404, $this->route->read(self::CHANNEL)->getStatusCode());
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testRejectsInvalidChanges(array $body): void
    {
        $this->channelExists(true);
        $this->systemConfig->expects($this->never())->method('set');

        static::assertSame(400, $this->route->update(self::CHANNEL, new Request(request: $body))->getStatusCode());
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

    private function channelExists(bool $exists): void
    {
        $this->connection->method('fetchAllAssociative')
            ->willReturn($exists ? [['id' => self::CHANNEL, 'navigation' => self::NAVIGATION]] : []);
    }
}
