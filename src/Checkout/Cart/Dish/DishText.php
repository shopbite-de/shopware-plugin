<?php

declare(strict_types=1);

namespace ShopBite\Checkout\Cart\Dish;

/**
 * Text of a dish line item that comes from the request: the ingredients to
 * leave out. Control characters are removed, the lists are capped.
 */
final class DishText
{
    public const int MAX_WITHOUT = 20;
    public const int MAX_LENGTH = 100;

    public static function clean(string $text): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $text) ?? '');
    }

    /**
     * The `without` list of the payload, rejected when it breaks the limits.
     *
     * @return list<string>
     */
    public static function withoutFromPayload(mixed $without): array
    {
        if (!\is_array($without) || !array_is_list($without)) {
            throw DishLineItemException::invalid('payload.without', 'must be a list of strings');
        }
        if (\count($without) > self::MAX_WITHOUT) {
            throw DishLineItemException::invalid('payload.without', 'must have at most ' . self::MAX_WITHOUT . ' entries');
        }

        $names = [];
        foreach ($without as $name) {
            if (!\is_string($name)) {
                throw DishLineItemException::invalid('payload.without', 'must be a list of strings');
            }
            $name = self::clean($name);
            if (mb_strlen($name) > self::MAX_LENGTH) {
                throw DishLineItemException::invalid('payload.without', 'entries must be at most ' . self::MAX_LENGTH . ' characters');
            }
            $names[] = $name;
        }

        return self::unique($names);
    }

    /**
     * The ingredients to leave out as the storefront writes them into the
     * label: `<name> +<extra> ... -<ingredient> ...`. Only a hint, the cart
     * keeps the names that are ingredients of the product.
     *
     * @return list<string>
     */
    public static function withoutFromLabel(string $label): array
    {
        $names = [];
        foreach (preg_split('/ (?=[+-])/u', $label) ?: [] as $index => $segment) {
            if ($index === 0 || !str_starts_with($segment, '-')) {
                continue;
            }
            $name = self::clean(substr($segment, 1));
            if (mb_strlen($name) <= self::MAX_LENGTH) {
                $names[] = $name;
            }
        }

        return \array_slice(self::unique($names), 0, self::MAX_WITHOUT);
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private static function unique(array $names): array
    {
        return array_values(array_unique(array_filter($names, static fn (string $name): bool => $name !== '')));
    }
}
