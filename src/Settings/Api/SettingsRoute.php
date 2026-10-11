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
 * Admin API for the backoffice: the restaurant settings of one sales channel, guarded by the
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
        if (!$this->salesChannelExists($salesChannelId)) {
            return $this->error(Response::HTTP_NOT_FOUND, 'Sales channel not found.');
        }

        return new JsonResponse($this->settings->read($salesChannelId));
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
        if (!$this->salesChannelExists($salesChannelId)) {
            return $this->error(Response::HTTP_NOT_FOUND, 'Sales channel not found.');
        }
        $changes = RestaurantSettings::changesFrom($request->request->all());
        if (\is_string($changes)) {
            return $this->error(Response::HTTP_BAD_REQUEST, $changes);
        }
        $this->settings->write($salesChannelId, $changes);

        return new JsonResponse($this->settings->read($salesChannelId));
    }

    private function salesChannelExists(string $id): bool
    {
        return $this->connection->fetchOne('SELECT 1 FROM sales_channel WHERE id = :id', ['id' => Uuid::fromHexToBytes($id)]) !== false;
    }

    private function error(int $status, string $detail): JsonResponse
    {
        return new JsonResponse(['errors' => [['status' => (string) $status, 'detail' => $detail]]], $status);
    }
}
