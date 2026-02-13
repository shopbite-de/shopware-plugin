<?php

declare(strict_types=1);

namespace ShopBite\MultiChannelGroup\SalesChannel;

use ShopBite\MultiChannelGroup\MultiChannelGroupCollection;
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
final readonly class MultiChannelGroupRoute extends AbstractMultiChannelGroupRoute
{
    /**
     * @param EntityRepository<MultiChannelGroupCollection> $multiChannelGroupRepository
     */
    public function __construct(
        private EntityRepository $multiChannelGroupRepository,
    ) {
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
    #[\Override]
    public function getDecorated(): AbstractMultiChannelGroupRoute
    {
        throw new DecorationPatternException(self::class);
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
    #[Route(
        path: '/store-api/shopbite/multi-channel-group',
        name: 'store-api.shopbite.multi-channel-group.get',
        defaults: ['_httpCache' => true],
        methods: ['GET']
    )]
    #[\Override]
    public function load(SalesChannelContext $context): MultiChannelGroupRouteResponse
    {
        $salesChannelId = $context->getSalesChannel()->getId();

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('salesChannels.id', $salesChannelId));

        $salesChannelsCriteria = $criteria->getAssociation('salesChannels');
        $salesChannelsCriteria->addFields(['name', 'domains']);
        $salesChannelsCriteria->addAssociation('domains');

        $multiChannelGroups = $this->multiChannelGroupRepository->search($criteria, $context->getContext())->getEntities();

        return new MultiChannelGroupRouteResponse(new MultiChannelGroupStruct($multiChannelGroups));
    }
}
