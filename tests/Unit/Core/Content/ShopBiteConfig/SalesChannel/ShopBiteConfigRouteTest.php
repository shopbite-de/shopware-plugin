<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Core\Content\ShopBiteConfig\SalesChannel;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ShopBite\Config\SalesChannel\ShopBiteConfigRoute;
use ShopBite\Config\SalesChannel\ShopBiteConfigRouteResponse;
use ShopBite\Config\SalesChannel\ShopBiteConfigStruct;
use ShopBite\Fulfillment\ShippingMethodFulfillment;
use ShopBite\Fulfillment\ShippingMethodLeadTimes;
use ShopBite\Settings\RestaurantSettings;
use ShopBite\Tests\Unit\Fulfillment\ShippingMethodLeadTimesTest;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;

#[CoversClass(ShopBiteConfigRoute::class)]
#[UsesClass(ShopBiteConfigStruct::class)]
#[UsesClass(ShopBiteConfigRouteResponse::class)]
#[UsesClass(RestaurantSettings::class)]
#[UsesClass(ShippingMethodLeadTimes::class)]
#[UsesClass(ShippingMethodFulfillment::class)]
#[AllowMockObjectsWithoutExpectations]
class ShopBiteConfigRouteTest extends TestCase
{
    private const string NAVIGATION = '211cf9b2d1725130a517bbadf753a43c';

    public function testLoad(): void
    {
        $config = $this->load('sales-channel-id', [
            'isCheckoutEnabled' => true,
            'addressStreet' => 'Hauptstraße 1',
            'addressPostalCode' => '01067',
            'addressCity' => 'Dresden',
            'telephone' => '+49 123 456789',
            'googleBusinessProfileUrl' => 'https://maps.app.goo.gl/abc',
        ]);

        $this->assertTrue($config->isCheckoutEnabled);
        $this->assertSame('Hauptstraße 1', $config->addressStreet);
        $this->assertSame('01067', $config->addressPostalCode);
        $this->assertSame('Dresden', $config->addressCity);
        $this->assertSame('+49 123 456789', $config->telephone);
        $this->assertSame('https://maps.app.goo.gl/abc', $config->googleBusinessProfileUrl);
    }

    public function testShippingMethodsOfTheChannelWithFulfillmentAndLeadTime(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->once())->method('search')
            ->with(
                $this->callback(static function (Criteria $criteria): bool {
                    foreach ($criteria->getFilters() as $filter) {
                        if ($filter instanceof EqualsFilter && $filter->getField() === 'salesChannels.id') {
                            return $filter->getValue() === 'sales-channel-id';
                        }
                    }

                    return false;
                }),
                $this->isInstanceOf(Context::class),
            )
            ->willReturn(ShippingMethodLeadTimesTest::searchResult(ShippingMethodLeadTimesTest::methods()));

        $config = $this->load('sales-channel-id', ['isCheckoutEnabled' => true], new ShippingMethodLeadTimes($repository, $this->createStub(EntityRepository::class), $this->createStub(Connection::class)));

        $this->assertSame([
            ['id' => ShippingMethodLeadTimesTest::DELIVERY, 'fulfillment' => 'delivery', 'leadTimeMinutes' => 45],
            ['id' => ShippingMethodLeadTimesTest::PICKUP, 'fulfillment' => 'pickup', 'leadTimeMinutes' => 30],
        ], $config->shippingMethods);
        $this->assertArrayNotHasKey('deliveryTime', $config->jsonSerialize());
    }

    public function testMenuCategoryComesFromTheConfigElseTheNavigation(): void
    {
        $menu = '018dadb399837af3aa669c8fff4f4d6c';
        $this->assertSame($menu, $this->load('sales-channel-id', ['isCheckoutEnabled' => true, 'menuCategoryId' => $menu])->menuCategoryId);
        $this->assertSame(self::NAVIGATION, $this->load('sales-channel-id', ['isCheckoutEnabled' => true])->menuCategoryId);
    }

    public function testLoadReturnsNullForUnsetContactDetails(): void
    {
        $config = $this->load('sales-channel-id', [
            'isCheckoutEnabled' => false,
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
            'addressStreet' => '  Hauptstraße 1 ',
            'addressPostalCode' => '',
            'addressCity' => '   ',
            'telephone' => 49123456789,
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
        $systemConfigService->expects($this->exactly(7))
            ->method('get')
            ->willReturnCallback(static function (string $key, ?string $salesChannelId): mixed {
                static::assertSame('other-sales-channel-id', $salesChannelId);

                return match ($key) {
                    'ShopBitePlugin.config.isCheckoutEnabled' => true,
                    'ShopBitePlugin.config.addressCity' => 'Hanau',
                    default => null,
                };
            });

        $context = $this->context('other-sales-channel-id');

        $config = new ShopBiteConfigRoute($systemConfigService, $this->leadTimes())->load($context)->getObject();

        $this->assertSame('Hanau', $config->addressCity);
    }

    /**
     * @param array<string, mixed> $values plugin config values by field name
     */
    private function load(string $salesChannelId, array $values, ?ShippingMethodLeadTimes $leadTimes = null): ShopBiteConfigStruct
    {
        $systemConfigService = $this->createStub(SystemConfigService::class);
        $systemConfigService->method('get')
            ->willReturnCallback(static fn (string $key, ?string $id): mixed => $id === $salesChannelId
                ? $values[substr($key, \strlen('ShopBitePlugin.config.'))] ?? null
                : null);

        $context = $this->context($salesChannelId);

        return new ShopBiteConfigRoute($systemConfigService, $leadTimes ?? $this->leadTimes())->load($context)->getObject();
    }

    private function leadTimes(): ShippingMethodLeadTimes
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('search')->willReturn(ShippingMethodLeadTimesTest::searchResult(new ShippingMethodCollection()));

        return new ShippingMethodLeadTimes($repository, $this->createStub(EntityRepository::class), $this->createStub(Connection::class));
    }

    private function context(string $salesChannelId): SalesChannelContext
    {
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setNavigationCategoryId(self::NAVIGATION);
        $context = $this->createStub(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn($salesChannelId);
        $context->method('getSalesChannel')->willReturn($salesChannel);
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        return $context;
    }
}
