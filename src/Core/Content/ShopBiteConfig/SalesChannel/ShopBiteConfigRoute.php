<?php

declare(strict_types=1);

namespace ShopBite\Core\Content\ShopBiteConfig\SalesChannel;

use function Psl\Type\bool;
use function Psl\Type\positive_int;

use ShopBite\Core\Content\ShopBiteConfig;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['store-api']])]

class ShopBiteConfigRoute
{
    public function __construct(
        private SystemConfigService $systemConfigService,
    ) {
    }

    public function getDecorated(): AbstractShopBiteConfigRoute
    {
        throw new DecorationPatternException(self::class);
    }

    #[Route(
        path: '/store-api/shopbite/config',
        name: 'store-api.shopbite.config.get',
        defaults: ['_httpCache' => false],
        methods: ['GET']
    )]
    public function load(SalesChannelContext $context): ShopBiteConfigRouteResponse
    {
        $isCheckoutEnabled = $this->systemConfigService->get('ShopBitePlugin.config.isCheckoutEnabled', $context->getSalesChannelId());
        $defaultDeliveryTime = $this->systemConfigService->get('ShopBitePlugin.config.defaultDeliveryTime', $context->getSalesChannelId());

        $isCheckoutEnabled = bool()->coerce($isCheckoutEnabled);
        $defaultDeliveryTime = positive_int()->coerce($defaultDeliveryTime);

        return new ShopBiteConfigRouteResponse(new ShopBiteConfig($isCheckoutEnabled, $defaultDeliveryTime));
    }
}
