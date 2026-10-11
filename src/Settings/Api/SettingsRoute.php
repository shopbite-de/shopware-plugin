<?php

declare(strict_types=1);

namespace ShopBite\Settings\Api;

use Doctrine\DBAL\Connection;
use ShopBite\Settings\RestaurantSettings;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin API for the backoffice: the restaurant settings of the sales channels, guarded by the
 * plugin's own privileges shopbite_settings:read / shopbite_settings:update instead of write
 * access to the whole system configuration (plugin#34).
 *
 * @psalm-suppress UnusedClass Registered through routes.xml
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => ['api']])]
final readonly class SettingsRoute
{
    public const string READ_PRIVILEGE = 'shopbite_settings:read';

    public const string UPDATE_PRIVILEGE = 'shopbite_settings:update';

    public function __construct(
        private RestaurantSettings $settings,
        private Connection $connection,
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
    public function list(): JsonResponse
    {
        $channels = [];
        foreach ($this->navigationCategories() as $id => $navigationCategoryId) {
            $channels[$id] = $this->settings->read($id, $navigationCategoryId);
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
    public function read(string $salesChannelId): JsonResponse
    {
        $navigationCategoryId = $this->navigationCategories($salesChannelId)[$salesChannelId] ?? null;
        if ($navigationCategoryId === null) {
            return $this->error(Response::HTTP_NOT_FOUND, 'Sales channel not found.');
        }

        return new JsonResponse($this->settings->read($salesChannelId, $navigationCategoryId));
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    #[Route(
        path: '/api/_action/shopbite/settings/{salesChannelId}',
        name: 'api.action.shopbite.settings.update',
        requirements: ['salesChannelId' => '[0-9a-f]{32}'],
        defaults: ['_acl' => [self::UPDATE_PRIVILEGE]],
        methods: ['PATCH'],
    )]
    public function update(string $salesChannelId, Request $request): JsonResponse
    {
        $navigationCategoryId = $this->navigationCategories($salesChannelId)[$salesChannelId] ?? null;
        if ($navigationCategoryId === null) {
            return $this->error(Response::HTTP_NOT_FOUND, 'Sales channel not found.');
        }
        $changes = RestaurantSettings::changesFrom($request->request->all());
        if (\is_string($changes)) {
            return $this->error(Response::HTTP_BAD_REQUEST, $changes);
        }
        $this->settings->write($salesChannelId, $changes);

        return new JsonResponse($this->settings->read($salesChannelId, $navigationCategoryId));
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

    private function error(int $status, string $detail): JsonResponse
    {
        return new JsonResponse(['errors' => [['status' => (string) $status, 'detail' => $detail]]], $status);
    }
}
