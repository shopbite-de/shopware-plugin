<?php

declare(strict_types=1);

namespace ShopBite\BusinessHour\SalesChannel;

use ShopBite\BusinessHour\BusinessHourCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\Routing\Attribute\Route;

/**
 * @psalm-suppress UnusedClass
 */
#[Route(defaults: ['_routeScope' => ['store-api']])]
final readonly class BusinessHourRoute extends AbstractBusinessHourRoute
{
    /**
     * @param EntityRepository<BusinessHourCollection> $businessHourRepository
     */
    public function __construct(
        private EntityRepository $businessHourRepository,
    ) {
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
    #[\Override]
    public function getDecorated(): AbstractBusinessHourRoute
    {
        throw new DecorationPatternException(self::class);
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
    #[Route(
        path: '/store-api/shopbite/business-hour',
        name: 'store-api.shopbite.business-hour.get',
        defaults: ['_httpCache' => true],
        methods: ['GET', 'POST']
    )]
    #[\Override]
    public function load(SalesChannelContext $context): BusinessHourRouteResponse
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('salesChannelId', $context->getSalesChannelId()));

        $businessHours = $this->businessHourRepository->search($criteria, $context->getContext())->getEntities();

        return new BusinessHourRouteResponse(new BusinessHourStruct($businessHours));
    }
}
