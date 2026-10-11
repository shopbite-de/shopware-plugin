<?php

declare(strict_types=1);

namespace ShopBite\Staff\Command;

/**
 * "Anna Rossi" → ["Anna", "Rossi"]; Shopware requires a last name, so a single word or
 * nothing falls back to the username.
 */
final class StaffNames
{
    /**
     * @return array{0: string, 1: string}
     */
    public static function split(string $name, string $username): array
    {
        $parts = preg_split('/\s+/', trim($name), 2) ?: [];
        if (\count($parts) === 2) {
            return [$parts[0], $parts[1]];
        }

        return ['', ($parts[0] ?? '') ?: $username];
    }
}
