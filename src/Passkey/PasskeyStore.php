<?php

declare(strict_types=1);

namespace ShopBite\Passkey;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Table shopbite_passkey. Plain DBAL instead of a DAL entity, so the credentials are not
 * exposed through the generic Admin API.
 */
final readonly class PasskeyStore
{
    /** @psalm-suppress PossiblyUnusedMethod Constructed by the DI container */
    public function __construct(private Connection $connection)
    {
    }

    public function add(string $userId, string $credentialId, string $name, string $record): string
    {
        $id = Uuid::randomHex();
        $this->connection->insert('shopbite_passkey', [
            'id' => Uuid::fromHexToBytes($id),
            'user_id' => Uuid::fromHexToBytes($userId),
            'credential_id' => $credentialId,
            'name' => $name,
            'record' => $record,
            'created_at' => new \DateTimeImmutable()->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        return $id;
    }

    /**
     * @return array{id: string, userId: string, record: string}|null
     */
    public function findByCredentialId(string $credentialId): ?array
    {
        /** @var array{id: string, user_id: string, record: string}|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT LOWER(HEX(id)) AS id, LOWER(HEX(user_id)) AS user_id, record FROM shopbite_passkey WHERE credential_id = :credentialId',
            ['credentialId' => $credentialId],
        );

        return $row === false ? null : ['id' => $row['id'], 'userId' => $row['user_id'], 'record' => $row['record']];
    }

    /**
     * @return list<array{id: string, name: string, createdAt: string, lastUsedAt: string|null}>
     */
    public function listForUser(string $userId): array
    {
        /** @var list<array{id: string, name: string, createdAt: string, lastUsedAt: string|null}> */
        return $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(id)) AS id, name, created_at AS createdAt, last_used_at AS lastUsedAt
             FROM shopbite_passkey WHERE user_id = :userId ORDER BY created_at',
            ['userId' => Uuid::fromHexToBytes($userId)],
        );
    }

    /**
     * @return list<string> credential ids, to keep a device from registering twice
     */
    public function credentialIdsForUser(string $userId): array
    {
        /** @var list<string> */
        return $this->connection->fetchFirstColumn(
            'SELECT credential_id FROM shopbite_passkey WHERE user_id = :userId',
            ['userId' => Uuid::fromHexToBytes($userId)],
        );
    }

    /** Deletes only a passkey of this user. */
    public function delete(string $id, string $userId): bool
    {
        return $this->connection->delete('shopbite_passkey', [
            'id' => Uuid::fromHexToBytes($id),
            'user_id' => Uuid::fromHexToBytes($userId),
        ]) > 0;
    }

    public function recordUse(string $id, string $record): void
    {
        $this->connection->update('shopbite_passkey', [
            'record' => $record,
            'last_used_at' => new \DateTimeImmutable()->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ], ['id' => Uuid::fromHexToBytes($id)]);
    }

    /**
     * @return array{username: string, name: string, active: bool}|null
     */
    public function user(string $userId): ?array
    {
        /** @var array{username: string, first_name: string, last_name: string, active: int|string}|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT username, first_name, last_name, active FROM user WHERE id = :id',
            ['id' => Uuid::fromHexToBytes($userId)],
        );
        if ($row === false) {
            return null;
        }

        return [
            'username' => $row['username'],
            'name' => trim($row['first_name'] . ' ' . $row['last_name']) ?: $row['username'],
            'active' => (bool) $row['active'],
        ];
    }
}
