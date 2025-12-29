<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Core\Content\ShopBiteConfig\SalesChannel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ShopBite\Core\Content\ShopBiteConfig;
use ShopBite\Core\Content\ShopBiteConfig\SalesChannel\ShopBiteConfigRoute;
use ShopBite\Core\Content\ShopBiteConfig\SalesChannel\ShopBiteConfigRouteResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

#[CoversClass(ShopBiteConfigRoute::class)]
#[UsesClass(ShopBiteConfig::class)]
#[UsesClass(ShopBiteConfigRouteResponse::class)]
class ShopBiteConfigRouteTest extends TestCase
{
    public function testLoad(): void
    {
        $systemConfigService = $this->createMock(SystemConfigService::class);
        $systemConfigService->method('get')
            ->willReturnMap([
                ['ShopBite.config.isCheckoutEnabled', 'sales-channel-id', true],
                ['ShopBite.config.defaultDeliveryTime', 'sales-channel-id', 24],
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
