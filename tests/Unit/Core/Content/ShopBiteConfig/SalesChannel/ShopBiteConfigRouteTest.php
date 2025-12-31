<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Core\Content\ShopBiteConfig\SalesChannel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ShopBite\Config\SalesChannel\ShopBiteConfigRoute;
use ShopBite\Config\SalesChannel\ShopBiteConfigRouteResponse;
use ShopBite\Config\SalesChannel\ShopBiteConfigStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

#[CoversClass(ShopBiteConfigRoute::class)]
#[UsesClass(ShopBiteConfigStruct::class)]
#[UsesClass(ShopBiteConfigRouteResponse::class)]
class ShopBiteConfigRouteTest extends TestCase
{
    public function testLoad(): void
    {
        $systemConfigService = $this->createMock(SystemConfigService::class);
        $systemConfigService->method('get')
            ->willReturnMap([
                ['ShopBitePlugin.config.isCheckoutEnabled', 'sales-channel-id', true],
                ['ShopBitePlugin.config.defaultDeliveryTime', 'sales-channel-id', 24],
            ]);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sales-channel-id');

        $route = new ShopBiteConfigRoute($systemConfigService);
        $response = $route->load($context);

        $config = $response->getObject();
        $this->assertTrue($config->isCheckoutEnabled);
        $this->assertSame(24, $config->deliveryTime);
    }
}
