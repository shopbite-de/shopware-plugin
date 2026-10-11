<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Core\Content\ShopBiteConfig\SalesChannel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ShopBite\Config\SalesChannel\ShopBiteConfigRoute;
use ShopBite\Config\SalesChannel\ShopBiteConfigRouteResponse;
use ShopBite\Config\SalesChannel\ShopBiteConfigStruct;
use ShopBite\Settings\RestaurantSettings;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;

#[CoversClass(ShopBiteConfigRoute::class)]
#[UsesClass(ShopBiteConfigStruct::class)]
#[UsesClass(ShopBiteConfigRouteResponse::class)]
#[UsesClass(RestaurantSettings::class)]
class ShopBiteConfigRouteTest extends TestCase
{
    private const string NAVIGATION = '211cf9b2d1725130a517bbadf753a43c';

    public function testLoad(): void
    {
        $config = $this->load('sales-channel-id', [
            'isCheckoutEnabled' => true,
            'defaultDeliveryTime' => 24,
            'addressStreet' => 'Hauptstraße 1',
            'addressPostalCode' => '01067',
            'addressCity' => 'Dresden',
            'telephone' => '+49 6104 71427',
            'googleBusinessProfileUrl' => 'https://maps.app.goo.gl/abc',
        ]);

        $this->assertTrue($config->isCheckoutEnabled);
        $this->assertSame(24, $config->deliveryTime);
        $this->assertSame('Hauptstraße 1', $config->addressStreet);
        $this->assertSame('01067', $config->addressPostalCode);
        $this->assertSame('Dresden', $config->addressCity);
        $this->assertSame('+49 6104 71427', $config->telephone);
        $this->assertSame('https://maps.app.goo.gl/abc', $config->googleBusinessProfileUrl);
    }

    public function testMenuCategoryComesFromTheConfigElseTheNavigation(): void
    {
        $menu = '018dadb399837af3aa669c8fff4f4d6c';
        $this->assertSame($menu, $this->load('sales-channel-id', ['isCheckoutEnabled' => true, 'defaultDeliveryTime' => 30, 'menuCategoryId' => $menu])->menuCategoryId);
        $this->assertSame(self::NAVIGATION, $this->load('sales-channel-id', ['isCheckoutEnabled' => true, 'defaultDeliveryTime' => 30])->menuCategoryId);
    }

    public function testLoadReturnsNullForUnsetContactDetails(): void
    {
        $config = $this->load('sales-channel-id', [
            'isCheckoutEnabled' => false,
            'defaultDeliveryTime' => 30,
        ]);

        $this->assertFalse($config->isCheckoutEnabled);
        $this->assertNull($config->addressStreet);
        $this->assertNull($config->addressPostalCode);
        $this->assertNull($config->addressCity);
        $this->assertNull($config->telephone);
        $this->assertNull($config->googleBusinessProfileUrl);
    }

    public function testLoadTrimsContactDetailsAndTreatsBlankAsNull(): void
    {
        $config = $this->load('sales-channel-id', [
            'isCheckoutEnabled' => true,
            'defaultDeliveryTime' => 30,
            'addressStreet' => '  Hauptstraße 1 ',
            'addressPostalCode' => '',
            'addressCity' => '   ',
            'telephone' => 4961047142,
            'googleBusinessProfileUrl' => null,
        ]);

        $this->assertSame('Hauptstraße 1', $config->addressStreet);
        $this->assertNull($config->addressPostalCode);
        $this->assertNull($config->addressCity);
        $this->assertNull($config->telephone);
        $this->assertNull($config->googleBusinessProfileUrl);
    }

    public function testLoadReadsValuesOfTheContextSalesChannel(): void
    {
        $systemConfigService = $this->createMock(SystemConfigService::class);
        $systemConfigService->expects($this->exactly(8))
            ->method('get')
            ->willReturnCallback(static function (string $key, ?string $salesChannelId): mixed {
                static::assertSame('other-sales-channel-id', $salesChannelId);

                return match ($key) {
                    'ShopBitePlugin.config.isCheckoutEnabled' => true,
                    'ShopBitePlugin.config.defaultDeliveryTime' => 30,
                    'ShopBitePlugin.config.addressCity' => 'Hanau',
                    default => null,
                };
            });

        $context = $this->context('other-sales-channel-id');

        $config = new ShopBiteConfigRoute($systemConfigService)->load($context)->getObject();

        $this->assertSame('Hanau', $config->addressCity);
    }

    /**
     * @param array<string, mixed> $values plugin config values by field name
     */
    private function load(string $salesChannelId, array $values): ShopBiteConfigStruct
    {
        $systemConfigService = $this->createStub(SystemConfigService::class);
        $systemConfigService->method('get')
            ->willReturnCallback(static fn (string $key, ?string $id): mixed => $id === $salesChannelId
                ? $values[substr($key, \strlen('ShopBitePlugin.config.'))] ?? null
                : null);

        $context = $this->context($salesChannelId);

        return new ShopBiteConfigRoute($systemConfigService)->load($context)->getObject();
    }

    private function context(string $salesChannelId): SalesChannelContext
    {
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setNavigationCategoryId(self::NAVIGATION);
        $context = $this->createStub(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn($salesChannelId);
        $context->method('getSalesChannel')->willReturn($salesChannel);

        return $context;
    }
}
