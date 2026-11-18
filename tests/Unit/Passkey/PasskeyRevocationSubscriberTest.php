<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Passkey;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ShopBite\Passkey\PasskeyRevocationSubscriber;
use ShopBite\Passkey\PasskeyStore;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\User\UserEvents;

#[CoversClass(PasskeyRevocationSubscriber::class)]
#[CoversClass(PasskeyStore::class)]
class PasskeyRevocationSubscriberTest extends TestCase
{
    private const string USER_A = '0190000000007000800000000000a001';
    private const string USER_B = '0190000000007000800000000000a002';
    private const string USER_C = '0190000000007000800000000000a003';

    public function testListensToUserWrites(): void
    {
        static::assertSame(
            [UserEvents::USER_WRITTEN_EVENT => 'onUserWritten'],
            PasskeyRevocationSubscriber::getSubscribedEvents(),
        );
    }

    public function testPasskeysGoWithAPasswordChangeOrDeactivation(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('DELETE FROM shopbite_passkey WHERE user_id IN'),
                ['userIds' => [Uuid::fromHexToBytes(self::USER_A), Uuid::fromHexToBytes(self::USER_B)]],
                ['userIds' => ArrayParameterType::BINARY],
            )
            ->willReturn(2);

        new PasskeyRevocationSubscriber(new PasskeyStore($connection))->onUserWritten($this->written([
            $this->writeResult(self::USER_A, ['password' => '$2y$10$hash']),
            $this->writeResult(self::USER_B, ['active' => false]),
            $this->writeResult(self::USER_A, ['active' => false]),
            $this->writeResult(self::USER_C, ['active' => true, 'firstName' => 'Clara']),
            $this->writeResult(self::USER_C, ['password' => '$2y$10$hash', 'active' => false], EntityWriteResult::OPERATION_INSERT),
        ]));
    }

    public function testNothingHappensWithoutRelevantChanges(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('executeStatement');

        new PasskeyRevocationSubscriber(new PasskeyStore($connection))->onUserWritten($this->written([
            $this->writeResult(self::USER_A, ['email' => 'a@example.com', 'active' => true]),
        ]));
    }

    /**
     * @param list<EntityWriteResult> $results
     */
    private function written(array $results): EntityWrittenEvent
    {
        return new EntityWrittenEvent('user', $results, Context::createDefaultContext());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeResult(string $id, array $payload, string $operation = EntityWriteResult::OPERATION_UPDATE): EntityWriteResult
    {
        return new EntityWriteResult($id, ['id' => $id, ...$payload], 'user', $operation);
    }
}
