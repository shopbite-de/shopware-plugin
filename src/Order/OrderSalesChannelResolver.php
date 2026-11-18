<?php

declare(strict_types=1);

namespace ShopBite\Order;

use Doctrine\DBAL\Connection;

/**
 * The sales channel (hex) of the order an order-family row belongs to, following the parent
 * foreign keys of OrderFamily::PARENTS up to the order.
 */
final readonly class OrderSalesChannelResolver
{
    /** @psalm-suppress PossiblyUnusedMethod Constructed by the container */
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param string $id binary id of a row of $entity
     */
    public function ofRow(string $entity, string $id): ?string
    {
        if ($entity === 'order') {
            $salesChannelId = $this->connection->fetchOne(
                'SELECT LOWER(HEX(sales_channel_id)) FROM `order` WHERE id = :id LIMIT 1',
                ['id' => $id],
            );

            return \is_string($salesChannelId) ? $salesChannelId : null;
        }

        $parent = OrderFamily::PARENTS[$entity] ?? null;
        if ($parent === null) {
            return null;
        }
        [$column, $parentEntity] = $parent;
        $parentId = $this->connection->fetchOne(
            \sprintf('SELECT `%s` FROM `%s` WHERE id = :id LIMIT 1', $column, $entity),
            ['id' => $id],
        );

        return \is_string($parentId) ? $this->ofRow($parentEntity, $parentId) : null;
    }
}
