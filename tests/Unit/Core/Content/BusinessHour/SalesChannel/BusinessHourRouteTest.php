<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Core\Content\BusinessHour\SalesChannel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ShopBite\BusinessHour\BusinessHourCollection;
use ShopBite\BusinessHour\SalesChannel\BusinessHourRoute;
use ShopBite\BusinessHour\SalesChannel\BusinessHourRouteResponse;
use ShopBite\BusinessHour\SalesChannel\BusinessHourStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

#[CoversClass(BusinessHourRoute::class)]
#[CoversClass(BusinessHourRouteResponse::class)]
#[CoversClass(BusinessHourStruct::class)]
class BusinessHourRouteTest extends TestCase
{
    public function testLoad(): void
    {
        $businessHourRepository = $this->createMock(EntityRepository::class);
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sales-channel-id');
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        $businessHours = new BusinessHourCollection();
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('getEntities')->willReturn($businessHours);

        $businessHourRepository->expects($this->once())
            ->method('search')
            ->with($this->callback(function (Criteria $criteria) {
                $filters = $criteria->getFilters();
                $this->assertCount(1, $filters);
                $this->assertInstanceOf(EqualsFilter::class, $filters[0]);
                $this->assertSame('salesChannelId', $filters[0]->getField());
                $this->assertSame('sales-channel-id', $filters[0]->getValue());
                return true;
            }), $this->isInstanceOf(Context::class))
            ->willReturn($searchResult);

        $route = new BusinessHourRoute($businessHourRepository);
        $response = $route->load($context);

        $this->assertSame($businessHours, $response->getBusinessHours());
    }
}
