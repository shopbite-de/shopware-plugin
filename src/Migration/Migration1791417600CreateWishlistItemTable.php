<?php

declare(strict_types=1);

namespace ShopBite\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @psalm-suppress UnusedClass registered by Shopware's migration collection
 */
final class Migration1791417600CreateWishlistItemTable extends MigrationStep
{
    #[\Override]
    public function getCreationTimestamp(): int
    {
        return 1791417600;
    }

    #[\Override]
    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `shopbite_wishlist_item` (
                `id` BINARY(16) NOT NULL,
                `customer_id` BINARY(16) NOT NULL,
                `sales_channel_id` BINARY(16) NOT NULL,
                `product_id` BINARY(16) NOT NULL,
                `product_version_id` BINARY(16) NOT NULL,
                `product_number` VARCHAR(64) NOT NULL,
                `configuration` JSON NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                KEY `idx.shopbite_wishlist_item.customer_sales_channel` (`customer_id`, `sales_channel_id`),
                KEY `fk.shopbite_wishlist_item.sales_channel_id` (`sales_channel_id`),
                KEY `fk.shopbite_wishlist_item.product_id` (`product_id`, `product_version_id`),
                CONSTRAINT `json.shopbite_wishlist_item.configuration` CHECK (JSON_VALID(`configuration`)),
                CONSTRAINT `fk.shopbite_wishlist_item.customer_id` FOREIGN KEY (`customer_id`) REFERENCES `customer` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk.shopbite_wishlist_item.sales_channel_id` FOREIGN KEY (`sales_channel_id`) REFERENCES `sales_channel` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk.shopbite_wishlist_item.product_id` FOREIGN KEY (`product_id`, `product_version_id`) REFERENCES `product` (`id`, `version_id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    #[\Override]
    public function updateDestructive(Connection $connection): void
    {
    }
}
