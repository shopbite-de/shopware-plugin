<?php

declare(strict_types=1);

namespace ShopBite\Order;

use Shopware\Core\Framework\HttpException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A user bound to branches asked for orders of other branches, or for a path that could reach them.
 */
final class BranchAccessException extends HttpException
{
    public const string ACCESS_DENIED = 'SHOPBITE__BRANCH_ACCESS_DENIED';

    public static function orderPath(string $path): self
    {
        return new self(
            Response::HTTP_FORBIDDEN,
            self::ACCESS_DENIED,
            'Orders can only be read from the order itself, not through "{{ path }}".',
            ['path' => $path],
        );
    }

    public static function otherBranch(string $entity): self
    {
        return new self(
            Response::HTTP_FORBIDDEN,
            self::ACCESS_DENIED,
            'This {{ entity }} belongs to an order of another branch.',
            ['entity' => $entity],
        );
    }
}
