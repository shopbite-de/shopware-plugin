<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Staff;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShopBite\Staff\BranchBinding;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Api\Context\SalesChannelApiSource;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;

#[CoversClass(BranchBinding::class)]
class BranchBindingTest extends TestCase
{
    private const string USER = '0190000000007000800000000000a001';
    private const string BRANCH_A = '0190000000007000800000000000b001';
    private const string BRANCH_B = '0190000000007000800000000000b002';

    public function testBoundUserGetsTheirBranches(): void
    {
        $binding = $this->binding(json_encode(['shopbite_sales_channel_ids' => [self::BRANCH_A, self::BRANCH_B]]));

        $this->assertSame([self::BRANCH_A, self::BRANCH_B], $binding->salesChannelIds($this->userContext()));
    }

    public function testInvalidEntriesAreDropped(): void
    {
        $binding = $this->binding(json_encode(['shopbite_sales_channel_ids' => [self::BRANCH_A, 42, 'not-an-id', strtoupper(self::BRANCH_B)]]));

        $this->assertSame([self::BRANCH_A, self::BRANCH_B], $binding->salesChannelIds($this->userContext()));
    }

    /**
     * @return iterable<string, array{string|false|null}>
     */
    public static function unboundUsers(): iterable
    {
        yield 'no custom fields' => [null];
        yield 'other custom fields' => [json_encode(['other' => true]) ?: null];
        yield 'field null' => [json_encode(['shopbite_sales_channel_ids' => null]) ?: null];
        yield 'empty list' => [json_encode(['shopbite_sales_channel_ids' => []]) ?: null];
    }

    /**
     * @return iterable<string, array{string|false}>
     */
    public static function malformedBindings(): iterable
    {
        yield 'unknown user' => [false];
        yield 'scalar' => [json_encode(['shopbite_sales_channel_ids' => self::BRANCH_A]) ?: ''];
        yield 'only invalid entries' => [json_encode(['shopbite_sales_channel_ids' => ['x', 42]]) ?: ''];
        yield 'broken json' => ['{'];
    }

    #[DataProvider('malformedBindings')]
    public function testMalformedBindingAllowsNoBranch(string|false $customFields): void
    {
        $binding = $this->binding($customFields);

        $this->assertSame([], $binding->salesChannelIds($this->userContext()));
    }

    public function testAllowsChecksTheBranches(): void
    {
        $this->assertTrue(BranchBinding::permits(null, self::BRANCH_A));
        $this->assertTrue(BranchBinding::permits([self::BRANCH_A], strtoupper(self::BRANCH_A)));
        $this->assertFalse(BranchBinding::permits([self::BRANCH_A], self::BRANCH_B));
        $this->assertFalse(BranchBinding::permits([], self::BRANCH_A));
    }

    #[DataProvider('unboundUsers')]
    public function testUserWithoutBranchesIsNotRestricted(string|false|null $customFields): void
    {
        $this->assertNull($this->binding($customFields)->salesChannelIds($this->userContext()));
    }

    public function testAdminIsNotRestricted(): void
    {
        $source = new AdminApiSource(self::USER);
        $source->setIsAdmin(true);

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('fetchOne');

        $this->assertNull(new BranchBinding($connection)->salesChannelIds(new Context($source)));
    }

    public function testIntegrationAndOtherSourcesAreNotRestricted(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('fetchOne');
        $binding = new BranchBinding($connection);

        $this->assertNull($binding->salesChannelIds(new Context(new AdminApiSource(null, '0190000000007000800000000000c001'))));
        $this->assertNull($binding->salesChannelIds(new Context(new SystemSource())));
        $this->assertNull($binding->salesChannelIds(new Context(new SalesChannelApiSource(self::BRANCH_A))));
    }

    private function userContext(): Context
    {
        return new Context(new AdminApiSource(self::USER));
    }

    private function binding(string|false|null $customFields): BranchBinding
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('fetchOne')->willReturn($customFields);

        return new BranchBinding($connection);
    }
}
