<?php

declare(strict_types=1);

namespace ShopBite\Passkey\Api;

use ShopBite\Passkey\PasskeyException;
use ShopBite\Passkey\PasskeyService;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin API for the backoffice's passkeys. A logged-in user manages only their own passkeys
 * (no ACL privilege needed); the login options are open, the login itself is the
 * shopbite_passkey grant on /api/oauth/token.
 *
 * @psalm-suppress UnusedClass Registered through routes.xml
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => ['api']])]
final readonly class PasskeyRoute
{
    public function __construct(private PasskeyService $passkeys)
    {
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    #[Route(path: '/api/_action/shopbite/passkey', name: 'api.action.shopbite.passkey.list', methods: ['GET'])]
    public function list(Context $context): JsonResponse
    {
        return new JsonResponse([
            'enabled' => $this->passkeys->enabled(),
            'passkeys' => $this->passkeys->list($this->userId($context)),
        ]);
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    #[Route(path: '/api/_action/shopbite/passkey/register-options', name: 'api.action.shopbite.passkey.register-options', methods: ['POST'])]
    public function registerOptions(Context $context): JsonResponse
    {
        return $this->guard(fn (): array => $this->passkeys->registrationOptions($this->userId($context)));
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    #[Route(path: '/api/_action/shopbite/passkey/register', name: 'api.action.shopbite.passkey.register', methods: ['POST'])]
    public function register(Request $request, Context $context): JsonResponse
    {
        $credential = $request->request->all()['credential'] ?? null;
        if (\is_array($credential)) {
            $credential = json_encode($credential, \JSON_THROW_ON_ERROR);
        }
        $name = $request->request->getString('name');

        return $this->guard(fn (): array => [
            'id' => $this->passkeys->register($this->userId($context), \is_string($credential) ? $credential : '', $name),
        ]);
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    #[Route(path: '/api/_action/shopbite/passkey/{id}', name: 'api.action.shopbite.passkey.delete', requirements: ['id' => '[0-9a-f]{32}'], methods: ['DELETE'])]
    public function delete(string $id, Context $context): Response
    {
        return $this->passkeys->delete($id, $this->userId($context))
            ? new Response(null, Response::HTTP_NO_CONTENT)
            : new JsonResponse(['errors' => [['detail' => 'Passkey not found.']]], Response::HTTP_NOT_FOUND);
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    #[Route(
        path: '/api/_action/shopbite/passkey/login-options',
        name: 'api.action.shopbite.passkey.login-options',
        defaults: ['auth_required' => false],
        methods: ['POST'],
    )]
    public function loginOptions(): JsonResponse
    {
        return $this->guard(fn (): array => $this->passkeys->loginOptions());
    }

    private function userId(Context $context): string
    {
        $source = $context->getSource();
        $userId = $source instanceof AdminApiSource ? $source->getUserId() : null;
        if ($userId === null || !Uuid::isValid($userId)) {
            throw new PasskeyException('Passkeys belong to users, not integrations.');
        }

        return $userId;
    }

    /**
     * @param callable(): array<string, mixed> $action
     */
    private function guard(callable $action): JsonResponse
    {
        try {
            return new JsonResponse($action());
        } catch (PasskeyException $e) {
            return new JsonResponse(['errors' => [['detail' => $e->getMessage()]]], Response::HTTP_BAD_REQUEST);
        }
    }
}
