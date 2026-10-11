<?php

declare(strict_types=1);

namespace ShopBite\Voucher;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

final class RedemptionEntity extends Entity
{
    use EntityIdTrait;

    protected string $voucherId;

    protected ?VoucherEntity $voucher = null;

    protected ?string $orderId = null;

    protected ?OrderEntity $order = null;

    /** @var array<string, mixed> */
    protected array $amount;

    public function getVoucherId(): string
    {
        return $this->voucherId;
    }

    public function setVoucherId(string $voucherId): void
    {
        $this->voucherId = $voucherId;
    }

    public function getVoucher(): ?VoucherEntity
    {
        return $this->voucher;
    }

    public function setVoucher(?VoucherEntity $voucher): void
    {
        $this->voucher = $voucher;
    }

    public function getOrderId(): ?string
    {
        return $this->orderId;
    }

    public function setOrderId(?string $orderId): void
    {
        $this->orderId = $orderId;
    }

    public function getOrder(): ?OrderEntity
    {
        return $this->order;
    }

    public function setOrder(?OrderEntity $order): void
    {
        $this->order = $order;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAmount(): array
    {
        return $this->amount;
    }

    /**
     * @param array<string, mixed> $amount
     */
    public function setAmount(array $amount): void
    {
        $this->amount = $amount;
    }
}
