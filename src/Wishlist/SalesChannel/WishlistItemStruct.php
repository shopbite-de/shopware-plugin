<?php

declare(strict_types=1);

namespace ShopBite\Wishlist\SalesChannel;

use ShopBite\Wishlist\WishlistConfiguration;
use ShopBite\Wishlist\WishlistItemEntity;
use Shopware\Core\Framework\Struct\Struct;

/**
 * JSON shape of one wishlist entry in the Store API.
 *
 * @psalm-api
 */
final class WishlistItemStruct extends Struct
{
    /**
     * @param list<string> $without
     * @param list<string> $extras
     */
    public function __construct(
        public readonly string $id,
        public readonly string $productId,
        public readonly string $productNumber,
        public readonly array $without,
        public readonly array $extras,
        public readonly string $createdAt,
    ) {
    }

    public static function fromEntity(WishlistItemEntity $entity): self
    {
        $configuration = WishlistConfiguration::fromStorage($entity->getConfiguration());

        return new self(
            $entity->getId(),
            $entity->getProductId(),
            $entity->getProductNumber(),
            $configuration->without,
            $configuration->extras,
            ($entity->getCreatedAt() ?? new \DateTimeImmutable())->format(\DATE_ATOM),
        );
    }

    #[\Override]
    public function getApiAlias(): string
    {
        return 'shopbite_wishlist_item';
    }
}
