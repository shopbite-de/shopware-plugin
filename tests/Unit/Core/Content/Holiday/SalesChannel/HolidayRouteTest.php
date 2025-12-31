<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Core\Content\Holiday\SalesChannel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ShopBite\Holiday\HolidayCollection;
use ShopBite\Holiday\SalesChannel\HolidayRoute;
use ShopBite\Holiday\SalesChannel\HolidayRouteResponse;
use ShopBite\Holiday\SalesChannel\HolidayStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

#[CoversClass(HolidayRoute::class)]
#[CoversClass(HolidayRouteResponse::class)]
#[CoversClass(HolidayStruct::class)]
class HolidayRouteTest extends TestCase
{
    public function testLoad(): void
    {
        $holidayRepository = $this->createMock(EntityRepository::class);
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sales-channel-id');
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        $holidays = new HolidayCollection();
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('getEntities')->willReturn($holidays);

        $holidayRepository->expects($this->once())
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

        $route = new HolidayRoute($holidayRepository);
        $response = $route->load($context);

        $this->assertSame($holidays, $response->getHolidays());
    }
}
