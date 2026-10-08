<?php

declare(strict_types=1);

namespace ShopBite\Wishlist\SalesChannel;

use ShopBite\Wishlist\WishlistConfiguration;
use ShopBite\Wishlist\WishlistException;
use ShopBite\Wishlist\WishlistItemCollection;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\NoContentResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Wishlist of configured dishes for logged-in customers, scoped to customer and sales channel.
 *
 * @psalm-suppress UnusedClass
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => ['store-api']])]
final readonly class WishlistRoute extends AbstractWishlistRoute
{
    public const int MERGE_LIMIT = 100;

    /**
     * @param EntityRepository<WishlistItemCollection> $wishlistItemRepository
     * @param SalesChannelRepository<ProductCollection> $productRepository
     */
    public function __construct(
        private EntityRepository $wishlistItemRepository,
        private SalesChannelRepository $productRepository,
    ) {
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
    #[\Override]
    public function getDecorated(): AbstractWishlistRoute
    {
        throw new DecorationPatternException(self::class);
    }

    #[Route(
        path: '/store-api/shopbite/wishlist',
        name: 'store-api.shopbite.wishlist.list',
        defaults: [PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true],
        methods: ['POST']
    )]
    #[\Override]
    public function load(SalesChannelContext $context, CustomerEntity $customer): WishlistRouteResponse
    {
        $criteria = $this->customerCriteria($context, $customer);
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));

        $elements = [];
        foreach ($this->wishlistItemRepository->search($criteria, $context->getContext())->getEntities() as $entity) {
            $elements[] = WishlistItemStruct::fromEntity($entity);
        }

        return new WishlistRouteResponse(new WishlistStruct($elements));
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
    #[Route(
        path: '/store-api/shopbite/wishlist/add',
        name: 'store-api.shopbite.wishlist.add',
        defaults: [PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true],
        methods: ['POST']
    )]
    #[\Override]
    public function add(RequestDataBag $data, SalesChannelContext $context, CustomerEntity $customer): WishlistItemRouteResponse
    {
        $productId = $data->all()['productId'] ?? null;
        if (!\is_string($productId) || !Uuid::isValid($productId)) {
            throw WishlistException::invalidProductId();
        }

        $configuration = WishlistConfiguration::fromInput($data->all()['without'] ?? null, $data->all()['extras'] ?? null);

        $product = $this->loadProducts([$productId], $context)->get($productId);
        if (!$product instanceof ProductEntity) {
            throw WishlistException::productNotFound($productId);
        }

        $existing = $this->loadItems($context, $customer, $productId);
        $match = $this->findMatch($existing, $productId, $configuration);
        if ($match !== null) {
            return new WishlistItemRouteResponse($match);
        }

        $payload = $this->createPayload($context, $customer, $product, $configuration, new \DateTimeImmutable());
        $this->wishlistItemRepository->create([$payload], $context->getContext());

        return new WishlistItemRouteResponse($this->structFromPayload($payload));
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
    #[Route(
        path: '/store-api/shopbite/wishlist/{id}',
        name: 'store-api.shopbite.wishlist.delete',
        defaults: [PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true],
        methods: ['DELETE']
    )]
    #[\Override]
    public function delete(string $id, SalesChannelContext $context, CustomerEntity $customer): NoContentResponse
    {
        if (!Uuid::isValid($id)) {
            throw WishlistException::itemNotFound($id);
        }

        $criteria = $this->customerCriteria($context, $customer);
        $criteria->setIds([$id]);

        if ($this->wishlistItemRepository->searchIds($criteria, $context->getContext())->firstId() === null) {
            throw WishlistException::itemNotFound($id);
        }

        $this->wishlistItemRepository->delete([['id' => $id]], $context->getContext());

        return new NoContentResponse();
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
    #[Route(
        path: '/store-api/shopbite/wishlist/merge',
        name: 'store-api.shopbite.wishlist.merge',
        defaults: [PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true],
        methods: ['POST']
    )]
    #[\Override]
    public function merge(RequestDataBag $data, SalesChannelContext $context, CustomerEntity $customer): WishlistRouteResponse
    {
        $items = $data->all()['items'] ?? [];
        if (!\is_array($items)) {
            throw WishlistException::invalidItems();
        }

        /** @var list<array{productId: string, configuration: WishlistConfiguration}> $candidates */
        $candidates = [];
        foreach (\array_slice(array_values($items), 0, self::MERGE_LIMIT) as $item) {
            $candidate = $this->parseMergeItem($item);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        if ($candidates !== []) {
            $this->mergeCandidates($candidates, $context, $customer);
        }

        return $this->load($context, $customer);
    }

    /**
     * @param list<array{productId: string, configuration: WishlistConfiguration}> $candidates
     */
    private function mergeCandidates(array $candidates, SalesChannelContext $context, CustomerEntity $customer): void
    {
        $products = $this->loadProducts(array_values(array_unique(array_column($candidates, 'productId'))), $context);
        $known = $this->loadItems($context, $customer);

        $payloads = [];
        // Each new entry is one millisecond older than the previous one, so the
        // newest-first list keeps the order of the submitted items.
        $createdAt = new \DateTimeImmutable();
        $offset = 0;
        foreach ($candidates as $candidate) {
            $product = $products->get($candidate['productId']);
            if (!$product instanceof ProductEntity) {
                continue;
            }

            if ($this->findMatch($known, $candidate['productId'], $candidate['configuration']) !== null) {
                continue;
            }

            $payload = $this->createPayload(
                $context,
                $customer,
                $product,
                $candidate['configuration'],
                $createdAt->modify(\sprintf('-%d milliseconds', $offset++)),
            );
            $payloads[] = $payload;
            $known[] = $this->structFromPayload($payload);
        }

        if ($payloads !== []) {
            $this->wishlistItemRepository->create($payloads, $context->getContext());
        }
    }

    /**
     * @return array{productId: string, configuration: WishlistConfiguration}|null
     */
    private function parseMergeItem(mixed $item): ?array
    {
        if (!\is_array($item)) {
            return null;
        }

        $productId = $item['productId'] ?? null;
        if (!\is_string($productId) || !Uuid::isValid($productId)) {
            return null;
        }

        try {
            $configuration = WishlistConfiguration::fromInput($item['without'] ?? null, $item['extras'] ?? null);
        } catch (WishlistException) {
            return null;
        }

        return ['productId' => $productId, 'configuration' => $configuration];
    }

    private function customerCriteria(SalesChannelContext $context, CustomerEntity $customer): Criteria
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customerId', $customer->getId()));
        $criteria->addFilter(new EqualsFilter('salesChannelId', $context->getSalesChannelId()));

        return $criteria;
    }

    /**
     * @return list<WishlistItemStruct>
     */
    private function loadItems(SalesChannelContext $context, CustomerEntity $customer, ?string $productId = null): array
    {
        $criteria = $this->customerCriteria($context, $customer);
        if ($productId !== null) {
            $criteria->addFilter(new EqualsFilter('productId', $productId));
        }

        $items = [];
        foreach ($this->wishlistItemRepository->search($criteria, $context->getContext())->getEntities() as $entity) {
            $items[] = WishlistItemStruct::fromEntity($entity);
        }

        return $items;
    }

    /**
     * @param list<WishlistItemStruct> $items
     */
    private function findMatch(array $items, string $productId, WishlistConfiguration $configuration): ?WishlistItemStruct
    {
        foreach ($items as $item) {
            if ($item->productId === $productId && $item->without === $configuration->without && $item->extras === $configuration->extras) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param list<string> $productIds
     */
    private function loadProducts(array $productIds, SalesChannelContext $context): ProductCollection
    {
        return $this->productRepository->search(new Criteria($productIds), $context)->getEntities();
    }

    /**
     * @return array{id: string, customerId: string, salesChannelId: string, productId: string, productVersionId: string, productNumber: string, configuration: array{without: list<string>, extras: list<string>}, createdAt: \DateTimeImmutable}
     */
    private function createPayload(
        SalesChannelContext $context,
        CustomerEntity $customer,
        ProductEntity $product,
        WishlistConfiguration $configuration,
        \DateTimeImmutable $createdAt,
    ): array {
        return [
            'id' => Uuid::randomHex(),
            'customerId' => $customer->getId(),
            'salesChannelId' => $context->getSalesChannelId(),
            'productId' => $product->getId(),
            'productVersionId' => $product->getVersionId() ?? Defaults::LIVE_VERSION,
            'productNumber' => $product->getProductNumber(),
            'configuration' => $configuration->toArray(),
            'createdAt' => $createdAt,
        ];
    }

    /**
     * @param array{id: string, customerId: string, salesChannelId: string, productId: string, productVersionId: string, productNumber: string, configuration: array{without: list<string>, extras: list<string>}, createdAt: \DateTimeImmutable} $payload
     */
    private function structFromPayload(array $payload): WishlistItemStruct
    {
        return new WishlistItemStruct(
            $payload['id'],
            $payload['productId'],
            $payload['productNumber'],
            $payload['configuration']['without'],
            $payload['configuration']['extras'],
            $payload['createdAt']->format(\DATE_ATOM),
        );
    }
}
