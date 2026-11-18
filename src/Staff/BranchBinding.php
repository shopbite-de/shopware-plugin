<?php

declare(strict_types=1);

namespace ShopBite\Staff;

use Doctrine\DBAL\Connection;
use ShopBite\Service\CustomFieldsInstaller;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The branches (sales channels) an Admin API user is bound to: the user custom field
 * `shopbite_sales_channel_ids`. Null means not restricted: admins, integrations, other
 * sources and users without the field or with an empty list (management without a branch
 * sees all). A field that is there but unreadable (no list, no valid id, broken JSON) or an
 * unknown user means no branch at all.
 */
final readonly class BranchBinding
{
    /** @psalm-suppress PossiblyUnusedMethod Constructed by the container */
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<string>|null
     */
    public function salesChannelIds(Context $context): ?array
    {
        $source = $context->getSource();
        if (!$source instanceof AdminApiSource || $source->isAdmin()) {
            return null;
        }
        $userId = $source->getUserId();
        if ($userId === null || !Uuid::isValid($userId)) {
            return null;
        }

        $customFields = $this->connection->fetchOne(
            'SELECT custom_fields FROM `user` WHERE id = :id',
            ['id' => Uuid::fromHexToBytes($userId)],
        );
        if ($customFields === false) {
            return [];
        }
        if ($customFields === null) {
            return null;
        }

        try {
            $decoded = json_decode((string) $customFields, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return self::parse(\is_array($decoded) ? ($decoded[CustomFieldsInstaller::SHOPBITE_SALES_CHANNEL_IDS] ?? null) : null);
    }

    /**
     * The field value as list of sales channel ids: null when unset or empty, [] when malformed.
     *
     * @return list<string>|null
     */
    public static function parse(mixed $value): ?array
    {
        if ($value === null || $value === []) {
            return null;
        }
        if (!\is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $id) {
            if (\is_string($id) && Uuid::isValid(strtolower($id))) {
                $ids[] = strtolower($id);
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Whether the user may act on this sales channel.
     */
    public function allows(Context $context, string $salesChannelId): bool
    {
        return self::permits($this->salesChannelIds($context), $salesChannelId);
    }

    /**
     * @param list<string>|null $salesChannelIds as returned by salesChannelIds()
     */
    public static function permits(?array $salesChannelIds, string $salesChannelId): bool
    {
        return $salesChannelIds === null || \in_array(strtolower($salesChannelId), $salesChannelIds, true);
    }
}
