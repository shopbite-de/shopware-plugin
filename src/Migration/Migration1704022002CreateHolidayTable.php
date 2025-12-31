<?php

declare(strict_types=1);

namespace ShopBite\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1704022002CreateHolidayTable extends MigrationStep
{
    #[\Override]
    public function getCreationTimestamp(): int
    {
        return 1704022002;
    }

    #[\Override]
    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `shopbite_holiday` (
                `id` BINARY(16) NOT NULL,
                `start` DATETIME(3) NOT NULL,
                `end` DATETIME(3) NOT NULL,
                `sales_channel_id` BINARY(16) NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                KEY `fk.shopbite_holiday.sales_channel_id` (`sales_channel_id`),
                CONSTRAINT `fk.shopbite_holiday.sales_channel_id` FOREIGN KEY (`sales_channel_id`) REFERENCES `sales_channel` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    #[\Override]
    public function updateDestructive(Connection $connection): void
    {
    }
}
