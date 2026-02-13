<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\MultiChannelGroup\SalesChannel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ShopBite\MultiChannelGroup\MultiChannelGroupCollection;
use ShopBite\MultiChannelGroup\SalesChannel\MultiChannelGroupRoute;
use ShopBite\MultiChannelGroup\SalesChannel\MultiChannelGroupRouteResponse;
use ShopBite\MultiChannelGroup\SalesChannel\MultiChannelGroupStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

#[CoversClass(MultiChannelGroupRoute::class)]
#[CoversClass(MultiChannelGroupRouteResponse::class)]
#[CoversClass(MultiChannelGroupStruct::class)]
class MultiChannelGroupRouteTest extends TestCase
{
    public function testLoad(): void
    {
        $multiChannelGroupRepository = $this->createMock(EntityRepository::class);
        $context = $this->createMock(SalesChannelContext::class);

        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId('sales-channel-id');

        $context->method('getSalesChannel')->willReturn($salesChannel);
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        $multiChannelGroups = new MultiChannelGroupCollection();
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('getEntities')->willReturn($multiChannelGroups);

        $multiChannelGroupRepository->expects($this->once())
            ->method('search')
            ->with($this->callback(function (Criteria $criteria) {
                $filters = $criteria->getFilters();
                $this->assertCount(1, $filters, 'Should have exactly one filter');

                // We expect EqualsFilter, but the current code uses ContainsFilter.
                // This test should fail if it doesn't find EqualsFilter.
                $this->assertInstanceOf(EqualsFilter::class, $filters[0], 'Filter should be EqualsFilter');
                $this->assertSame('salesChannels.id', $filters[0]->getField());
                $this->assertSame('sales-channel-id', $filters[0]->getValue());

                $associations = $criteria->getAssociations();
                $this->assertArrayHasKey('salesChannels', $associations, 'Should have salesChannels association');

                return true;
            }), $this->isInstanceOf(Context::class))
            ->willReturn($searchResult);

        $route = new MultiChannelGroupRoute($multiChannelGroupRepository);
        $response = $route->load($context);

        $this->assertSame($multiChannelGroups, $response->getMultiChannelGroup());
    }
}
