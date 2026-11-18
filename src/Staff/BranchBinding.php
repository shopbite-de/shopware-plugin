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
 * sources and users without branches (management without a branch sees all).
 */
final readonly class BranchBinding
{
    /** @psalm-suppress PossiblyUnusedMethod Constructed by the container */
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return non-empty-list<string>|null
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
        if (!\is_string($customFields)) {
            return null;
        }

        try {
            $decoded = json_decode($customFields, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $value = \is_array($decoded) ? ($decoded[CustomFieldsInstaller::SHOPBITE_SALES_CHANNEL_IDS] ?? null) : null;
        if (!\is_array($value)) {
            return null;
        }

        $ids = [];
        foreach ($value as $id) {
            if (\is_string($id) && Uuid::isValid(strtolower($id))) {
                $ids[] = strtolower($id);
            }
        }
        $ids = array_values(array_unique($ids));

        return $ids === [] ? null : $ids;
    }

    /**
     * Whether the user may act on this sales channel.
     */
    public function allows(Context $context, string $salesChannelId): bool
    {
        $ids = $this->salesChannelIds($context);

        return $ids === null || \in_array(strtolower($salesChannelId), $ids, true);
    }
}
