<?php

declare(strict_types=1);

namespace ShopBite\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Passkeys (WebAuthn credentials) of backoffice users, see src/Passkey.
 *
 * @psalm-suppress UnusedClass registered by Shopware's migration collection
 */
final class Migration1791800000CreatePasskeyTable extends MigrationStep
{
    #[\Override]
    public function getCreationTimestamp(): int
    {
        return 1791800000;
    }

    #[\Override]
    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `shopbite_passkey` (
                `id` BINARY(16) NOT NULL,
                `user_id` BINARY(16) NOT NULL,
                `credential_id` VARCHAR(512) NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `record` JSON NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `last_used_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.shopbite_passkey.credential_id` (`credential_id`),
                KEY `fk.shopbite_passkey.user_id` (`user_id`),
                CONSTRAINT `json.shopbite_passkey.record` CHECK (JSON_VALID(`record`)),
                CONSTRAINT `fk.shopbite_passkey.user_id` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }
}
