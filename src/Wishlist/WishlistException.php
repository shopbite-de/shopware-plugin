<?php

declare(strict_types=1);

namespace ShopBite\Wishlist;

use Shopware\Core\Framework\HttpException;
use Symfony\Component\HttpFoundation\Response;

final class WishlistException extends HttpException
{
    public const string INVALID_PRODUCT_ID = 'SHOPBITE__WISHLIST_INVALID_PRODUCT_ID';
    public const string PRODUCT_NOT_FOUND = 'SHOPBITE__WISHLIST_PRODUCT_NOT_FOUND';
    public const string INVALID_CONFIGURATION = 'SHOPBITE__WISHLIST_INVALID_CONFIGURATION';
    public const string INVALID_ITEMS = 'SHOPBITE__WISHLIST_INVALID_ITEMS';
    public const string ITEM_NOT_FOUND = 'SHOPBITE__WISHLIST_ITEM_NOT_FOUND';

    public static function invalidProductId(): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::INVALID_PRODUCT_ID,
            'The parameter "productId" must be a valid UUID.',
        );
    }

    public static function productNotFound(string $productId): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::PRODUCT_NOT_FOUND,
            'Product "{{ productId }}" does not exist in this sales channel.',
            ['productId' => $productId],
        );
    }

    public static function invalidConfiguration(string $field): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::INVALID_CONFIGURATION,
            'The parameter "{{ field }}" must be a list of strings.',
            ['field' => $field],
        );
    }

    public static function invalidItems(): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::INVALID_ITEMS,
            'The parameter "items" must be a list of wishlist items.',
        );
    }

    public static function itemNotFound(string $id): self
    {
        return new self(
            Response::HTTP_NOT_FOUND,
            self::ITEM_NOT_FOUND,
            'Wishlist item "{{ id }}" not found.',
            ['id' => $id],
        );
    }
}
