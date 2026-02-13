<?php

declare(strict_types=1);

namespace ShopBite\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1770980542CreateMultiChannelGroupTable extends MigrationStep
{
    #[\Override]
    public function getCreationTimestamp(): int
    {
        return 1770980542;
    }

    #[\Override]
    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `shopbite_multi_channel_group` (
                `id` BINARY(16) NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');

        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `shopbite_multi_channel_group_sales_channels` (
                `multi_channel_group_id` BINARY(16) NOT NULL,
                `sales_channel_id` BINARY(16) NOT NULL,
                PRIMARY KEY (`multi_channel_group_id`, `sales_channel_id`),
                CONSTRAINT `fk.mc_group_sc.multi_channel_group_id` 
                    FOREIGN KEY (`multi_channel_group_id`) 
                    REFERENCES `shopbite_multi_channel_group` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk.mc_group_sc.sales_channel_id` 
                    FOREIGN KEY (`sales_channel_id`) 
                    REFERENCES `sales_channel` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    #[\Override]
    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
