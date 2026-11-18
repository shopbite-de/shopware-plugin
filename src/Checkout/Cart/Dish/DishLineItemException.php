<?php

declare(strict_types=1);

namespace ShopBite\Checkout\Cart\Dish;

use Shopware\Core\Framework\HttpException;
use Symfony\Component\HttpFoundation\Response;

final class DishLineItemException extends HttpException
{
    public const string INVALID_DISH = 'SHOPBITE__CART_INVALID_DISH';

    public static function invalid(string $field, string $rule): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::INVALID_DISH,
            'Invalid dish line item: "{{ field }}" {{ rule }}.',
            ['field' => $field, 'rule' => $rule],
        );
    }
}
