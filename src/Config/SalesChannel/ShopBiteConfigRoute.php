<?php

declare(strict_types=1);

namespace ShopBite\Config\SalesChannel;

use function Psl\Type\bool;
use function Psl\Type\positive_int;
use function Psl\Type\string;

use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Routing\Attribute\Route;

/**
 * @psalm-suppress UnusedClass
 */
#[Route(defaults: ['_routeScope' => ['store-api']])]
final readonly class ShopBiteConfigRoute
{
    public function __construct(
        private SystemConfigService $systemConfigService,
    ) {
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
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
        $salesChannelId = $context->getSalesChannelId();

        $isCheckoutEnabled = $this->systemConfigService->get('ShopBitePlugin.config.isCheckoutEnabled', $salesChannelId);
        $defaultDeliveryTime = $this->systemConfigService->get('ShopBitePlugin.config.defaultDeliveryTime', $salesChannelId);

        return new ShopBiteConfigRouteResponse(new ShopBiteConfigStruct(
            bool()->coerce($isCheckoutEnabled),
            positive_int()->coerce($defaultDeliveryTime),
            $this->getOptionalString('addressStreet', $salesChannelId),
            $this->getOptionalString('addressPostalCode', $salesChannelId),
            $this->getOptionalString('addressCity', $salesChannelId),
            $this->getOptionalString('telephone', $salesChannelId),
            $this->getOptionalString('googleBusinessProfileUrl', $salesChannelId),
        ));
    }

    /**
     * Unset, non-string and blank values are returned as null.
     */
    private function getOptionalString(string $name, string $salesChannelId): ?string
    {
        $value = $this->systemConfigService->get('ShopBitePlugin.config.' . $name, $salesChannelId);

        if (!string()->matches($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
