<?php

declare(strict_types=1);

namespace ShopBite\BusinessHour;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

final class BusinessHourEntity extends Entity
{
    use EntityIdTrait;

    protected int $dayOfWeek;

    protected string $openingTime;

    protected string $closingTime;

    protected string $salesChannelId;

    protected ?SalesChannelEntity $salesChannel = null;

    public function getDayOfWeek(): int
    {
        return $this->dayOfWeek;
    }

    public function setDayOfWeek(int $dayOfWeek): void
    {
        $this->dayOfWeek = $dayOfWeek;
    }

    public function getOpeningTime(): string
    {
        return $this->openingTime;
    }

    public function setOpeningTime(string $openingTime): void
    {
        $this->openingTime = $openingTime;
    }

    public function getClosingTime(): string
    {
        return $this->closingTime;
    }

    public function setClosingTime(string $closingTime): void
    {
        $this->closingTime = $closingTime;
    }

    public function getSalesChannelId(): string
    {
        return $this->salesChannelId;
    }

    public function setSalesChannelId(string $salesChannelId): void
    {
        $this->salesChannelId = $salesChannelId;
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
