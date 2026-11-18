<?php

declare(strict_types=1);

namespace ShopBite\Checkout\Cart\Dish;

/**
 * Text of a dish line item that comes from the request: the ingredients to
 * leave out and the label as a hint for them. Control and format characters
 * are removed, lists and lengths are capped.
 */
final class DishText
{
    public const int MAX_WITHOUT = 20;
    public const int MAX_LENGTH = 100;
    public const int MAX_LABEL_LENGTH = 500;

    /**
     * Without control characters (Cc), format characters such as bidi overrides and zero
     * width spaces (Cf) and line/paragraph separators; invalid UTF-8 gives ''.
     */
    public static function clean(string $text): string
    {
        return trim(preg_replace('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]/u', '', $text) ?? '');
    }

    /** The label of the request, kept only as a hint until the products are known. */
    public static function label(string $label): string
    {
        return mb_substr(self::clean($label), 0, self::MAX_LABEL_LENGTH);
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
     * The ingredients to leave out as the storefront writes them into the label:
     * `<product> +<extra> ... -<ingredient> ...`. Product name and extras are known and cut
     * off first, then only the given ingredient names count, as a whole (longest first), so
     * names containing " - " or " +" are read correctly.
     *
     * @param list<string> $productNames names the label may start with
     * @param list<string> $extras       labels of the extras
     * @param list<string> $ingredients  names that can be left out
     *
     * @return list<string>
     */
    public static function withoutFromLabel(string $label, array $productNames, array $extras, array $ingredients): array
    {
        $rest = $label;
        foreach ($productNames as $name) {
            if ($name !== '' && str_starts_with($rest, $name)) {
                $rest = substr($rest, \strlen($name));
                break;
            }
        }
        foreach ($extras as $extra) {
            $position = strpos($rest, ' +' . $extra);
            if ($extra !== '' && $position !== false) {
                $rest = substr_replace($rest, '', $position, \strlen(' +' . $extra));
            }
        }

        $ingredients = array_values(array_filter($ingredients, static fn (string $name): bool => $name !== ''));
        usort($ingredients, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        $found = [];
        $offset = 0;
        while (($position = strpos($rest, ' -', $offset)) !== false) {
            $start = $position + 2;
            $offset = $start;
            foreach ($ingredients as $name) {
                $end = $start + \strlen($name);
                if (substr($rest, $start, \strlen($name)) !== $name) {
                    continue;
                }
                $after = substr($rest, $end);
                if ($after === '' || str_starts_with($after, ' -') || str_starts_with($after, ' +')) {
                    $found[] = $name;
                    $offset = $end;
                    break;
                }
            }
        }

        return \array_slice(self::unique($found), 0, self::MAX_WITHOUT);
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
