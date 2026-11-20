<?php

declare(strict_types=1);

namespace ShopBite\Settings\Api;

use Doctrine\DBAL\Connection;
use ShopBite\Fulfillment\ShippingMethodLeadTimes;
use ShopBite\Settings\RestaurantSettings;
use ShopBite\Staff\BranchBinding;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin API for the backoffice: the restaurant settings of the sales channels, guarded by the
 * plugin's own privileges shopbite_settings:read / shopbite_settings:update instead of write
 * access to the whole system configuration (plugin#34). Users bound to branches (user custom
 * field shopbite_sales_channel_ids, see BranchBinding) see and change only these sales channels.
 *
 * Every read carries `shippingMethods` (the channel's active shipping methods with fulfillment and
 * lead time); a PATCH with `leadTimes` assigns the shipping method a delivery time of that many
 * minutes, which changes it for every sales channel using that method. Hence a bound user may only change methods
 * that are assigned exclusively to their branches.
 *
 * @psalm-api Registered through routes.xml
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => ['api']])]
final readonly class SettingsRoute
{
    public const string READ_PRIVILEGE = 'shopbite_settings:read';

    public const string UPDATE_PRIVILEGE = 'shopbite_settings:update';

    public function __construct(
        private RestaurantSettings $settings,
        private Connection $connection,
        private BranchBinding $branchBinding,
        private ShippingMethodLeadTimes $leadTimes,
    ) {
    }

    /**
     * Every sales channel at once, keyed by id (the backoffice needs the menu category of each
     * branch on every request).
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    #[Route(
        path: '/api/_action/shopbite/settings',
        name: 'api.action.shopbite.settings.list',
        defaults: ['_acl' => [self::READ_PRIVILEGE]],
        methods: ['GET'],
    )]
    public function list(Context $context): JsonResponse
    {
        $allowed = $this->branchBinding->salesChannelIds($context);
        $channels = [];
        foreach ($this->navigationCategories() as $id => $navigationCategoryId) {
            if (!BranchBinding::permits($allowed, $id)) {
                continue;
            }
            $channels[$id] = $this->readChannel($id, $navigationCategoryId, $context, $allowed);
        }

        return new JsonResponse(['channels' => (object) $channels]);
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    #[Route(
        path: '/api/_action/shopbite/settings/{salesChannelId}',
        name: 'api.action.shopbite.settings.read',
        requirements: ['salesChannelId' => '[0-9a-f]{32}'],
        defaults: ['_acl' => [self::READ_PRIVILEGE]],
        methods: ['GET'],
    )]
    public function read(string $salesChannelId, Context $context): JsonResponse
    {
        if (!$this->branchBinding->allows($context, $salesChannelId)) {
            return $this->forbidden();
        }
        $navigationCategoryId = $this->navigationCategories($salesChannelId)[$salesChannelId] ?? null;
        if ($navigationCategoryId === null) {
            return $this->error(Response::HTTP_NOT_FOUND, 'Sales channel not found.');
        }

        return new JsonResponse($this->readChannel($salesChannelId, $navigationCategoryId, $context, $this->branchBinding->salesChannelIds($context)));
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    #[Route(
        path: '/api/_action/shopbite/settings/{salesChannelId}',
        name: 'api.action.shopbite.settings.update',
        requirements: ['salesChannelId' => '[0-9a-f]{32}'],
        defaults: ['_acl' => [self::UPDATE_PRIVILEGE]],
        methods: ['PATCH'],
    )]
    public function update(string $salesChannelId, Request $request, Context $context): JsonResponse
    {
        if (!$this->branchBinding->allows($context, $salesChannelId)) {
            return $this->forbidden();
        }
        $navigationCategoryId = $this->navigationCategories($salesChannelId)[$salesChannelId] ?? null;
        if ($navigationCategoryId === null) {
            return $this->error(Response::HTTP_NOT_FOUND, 'Sales channel not found.');
        }
        $changes = RestaurantSettings::changesFrom($request->request->all());
        if (\is_string($changes)) {
            return $this->error(Response::HTTP_BAD_REQUEST, $changes);
        }
        $leadTimes = $changes['leadTimes'] ?? [];
        $refused = $this->refusedLeadTimes($salesChannelId, $leadTimes, $context);
        if ($refused !== null) {
            return $refused;
        }
        // One transaction, so a failing lead time write does not leave the ordering switch changed.
        $this->connection->transactional(function () use ($salesChannelId, $changes, $leadTimes): void {
            $this->settings->write($salesChannelId, $changes);
            $this->leadTimes->write($leadTimes);
        });

        return new JsonResponse($this->readChannel($salesChannelId, $navigationCategoryId, $context, $this->branchBinding->salesChannelIds($context)));
    }

    /**
     * @param list<string>|null $allowed the user's branches, see BranchBinding
     *
     * @return array{orderingOpen: bool, menuCategoryId: string, shippingMethods: list<array{id: string, name: string, fulfillment: string, leadTimeMinutes: int, leadTimeMinutesSet: bool, editable: bool}>}
     */
    private function readChannel(string $salesChannelId, string $navigationCategoryId, Context $context, ?array $allowed): array
    {
        return $this->settings->read($salesChannelId, $navigationCategoryId)
            + ['shippingMethods' => $this->leadTimes->backoffice($salesChannelId, $context, $allowed)];
    }

    /**
     * 400 for a shipping method that is not assigned to the sales channel, 403 for a bound user
     * when the method is also assigned to a channel that is not one of their branches.
     *
     * @param array<string, int> $leadTimes
     */
    private function refusedLeadTimes(string $salesChannelId, array $leadTimes, Context $context): ?JsonResponse
    {
        if ($leadTimes === []) {
            return null;
        }
        $channelsByMethod = $this->leadTimes->salesChannelIds(array_keys($leadTimes));
        foreach (array_keys($leadTimes) as $shippingMethodId) {
            if (!\in_array($salesChannelId, $channelsByMethod[$shippingMethodId] ?? [], true)) {
                return $this->error(Response::HTTP_BAD_REQUEST, \sprintf('Shipping method %s is not assigned to this sales channel.', $shippingMethodId));
            }
        }
        $allowed = $this->branchBinding->salesChannelIds($context);
        foreach ($channelsByMethod as $shippingMethodId => $salesChannelIds) {
            if (!ShippingMethodLeadTimes::editable($salesChannelIds, $allowed)) {
                return $this->error(
                    Response::HTTP_FORBIDDEN,
                    \sprintf('Shipping method %s is also used by a sales channel that is not one of your branches.', $shippingMethodId),
                );
            }
        }

        return null;
    }

    /**
     * Navigation category by sales channel id (hex), all channels or one.
     *
     * @return array<string, string>
     */
    private function navigationCategories(?string $salesChannelId = null): array
    {
        $sql = 'SELECT LOWER(HEX(id)) AS id, LOWER(HEX(navigation_category_id)) AS navigation FROM sales_channel';
        /** @var list<array{id: string, navigation: string}> $rows */
        $rows = $salesChannelId === null
            ? $this->connection->fetchAllAssociative($sql)
            : $this->connection->fetchAllAssociative($sql . ' WHERE id = :id', ['id' => Uuid::fromHexToBytes($salesChannelId)]);

        return array_column($rows, 'navigation', 'id');
    }

    private function forbidden(): JsonResponse
    {
        return $this->error(Response::HTTP_FORBIDDEN, 'This sales channel is not one of your branches.');
    }

    private function error(int $status, string $detail): JsonResponse
    {
        return new JsonResponse(['errors' => [['status' => (string) $status, 'detail' => $detail]]], $status);
    }
}
