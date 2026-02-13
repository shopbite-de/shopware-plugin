<?php

declare(strict_types=1);

namespace ShopBite\MultiChannelGroup\SalesChannel;

use ShopBite\MultiChannelGroup\MultiChannelGroupEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

final class MultiChannelGroupSalesChannelEntity extends Entity
{
    use EntityIdTrait;

    protected string $multiChannelGroupId;

    protected string $salesChannelId;

    protected ?MultiChannelGroupEntity $multiChannelGroup = null;

    protected ?SalesChannelEntity $salesChannel = null;

    public function getMultiChannelGroupId(): string
    {
        return $this->multiChannelGroupId;
    }

    public function setMultiChannelGroupId(string $multiChannelGroupId): void
    {
        $this->multiChannelGroupId = $multiChannelGroupId;
    }

    public function getSalesChannelId(): string
    {
        return $this->salesChannelId;
    }

    public function setSalesChannelId(string $salesChannelId): void
    {
        $this->salesChannelId = $salesChannelId;
    }

    public function getMultiChannelGroup(): ?MultiChannelGroupEntity
    {
        return $this->multiChannelGroup;
    }

    public function setMultiChannelGroup(?MultiChannelGroupEntity $multiChannelGroup): void
    {
        $this->multiChannelGroup = $multiChannelGroup;
    }

    public function getSalesChannel(): ?SalesChannelEntity
    {
        return $this->salesChannel;
    }

    public function setSalesChannel(?SalesChannelEntity $salesChannel): void
    {
        $this->salesChannel = $salesChannel;
    }
}
