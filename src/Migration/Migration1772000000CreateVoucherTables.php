<?php

declare(strict_types=1);

namespace ShopBite\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1772000000CreateVoucherTables extends MigrationStep
{
    #[\Override]
    public function getCreationTimestamp(): int
    {
        return 1772000000;
    }

    #[\Override]
    public function update(Connection $connection): void
    {
        // Main voucher table
        $connection->executeStatement(
            <<<'SQL'
CREATE TABLE IF NOT EXISTS `shopbite_voucher` (
    `id` BINARY(16) NOT NULL,
    `code` VARCHAR(64) NOT NULL,
    `value` JSON NOT NULL,
    `remaining_value` JSON NOT NULL,
    `valid_from` DATETIME(3) NULL,
    `valid_until` DATETIME(3) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by_order_id` BINARY(16) NULL,
    `sales_channel_id` BINARY(16) NULL,
    `created_at` DATETIME(3) NOT NULL,
    `updated_at` DATETIME(3) NULL,
    UNIQUE KEY `uniq.shopbite_voucher.code` (`code`),
    KEY `fk.shopbite_voucher.created_by_order_id` (`created_by_order_id`),
    KEY `fk.shopbite_voucher.sales_channel_id` (`sales_channel_id`),
    CONSTRAINT `fk.shopbite_voucher.created_by_order_id` FOREIGN KEY (`created_by_order_id`) REFERENCES `order` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk.shopbite_voucher.sales_channel_id` FOREIGN KEY (`sales_channel_id`) REFERENCES `sales_channel` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
        );

        // Redemption history
        $connection->executeStatement(
            <<<'SQL'
CREATE TABLE IF NOT EXISTS `shopbite_voucher_redemption` (
    `id` BINARY(16) NOT NULL,
    `voucher_id` BINARY(16) NOT NULL,
    `order_id` BINARY(16) NULL,
    `amount` JSON NOT NULL,
    `created_at` DATETIME(3) NOT NULL,
    PRIMARY KEY (`id`),
    KEY `fk.shopbite_voucher_redemption.voucher_id` (`voucher_id`),
    KEY `fk.shopbite_voucher_redemption.order_id` (`order_id`),
    CONSTRAINT `fk.shopbite_voucher_redemption.voucher_id` FOREIGN KEY (`voucher_id`) REFERENCES `shopbite_voucher` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk.shopbite_voucher_redemption.order_id` FOREIGN KEY (`order_id`) REFERENCES `order` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
        );
    }

    #[\Override]
    public function updateDestructive(Connection $connection): void
    {
    }
}
