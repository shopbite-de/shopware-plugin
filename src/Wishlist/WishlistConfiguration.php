<?php

declare(strict_types=1);

namespace ShopBite\Wishlist;

/**
 * Normalised dish configuration: deselected main ingredients (`without`, names)
 * and selected extras (`extras`, product numbers). Both lists are trimmed,
 * free of empty values, unique and sorted, so two configurations can be compared.
 */
final readonly class WishlistConfiguration
{
    /**
     * @param list<string> $without
     * @param list<string> $extras
     */
    private function __construct(
        public array $without,
        public array $extras,
    ) {
    }

    /**
     * @throws WishlistException when a value is neither null nor a list of strings
     */
    public static function fromInput(mixed $without, mixed $extras): self
    {
        return new self(
            self::normalise('without', $without),
            self::normalise('extras', $extras),
        );
    }

    /**
     * Lenient counterpart for stored data: invalid values become empty lists.
     */
    public static function fromStorage(mixed $configuration): self
    {
        if (!\is_array($configuration)) {
            return new self([], []);
        }

        try {
            return self::fromInput($configuration['without'] ?? null, $configuration['extras'] ?? null);
        } catch (WishlistException) {
            return new self([], []);
        }
    }

    /**
     * @return array{without: list<string>, extras: list<string>}
     */
    public function toArray(): array
    {
        return ['without' => $this->without, 'extras' => $this->extras];
    }

    /**
     * @return list<string>
     */
    private static function normalise(string $field, mixed $values): array
    {
        if ($values === null) {
            return [];
        }

        if (!\is_array($values)) {
            throw WishlistException::invalidConfiguration($field);
        }

        $normalised = [];
        foreach ($values as $value) {
            if (!\is_string($value)) {
                throw WishlistException::invalidConfiguration($field);
            }

            $value = trim($value);
            if ($value !== '') {
                $normalised[$value] = $value;
            }
        }

        $normalised = array_values($normalised);
        sort($normalised, \SORT_STRING);

        return $normalised;
    }
}
