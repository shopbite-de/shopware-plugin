<?php

declare(strict_types=1);

namespace ShopBite\Checkout\Cart\Ordering;

use Shopware\Core\Checkout\Cart\Error\Error;

/**
 * Blocks the order while the restaurant takes no orders. The key is one of
 * OrderingWindow::PAUSED, ::HOLIDAY, ::CLOSED and serves as id and message key.
 */
final class OrderingClosedError extends Error
{
    public function __construct(private readonly string $key)
    {
        parent::__construct(match ($key) {
            OrderingWindow::PAUSED => 'Ordering is paused.',
            OrderingWindow::HOLIDAY => 'The restaurant is closed today.',
            default => 'The restaurant takes no more orders today.',
        });
    }

    #[\Override]
    public function getId(): string
    {
        return $this->key;
    }

    #[\Override]
    public function getMessageKey(): string
    {
        return $this->key;
    }

    #[\Override]
    public function getLevel(): int
    {
        return self::LEVEL_ERROR;
    }

    #[\Override]
    public function blockOrder(): bool
    {
        return true;
    }

    /**
     * Checked again on every calculation, never stored with the cart.
     */
    #[\Override]
    public function isPersistent(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function getParameters(): array
    {
        return [];
    }
}
