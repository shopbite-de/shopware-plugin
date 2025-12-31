<?php

declare(strict_types=1);

namespace ShopBite\Holiday\SalesChannel;

use ShopBite\Holiday\HolidayCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['store-api']])]
final readonly class HolidayRoute extends AbstractHolidayRoute
{
    /**
     * @param EntityRepository<HolidayCollection> $holidayRepository
     */
    public function __construct(
        private EntityRepository $holidayRepository,
    ) {
    }

    #[\Override]
    public function getDecorated(): AbstractHolidayRoute
    {
        throw new DecorationPatternException(self::class);
    }

    #[Route(
        path: '/store-api/shopbite/holiday',
        name: 'store-api.shopbite.holiday.get',
        defaults: ['_httpCache' => true],
        methods: ['GET', 'POST']
    )]
    #[\Override]
    public function load(SalesChannelContext $context): HolidayRouteResponse
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('salesChannelId', $context->getSalesChannelId()));

        $holidays = $this->holidayRepository->search($criteria, $context->getContext())->getEntities();

        return new HolidayRouteResponse(new HolidayStruct($holidays));
    }
}
