<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Wishlist\SalesChannel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ShopBite\Wishlist\SalesChannel\WishlistItemRouteResponse;
use ShopBite\Wishlist\SalesChannel\WishlistItemStruct;
use ShopBite\Wishlist\SalesChannel\WishlistRoute;
use ShopBite\Wishlist\SalesChannel\WishlistRouteResponse;
use ShopBite\Wishlist\SalesChannel\WishlistStruct;
use ShopBite\Wishlist\WishlistConfiguration;
use ShopBite\Wishlist\WishlistException;
use ShopBite\Wishlist\WishlistItemCollection;
use ShopBite\Wishlist\WishlistItemEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\NoContentResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

#[CoversClass(WishlistRoute::class)]
#[CoversClass(WishlistRouteResponse::class)]
#[CoversClass(WishlistItemRouteResponse::class)]
#[CoversClass(WishlistStruct::class)]
#[CoversClass(WishlistItemStruct::class)]
#[CoversClass(WishlistConfiguration::class)]
#[CoversClass(WishlistException::class)]
class WishlistRouteTest extends TestCase
{
    private const string SALES_CHANNEL_ID = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
    private const string OTHER_SALES_CHANNEL_ID = 'b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2';
    private const string CUSTOMER_ID = 'c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3';
    private const string OTHER_CUSTOMER_ID = 'd4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4';
    private const string PIZZA_ID = 'e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5';
    private const string PASTA_ID = 'f6f6f6f6f6f6f6f6f6f6f6f6f6f6f6f6';
    private const string UNKNOWN_ID = '0123456789abcdef0123456789abcdef';

    /**
     * @var array<string, WishlistItemEntity> in-memory table of the fake repository
     */
    private array $rows = [];

    /**
     * @var list<Criteria>
     */
    private array $searches = [];

    /**
     * @var list<list<array<string, mixed>>>
     */
    private array $creates = [];

    /**
     * @var list<list<array<string, mixed>>>
     */
    private array $deletes = [];

    private WishlistRoute $route;

    private SalesChannelContext $context;

    private CustomerEntity $customer;

    #[\Override]
    protected function setUp(): void
    {
        $this->rows = [];
        $this->searches = [];
        $this->creates = [];
        $this->deletes = [];

        $context = $this->createStub(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
        $context->method('getContext')->willReturn(Context::createDefaultContext());
        $this->context = $context;

        $this->customer = new CustomerEntity();
        $this->customer->setId(self::CUSTOMER_ID);

        $this->route = new WishlistRoute($this->createWishlistRepository(), $this->createProductRepository());
    }

    public function testLoadIsScopedToCustomerAndSalesChannelNewestFirst(): void
    {
        $this->addRow('11111111111111111111111111111111', self::CUSTOMER_ID, self::SALES_CHANNEL_ID, self::PIZZA_ID, '2026-10-01 10:00:00');
        $this->addRow('22222222222222222222222222222222', self::CUSTOMER_ID, self::SALES_CHANNEL_ID, self::PASTA_ID, '2026-10-02 10:00:00');
        $this->addRow('33333333333333333333333333333333', self::OTHER_CUSTOMER_ID, self::SALES_CHANNEL_ID, self::PIZZA_ID, '2026-10-03 10:00:00');
        $this->addRow('44444444444444444444444444444444', self::CUSTOMER_ID, self::OTHER_SALES_CHANNEL_ID, self::PIZZA_ID, '2026-10-04 10:00:00');

        $response = $this->route->load($this->context, $this->customer);

        self::assertSame(
            ['22222222222222222222222222222222', '11111111111111111111111111111111'],
            array_map(static fn (WishlistItemStruct $item) => $item->id, $response->getElements()),
        );

        $criteria = $this->searches[0];
        self::assertEquals(
            [new EqualsFilter('customerId', self::CUSTOMER_ID), new EqualsFilter('salesChannelId', self::SALES_CHANNEL_ID)],
            $criteria->getFilters(),
        );
        self::assertEquals([new FieldSorting('createdAt', FieldSorting::DESCENDING)], $criteria->getSorting());
    }

    public function testItemJsonShape(): void
    {
        $this->addRow('11111111111111111111111111111111', self::CUSTOMER_ID, self::SALES_CHANNEL_ID, self::PIZZA_ID, '2026-10-01 10:00:00', ['Zwiebeln'], ['X-1']);

        $item = $this->route->load($this->context, $this->customer)->getElements()[0];
        $json = $item->jsonSerialize();

        self::assertSame('11111111111111111111111111111111', $json['id']);
        self::assertSame(self::PIZZA_ID, $json['productId']);
        self::assertSame('P-' . self::PIZZA_ID, $json['productNumber']);
        self::assertSame(['Zwiebeln'], $json['without']);
        self::assertSame(['X-1'], $json['extras']);
        self::assertSame('2026-10-01T10:00:00+00:00', $json['createdAt']);
        self::assertSame('shopbite_wishlist_item', $item->getApiAlias());
    }

    public function testAddCreatesNormalisedEntry(): void
    {
        $response = $this->route->add(new RequestDataBag([
            'productId' => self::PIZZA_ID,
            'without' => [' Zwiebeln', 'Knoblauch', 'Zwiebeln', ''],
            'extras' => ['X-2', 'X-1'],
        ]), $this->context, $this->customer);

        $item = $response->getItem();
        self::assertTrue(Uuid::isValid($item->id));
        self::assertSame(self::PIZZA_ID, $item->productId);
        self::assertSame('P-' . self::PIZZA_ID, $item->productNumber);
        self::assertSame(['Knoblauch', 'Zwiebeln'], $item->without);
        self::assertSame(['X-1', 'X-2'], $item->extras);

        self::assertCount(1, $this->creates);
        $payload = $this->creates[0][0];
        self::assertSame(self::CUSTOMER_ID, $payload['customerId']);
        self::assertSame(self::SALES_CHANNEL_ID, $payload['salesChannelId']);
        self::assertSame(self::PIZZA_ID, $payload['productId']);
        self::assertSame(Defaults::LIVE_VERSION, $payload['productVersionId']);
        self::assertSame('P-' . self::PIZZA_ID, $payload['productNumber']);
        self::assertSame(['without' => ['Knoblauch', 'Zwiebeln'], 'extras' => ['X-1', 'X-2']], $payload['configuration']);
    }

    public function testAddReturnsExistingEntryForSameConfiguration(): void
    {
        $this->addRow('11111111111111111111111111111111', self::CUSTOMER_ID, self::SALES_CHANNEL_ID, self::PIZZA_ID, '2026-10-01 10:00:00', ['Knoblauch', 'Zwiebeln'], ['X-1']);

        $response = $this->route->add(new RequestDataBag([
            'productId' => self::PIZZA_ID,
            'without' => ['Zwiebeln', 'Knoblauch '],
            'extras' => ['X-1', 'X-1'],
        ]), $this->context, $this->customer);

        self::assertSame('11111111111111111111111111111111', $response->getItem()->id);
        self::assertSame([], $this->creates);
    }

    public function testAddCreatesSeparateEntryForDifferentConfiguration(): void
    {
        $this->addRow('11111111111111111111111111111111', self::CUSTOMER_ID, self::SALES_CHANNEL_ID, self::PIZZA_ID, '2026-10-01 10:00:00', ['Zwiebeln'], []);

        $response = $this->route->add(new RequestDataBag([
            'productId' => self::PIZZA_ID,
            'without' => ['Zwiebeln'],
            'extras' => ['X-1'],
        ]), $this->context, $this->customer);

        self::assertNotSame('11111111111111111111111111111111', $response->getItem()->id);
        self::assertCount(1, $this->creates);
    }

    public function testAddDoesNotDedupeAgainstOtherCustomers(): void
    {
        $this->addRow('11111111111111111111111111111111', self::OTHER_CUSTOMER_ID, self::SALES_CHANNEL_ID, self::PIZZA_ID, '2026-10-01 10:00:00');

        $response = $this->route->add(new RequestDataBag(['productId' => self::PIZZA_ID]), $this->context, $this->customer);

        self::assertNotSame('11111111111111111111111111111111', $response->getItem()->id);
        self::assertCount(1, $this->creates);
    }

    public function testAddRejectsMissingProductId(): void
    {
        $this->assertWishlistException(
            WishlistException::INVALID_PRODUCT_ID,
            400,
            fn () => $this->route->add(new RequestDataBag([]), $this->context, $this->customer),
        );
    }

    public function testAddRejectsInvalidProductId(): void
    {
        $this->assertWishlistException(
            WishlistException::INVALID_PRODUCT_ID,
            400,
            fn () => $this->route->add(new RequestDataBag(['productId' => 'pizza']), $this->context, $this->customer),
        );
    }

    public function testAddRejectsUnknownProduct(): void
    {
        $this->assertWishlistException(
            WishlistException::PRODUCT_NOT_FOUND,
            400,
            fn () => $this->route->add(new RequestDataBag(['productId' => self::UNKNOWN_ID]), $this->context, $this->customer),
        );
        self::assertSame([], $this->creates);
    }

    public function testAddRejectsInvalidConfiguration(): void
    {
        $this->assertWishlistException(
            WishlistException::INVALID_CONFIGURATION,
            400,
            fn () => $this->route->add(new RequestDataBag(['productId' => self::PIZZA_ID, 'without' => 'Zwiebeln']), $this->context, $this->customer),
        );
    }

    public function testDeleteOwnItem(): void
    {
        $this->addRow('11111111111111111111111111111111', self::CUSTOMER_ID, self::SALES_CHANNEL_ID, self::PIZZA_ID, '2026-10-01 10:00:00');

        $response = $this->route->delete('11111111111111111111111111111111', $this->context, $this->customer);

        self::assertInstanceOf(NoContentResponse::class, $response);
        self::assertSame(204, $response->getStatusCode());
        self::assertSame([[['id' => '11111111111111111111111111111111']]], $this->deletes);
    }

    public function testDeleteItemOfOtherCustomerIsNotFound(): void
    {
        $this->addRow('11111111111111111111111111111111', self::OTHER_CUSTOMER_ID, self::SALES_CHANNEL_ID, self::PIZZA_ID, '2026-10-01 10:00:00');

        $this->assertWishlistException(
            WishlistException::ITEM_NOT_FOUND,
            404,
            fn () => $this->route->delete('11111111111111111111111111111111', $this->context, $this->customer),
        );
        self::assertSame([], $this->deletes);
    }

    public function testDeleteItemOfOtherSalesChannelIsNotFound(): void
    {
        $this->addRow('11111111111111111111111111111111', self::CUSTOMER_ID, self::OTHER_SALES_CHANNEL_ID, self::PIZZA_ID, '2026-10-01 10:00:00');

        $this->assertWishlistException(
            WishlistException::ITEM_NOT_FOUND,
            404,
            fn () => $this->route->delete('11111111111111111111111111111111', $this->context, $this->customer),
        );
        self::assertSame([], $this->deletes);
    }

    public function testDeleteUnknownOrInvalidIdIsNotFound(): void
    {
        $this->assertWishlistException(
            WishlistException::ITEM_NOT_FOUND,
            404,
            fn () => $this->route->delete(self::UNKNOWN_ID, $this->context, $this->customer),
        );
        $this->assertWishlistException(
            WishlistException::ITEM_NOT_FOUND,
            404,
            fn () => $this->route->delete('not-a-uuid', $this->context, $this->customer),
        );
    }

    public function testMergeDedupesSkipsUnknownAndReturnsFullList(): void
    {
        $this->addRow('11111111111111111111111111111111', self::CUSTOMER_ID, self::SALES_CHANNEL_ID, self::PIZZA_ID, '2026-10-01 10:00:00', ['Zwiebeln'], []);

        $response = $this->route->merge(new RequestDataBag(['items' => [
            ['productId' => self::PIZZA_ID, 'without' => [' Zwiebeln']],          // already saved
            ['productId' => self::PIZZA_ID, 'extras' => ['X-1']],                 // new configuration
            ['productId' => self::PIZZA_ID, 'extras' => ['X-1', '']],             // duplicate within the request
            ['productId' => self::PASTA_ID],                                      // new product
            ['productId' => self::UNKNOWN_ID],                                    // unknown product
            ['productId' => 'pizza'],                                             // invalid id
            ['productId' => self::PASTA_ID, 'without' => 'Zwiebeln'],             // invalid configuration
            'garbage',
        ]]), $this->context, $this->customer);

        self::assertCount(1, $this->creates);
        $created = $this->creates[0];
        self::assertCount(2, $created);
        self::assertSame(self::PIZZA_ID, $created[0]['productId']);
        self::assertSame(['without' => [], 'extras' => ['X-1']], $created[0]['configuration']);
        self::assertSame(self::PASTA_ID, $created[1]['productId']);

        $elements = $response->getElements();
        self::assertCount(3, $elements);
        // newest first, new entries keep the order of the request
        self::assertSame($created[0]['id'], $elements[0]->id);
        self::assertSame($created[1]['id'], $elements[1]->id);
        self::assertSame('11111111111111111111111111111111', $elements[2]->id);
    }

    public function testMergeCapsItems(): void
    {
        $items = [];
        for ($i = 0; $i < WishlistRoute::MERGE_LIMIT + 20; ++$i) {
            $items[] = ['productId' => self::PIZZA_ID, 'extras' => ['X-' . $i]];
        }

        $this->route->merge(new RequestDataBag(['items' => $items]), $this->context, $this->customer);

        self::assertCount(1, $this->creates);
        self::assertCount(WishlistRoute::MERGE_LIMIT, $this->creates[0]);
    }

    public function testMergeWithoutItemsReturnsList(): void
    {
        $this->addRow('11111111111111111111111111111111', self::CUSTOMER_ID, self::SALES_CHANNEL_ID, self::PIZZA_ID, '2026-10-01 10:00:00');

        $response = $this->route->merge(new RequestDataBag(['items' => []]), $this->context, $this->customer);

        self::assertSame([], $this->creates);
        self::assertCount(1, $response->getElements());
    }

    public function testMergeRejectsNonListItems(): void
    {
        $this->assertWishlistException(
            WishlistException::INVALID_ITEMS,
            400,
            fn () => $this->route->merge(new RequestDataBag(['items' => 'x']), $this->context, $this->customer),
        );
    }

    private function assertWishlistException(string $code, int $status, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected WishlistException ' . $code);
        } catch (WishlistException $e) {
            self::assertSame($code, $e->getErrorCode());
            self::assertSame($status, $e->getStatusCode());
        }
    }

    /**
     * @param list<string> $without
     * @param list<string> $extras
     */
    private function addRow(string $id, string $customerId, string $salesChannelId, string $productId, string $createdAt, array $without = [], array $extras = []): void
    {
        $entity = new WishlistItemEntity();
        $entity->assign([
            'id' => $id,
            'customerId' => $customerId,
            'salesChannelId' => $salesChannelId,
            'productId' => $productId,
            'productVersionId' => Defaults::LIVE_VERSION,
            'productNumber' => 'P-' . $productId,
            'configuration' => ['without' => $without, 'extras' => $extras],
            'createdAt' => new \DateTimeImmutable($createdAt, new \DateTimeZone('UTC')),
        ]);
        $this->rows[$id] = $entity;
    }

    /**
     * @return list<WishlistItemEntity>
     */
    private function matching(Criteria $criteria): array
    {
        $rows = array_values(array_filter($this->rows, static function (WishlistItemEntity $row) use ($criteria): bool {
            if ($criteria->getIds() !== [] && !\in_array($row->getId(), $criteria->getIds(), true)) {
                return false;
            }

            foreach ($criteria->getFilters() as $filter) {
                self::assertInstanceOf(EqualsFilter::class, $filter);
                if ($row->get($filter->getField()) !== $filter->getValue()) {
                    return false;
                }
            }

            return true;
        }));

        if ($criteria->getSorting() !== []) {
            usort($rows, static fn (WishlistItemEntity $a, WishlistItemEntity $b) => $b->getCreatedAt() <=> $a->getCreatedAt());
        }

        return $rows;
    }

    private function createWishlistRepository(): EntityRepository
    {
        $repository = $this->createStub(EntityRepository::class);

        $repository->method('search')->willReturnCallback(function (Criteria $criteria, Context $context): EntitySearchResult {
            $this->searches[] = $criteria;

            return new EntitySearchResult('shopbite_wishlist_item', 0, new WishlistItemCollection($this->matching($criteria)), null, $criteria, $context);
        });

        $repository->method('searchIds')->willReturnCallback(function (Criteria $criteria, Context $context): IdSearchResult {
            $ids = array_map(static fn (WishlistItemEntity $row) => ['primaryKey' => $row->getId(), 'data' => []], $this->matching($criteria));

            return new IdSearchResult(\count($ids), $ids, $criteria, $context);
        });

        $repository->method('create')->willReturnCallback(function (array $payloads): EntityWrittenContainerEvent {
            /** @var list<array<string, mixed>> $payloads */
            $this->creates[] = $payloads;
            foreach ($payloads as $payload) {
                $entity = new WishlistItemEntity();
                $entity->assign($payload);
                $this->rows[(string) $payload['id']] = $entity;
            }

            return EntityWrittenContainerEvent::createWithWrittenEvents([], Context::createDefaultContext(), []);
        });

        $repository->method('delete')->willReturnCallback(function (array $ids): EntityWrittenContainerEvent {
            /** @var list<array<string, mixed>> $ids */
            $this->deletes[] = $ids;

            return EntityWrittenContainerEvent::createWithDeletedEvents([], Context::createDefaultContext(), []);
        });

        return $repository;
    }

    private function createProductRepository(): SalesChannelRepository
    {
        $repository = $this->createStub(SalesChannelRepository::class);
        $repository->method('search')->willReturnCallback(static function (Criteria $criteria, SalesChannelContext $context): EntitySearchResult {
            $products = new ProductCollection();
            foreach ($criteria->getIds() as $id) {
                if (!\in_array($id, [self::PIZZA_ID, self::PASTA_ID], true)) {
                    continue;
                }
                $product = new SalesChannelProductEntity();
                $product->setId((string) $id);
                $product->setVersionId(Defaults::LIVE_VERSION);
                $product->setProductNumber('P-' . $id);
                $products->add($product);
            }

            return new EntitySearchResult('product', $products->count(), $products, null, $criteria, $context->getContext());
        });

        return $repository;
    }
}
