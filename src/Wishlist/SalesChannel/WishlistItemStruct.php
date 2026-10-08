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

    /**
     * Not the entity name: for an alias that names an entity, the Store API
     * encoder drops every property the entity definition does not know
     * (`without`, `extras`).
     */
    #[\Override]
    public function getApiAlias(): string
    {
        return 'shopbite_wishlist_entry';
    }
}
