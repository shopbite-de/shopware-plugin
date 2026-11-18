<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Passkey;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ShopBite\Passkey\PasskeyGrant;
use Shopware\Core\Framework\Api\OAuth\Scope\UserVerifiedScope;
use Shopware\Core\Framework\Api\OAuth\Scope\WriteScope;

#[CoversClass(PasskeyGrant::class)]
class PasskeyGrantTest extends TestCase
{
    private const string USER = '0190000000007000800000000000a002';

    public function testPasskeyTokensAreNotUserVerified(): void
    {
        $client = $this->createStub(ClientEntityInterface::class);
        $repository = $this->createMock(ScopeRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('finalizeScopes')
            ->with(
                $this->callback(static fn (array $scopes): bool => self::identifiers($scopes) === ['write']),
                'password',
                $client,
                self::USER,
            )
            // Shopware keeps user-verified for password logins; the grant must drop it anyway.
            ->willReturn([new WriteScope(), new UserVerifiedScope()]);

        $scopes = PasskeyGrant::finalizeScopesForPasskey(
            $repository,
            [new WriteScope(), new UserVerifiedScope()],
            $client,
            self::USER,
        );

        static::assertSame(['write'], self::identifiers($scopes));
    }

    /**
     * @param array<ScopeEntityInterface> $scopes
     *
     * @return list<string>
     */
    private static function identifiers(array $scopes): array
    {
        return array_values(array_map(static fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(), $scopes));
    }
}
