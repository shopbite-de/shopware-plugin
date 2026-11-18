<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Passkey;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ShopBite\Passkey\Api\PasskeyRoute;
use ShopBite\Passkey\PasskeyConfig;
use ShopBite\Passkey\PasskeyService;
use ShopBite\Passkey\PasskeyStore;
use Shopware\Core\Framework\Api\ApiException;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(PasskeyRoute::class)]
#[AllowMockObjectsWithoutExpectations]
class PasskeyRouteTest extends TestCase
{
    private const string USER = '0190000000007000800000000000a002';

    private ArrayAdapter $cache;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
    }

    public function testRegistrationOptionsNeedAFreshPasswordLogin(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('user-verified');

        $this->route()->registerOptions($this->request(['write']), $this->context());
    }

    public function testRegistrationNeedsAFreshPasswordLogin(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('user-verified');

        $this->route()->register($this->request(['write'], ['name' => 'iPhone', 'credential' => '{}']), $this->context());
    }

    public function testRegistrationOptionsWithUserVerifiedToken(): void
    {
        $response = $this->route()->registerOptions($this->request(['write', 'user-verified']), $this->context());

        static::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        static::assertIsArray($body);
        static::assertSame('backoffice.example', $body['rp']['id']);
        static::assertTrue($this->cache->hasItem('shopbite_passkey_register_' . self::USER));
    }

    public function testListAndLoginOptionsNeedNoVerification(): void
    {
        static::assertSame(200, $this->route()->list($this->context())->getStatusCode());
        static::assertSame(200, $this->route()->loginOptions()->getStatusCode());
    }

    private function route(): PasskeyRoute
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')
            ->willReturn(['username' => 'chef', 'first_name' => 'Clara', 'last_name' => 'Chef', 'active' => 1]);
        $connection->method('fetchFirstColumn')->willReturn([]);
        $connection->method('fetchAllAssociative')->willReturn([]);

        $systemConfig = $this->createStub(SystemConfigService::class);
        $systemConfig->method('getString')->willReturnMap([
            ['ShopBitePlugin.config.passkeyRpId', null, 'backoffice.example'],
            ['ShopBitePlugin.config.passkeyOrigins', null, 'https://backoffice.example'],
        ]);

        return new PasskeyRoute(new PasskeyService(
            new PasskeyStore($connection),
            new PasskeyConfig($systemConfig),
            $this->cache,
            new LockFactory(new InMemoryStore()),
        ));
    }

    /**
     * @param list<string>         $scopes
     * @param array<string, mixed> $body
     */
    private function request(array $scopes, array $body = []): Request
    {
        $request = new Request(request: $body);
        $request->attributes->set(PlatformRequest::ATTRIBUTE_OAUTH_SCOPES, $scopes);

        return $request;
    }

    private function context(): Context
    {
        return new Context(new AdminApiSource(self::USER));
    }
}
