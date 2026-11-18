<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Staff\Command;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ShopBite\Passkey\PasskeyStore;
use ShopBite\Staff\Command\StaffPasskeysCommand;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(StaffPasskeysCommand::class)]
#[CoversClass(PasskeyStore::class)]
#[AllowMockObjectsWithoutExpectations]
class StaffPasskeysCommandTest extends TestCase
{
    private const string USER = '0190000000007000800000000000a001';
    private const string PASSKEY = '0190000000007000800000000000d001';

    private Connection&MockObject $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
    }

    public function testListsThePasskeysOfAUser(): void
    {
        $this->userExists();
        $this->connection->method('fetchAllAssociative')->willReturn([
            ['id' => self::PASSKEY, 'name' => 'iPhone', 'createdAt' => '2026-11-01 10:00:00.000', 'lastUsedAt' => null],
        ]);
        $this->connection->expects($this->never())->method('delete');

        $tester = $this->execute(['username' => 'chef']);

        static::assertSame(Command::SUCCESS, $tester->getStatusCode());
        static::assertStringContainsString(self::PASSKEY, $tester->getDisplay());
        static::assertStringContainsString('iPhone', $tester->getDisplay());
        static::assertStringContainsString('never', $tester->getDisplay());
    }

    public function testRevokesOnePasskey(): void
    {
        $this->userExists();
        $this->connection->expects($this->once())->method('delete')
            ->with('shopbite_passkey', ['id' => Uuid::fromHexToBytes(self::PASSKEY), 'user_id' => Uuid::fromHexToBytes(self::USER)])
            ->willReturn(1);

        $tester = $this->execute(['username' => 'chef', '--revoke' => self::PASSKEY]);

        static::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testRevokingAPasskeyOfSomeoneElseFails(): void
    {
        $this->userExists();
        $this->connection->method('delete')->willReturn(0);

        static::assertSame(Command::FAILURE, $this->execute(['username' => 'chef', '--revoke' => self::PASSKEY])->getStatusCode());
        static::assertSame(Command::FAILURE, $this->execute(['username' => 'chef', '--revoke' => 'not-an-id'])->getStatusCode());
    }

    public function testRevokesAllPasskeys(): void
    {
        $this->userExists();
        $this->connection->expects($this->once())->method('executeStatement')
            ->with($this->stringContains('DELETE FROM shopbite_passkey'), ['userIds' => [Uuid::fromHexToBytes(self::USER)]])
            ->willReturn(3);

        $tester = $this->execute(['username' => 'chef', '--revoke-all' => true]);

        static::assertSame(Command::SUCCESS, $tester->getStatusCode());
        static::assertStringContainsString('3', $tester->getDisplay());
    }

    public function testUnknownUserFails(): void
    {
        $this->connection->method('fetchOne')->willReturn(false);

        static::assertSame(Command::FAILURE, $this->execute(['username' => 'nobody'])->getStatusCode());
    }

    private function userExists(): void
    {
        $this->connection->method('fetchOne')->willReturn(self::USER);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function execute(array $input): CommandTester
    {
        $tester = new CommandTester(new StaffPasskeysCommand(new PasskeyStore($this->connection)));
        $tester->execute($input);

        return $tester;
    }
}
