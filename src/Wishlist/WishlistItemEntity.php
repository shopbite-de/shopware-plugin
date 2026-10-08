<?php

declare(strict_types=1);

namespace ShopBite\Wishlist;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

final class WishlistItemEntity extends Entity
{
    use EntityIdTrait;

    protected string $customerId;

    protected ?CustomerEntity $customer = null;

    protected string $salesChannelId;

    protected ?SalesChannelEntity $salesChannel = null;

    protected string $productId;

    protected string $productVersionId;

    protected ?ProductEntity $product = null;

    protected string $productNumber;

    /**
     * @var array<string, mixed>
     */
    protected array $configuration = [];

    public function getProductId(): string
    {
        return $this->productId;
    }

    public function getProductNumber(): string
    {
        return $this->productNumber;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfiguration(): array
    {
        return $this->configuration;
    }
}
