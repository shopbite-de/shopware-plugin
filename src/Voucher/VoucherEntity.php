<?php

declare(strict_types=1);

namespace ShopBite\Voucher;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

final class VoucherEntity extends Entity
{
    use EntityIdTrait;

    protected string $code;

    /** @var array<string, mixed> */
    protected array $value;

    /** @var array<string, mixed> */
    protected array $remainingValue;

    protected ?\DateTimeInterface $validFrom = null;

    protected ?\DateTimeInterface $validUntil = null;

    protected bool $isActive = true;

    protected ?string $createdByOrderId = null;

    protected ?OrderEntity $createdByOrder = null;

    protected ?string $salesChannelId = null;

    protected ?SalesChannelEntity $salesChannel = null;

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): void
    {
        $this->code = $code;
    }

    /**
     * @return array<string, mixed>
     */
    public function getValue(): array
    {
        return $this->value;
    }

    /**
     * @param array<string, mixed> $value
     */
    public function setValue(array $value): void
    {
        $this->value = $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRemainingValue(): array
    {
        return $this->remainingValue;
    }

    /**
     * @param array<string, mixed> $remainingValue
     */
    public function setRemainingValue(array $remainingValue): void
    {
        $this->remainingValue = $remainingValue;
    }

    public function getValidFrom(): ?\DateTimeInterface
    {
        return $this->validFrom;
    }

    public function setValidFrom(?\DateTimeInterface $validFrom): void
    {
        $this->validFrom = $validFrom;
    }

    public function getValidUntil(): ?\DateTimeInterface
    {
        return $this->validUntil;
    }

    public function setValidUntil(?\DateTimeInterface $validUntil): void
    {
        $this->validUntil = $validUntil;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): void
    {
        $this->isActive = $isActive;
    }

    public function getCreatedByOrderId(): ?string
    {
        return $this->createdByOrderId;
    }

    public function setCreatedByOrderId(?string $createdByOrderId): void
    {
        $this->createdByOrderId = $createdByOrderId;
    }

    public function getCreatedByOrder(): ?OrderEntity
    {
        return $this->createdByOrder;
    }

    public function setCreatedByOrder(?OrderEntity $createdByOrder): void
    {
        $this->createdByOrder = $createdByOrder;
    }

    public function getSalesChannelId(): ?string
    {
        return $this->salesChannelId;
    }

    public function setSalesChannelId(?string $salesChannelId): void
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
