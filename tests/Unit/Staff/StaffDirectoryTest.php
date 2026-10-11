<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Staff;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ShopBite\Service\CustomFieldsInstaller;
use ShopBite\Service\StaffRoleInstaller;
use ShopBite\Staff\StaffDirectory;
use ShopBite\Staff\StaffException;
use Shopware\Core\Framework\Api\Acl\Role\AclRoleCollection;
use Shopware\Core\Framework\Api\Acl\Role\AclRoleEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\System\Language\LanguageCollection;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\User\UserCollection;
use Shopware\Core\System\User\UserEntity;

#[CoversClass(StaffDirectory::class)]
#[AllowMockObjectsWithoutExpectations]
class StaffDirectoryTest extends TestCase
{
    private const string BRANCH = '0190000000007000800000000000b001';
    private const string LOCALE = '0190000000007000800000000000c001';

    private EntityRepository&MockObject $users;
    private EntityRepository&MockObject $userRoles;
    private EntityRepository&MockObject $channels;
    private EntityRepository&MockObject $languages;
    private StaffDirectory $staff;
    private Context $context;

    protected function setUp(): void
    {
        $this->users = $this->createMock(EntityRepository::class);
        $this->userRoles = $this->createMock(EntityRepository::class);
        $this->channels = $this->createMock(EntityRepository::class);
        $this->languages = $this->createMock(EntityRepository::class);
        $this->staff = new StaffDirectory($this->users, $this->userRoles, $this->channels, $this->languages);
        $this->context = Context::createDefaultContext();

        $language = new LanguageEntity();
        $language->setId('2fbb5fe2e29a4d70aa5854ce7ce3e20b');
        $language->setLocaleId(self::LOCALE);
        $this->languages->method('search')->willReturn($this->searchResult(new LanguageCollection([$language])));
    }

    public function testCreatesManagementWithoutBranch(): void
    {
        $this->usersFound([]);
        $this->users->expects($this->once())->method('create')->with($this->callback(static function (array $data): bool {
            $user = $data[0];
            static::assertSame('inhaber', $user['username']);
            static::assertSame([['id' => StaffRoleInstaller::MANAGEMENT_ROLE_ID]], $user['aclRoles']);
            static::assertSame([CustomFieldsInstaller::SHOPBITE_SALES_CHANNEL_IDS => []], $user['customFields']);
            static::assertFalse($user['admin']);
            static::assertSame(self::LOCALE, $user['localeId']);
            static::assertSame('inhaber@staff.shopbite.invalid', $user['email']);

            return true;
        }), $this->context);

        $this->staff->create('inhaber', 'geheim-123', 'management', [], 'Anna', 'Rossi', null, $this->context);
    }

    public function testCreatesPersonalForAnExistingBranch(): void
    {
        $this->usersFound([]);
        $this->branchesFound([self::BRANCH]);
        $this->users->expects($this->once())->method('create')->with($this->callback(static function (array $data): bool {
            static::assertSame([['id' => StaffRoleInstaller::PERSONAL_ROLE_ID]], $data[0]['aclRoles']);
            static::assertSame([CustomFieldsInstaller::SHOPBITE_SALES_CHANNEL_IDS => [self::BRANCH]], $data[0]['customFields']);

            return true;
        }), $this->context);

        $this->staff->create('theke', 'geheim-123', 'personal', [self::BRANCH], '', 'theke', 'theke@example.com', $this->context);
    }

    public function testPersonalNeedsABranch(): void
    {
        $this->usersFound([]);
        $this->users->expects($this->never())->method('create');
        $this->expectException(StaffException::class);
        $this->expectExceptionMessage('at least one --branch');

        $this->staff->create('theke', 'geheim-123', 'personal', [], '', 'theke', null, $this->context);
    }

    public function testRejectsUnknownBranch(): void
    {
        $this->usersFound([]);
        $this->branchesFound([]);
        $this->users->expects($this->never())->method('create');
        $this->expectExceptionMessage('Unknown sales channel: ' . self::BRANCH);

        $this->staff->create('theke', 'geheim-123', 'personal', [self::BRANCH], '', 'theke', null, $this->context);
    }

    public function testRejectsUnknownRoleAndExistingUsername(): void
    {
        $this->usersFound([$this->user('theke', StaffRoleInstaller::PERSONAL_ROLE_ID, [self::BRANCH])]);
        $this->expectExceptionMessage('already exists');

        $this->staff->create('theke', 'geheim-123', 'personal', [self::BRANCH], '', 'theke', null, $this->context);
    }

    public function testRoleChangeRemovesTheOldRoleAndKeepsOtherCustomFields(): void
    {
        $user = $this->user('theke', StaffRoleInstaller::PERSONAL_ROLE_ID, [self::BRANCH], ['other' => 1]);
        $this->usersFound([$user]);
        $this->branchesFound([self::BRANCH]);
        $this->userRoles->expects($this->once())->method('delete')
            ->with([['userId' => $user->getId(), 'aclRoleId' => StaffRoleInstaller::PERSONAL_ROLE_ID]], $this->context);
        $this->users->expects($this->once())->method('update')->with($this->callback(static function (array $data) use ($user): bool {
            static::assertSame([
                'id' => $user->getId(),
                'aclRoles' => [['id' => StaffRoleInstaller::MANAGEMENT_ROLE_ID]],
            ], $data[0]);

            return true;
        }), $this->context);

        $this->staff->update('theke', 'management', null, null, null, null, null, null, $this->context);
    }

    public function testNewBranchesMergeIntoCustomFields(): void
    {
        $user = $this->user('chef', StaffRoleInstaller::MANAGEMENT_ROLE_ID, [], ['other' => 1]);
        $this->usersFound([$user]);
        $this->branchesFound([self::BRANCH]);
        $this->userRoles->expects($this->never())->method('delete');
        $this->users->expects($this->once())->method('update')->with($this->callback(static function (array $data): bool {
            static::assertSame(['other' => 1, CustomFieldsInstaller::SHOPBITE_SALES_CHANNEL_IDS => [self::BRANCH]], $data[0]['customFields']);
            static::assertFalse($data[0]['active']);
            static::assertArrayNotHasKey('password', $data[0]);

            return true;
        }), $this->context);

        $this->staff->update('chef', null, [self::BRANCH], null, null, null, null, false, $this->context);
    }

    public function testRemovingAllBranchesOfStaffIsRefused(): void
    {
        $this->usersFound([$this->user('theke', StaffRoleInstaller::PERSONAL_ROLE_ID, [self::BRANCH])]);
        $this->users->expects($this->never())->method('update');
        $this->expectException(StaffException::class);

        $this->staff->update('theke', null, [], null, null, null, null, null, $this->context);
    }

    public function testListShowsRoleAndBranchNames(): void
    {
        $this->usersFound([$this->user('theke', StaffRoleInstaller::PERSONAL_ROLE_ID, [self::BRANCH])]);
        $channel = new SalesChannelEntity();
        $channel->setId(self::BRANCH);
        $channel->setTranslated(['name' => 'Filiale Nord']);
        $this->channels->method('search')->willReturn($this->searchResult(new SalesChannelCollection([$channel])));

        static::assertSame([[
            'username' => 'theke',
            'name' => 'Tom Theke',
            'role' => 'personal',
            'branches' => ['Filiale Nord'],
            'active' => true,
        ]], $this->staff->list($this->context));
    }

    /**
     * @param list<UserEntity> $users
     */
    private function usersFound(array $users): void
    {
        $this->users->method('search')->willReturn($this->searchResult(new UserCollection($users)));
    }

    /**
     * @param list<string> $ids
     */
    private function branchesFound(array $ids): void
    {
        $result = $this->createStub(IdSearchResult::class);
        $result->method('getIds')->willReturn($ids);
        $this->channels->method('searchIds')->willReturn($result);
    }

    private function searchResult(object $collection): EntitySearchResult
    {
        $result = $this->createStub(EntitySearchResult::class);
        $result->method('getEntities')->willReturn($collection);

        return $result;
    }

    /**
     * @param list<string>         $branches
     * @param array<string, mixed> $customFields
     */
    private function user(string $username, string $roleId, array $branches, array $customFields = []): UserEntity
    {
        $role = new AclRoleEntity();
        $role->setId($roleId);
        $user = new UserEntity();
        $user->setId(md5($username));
        $user->setUsername($username);
        $user->setFirstName('Tom');
        $user->setLastName('Theke');
        $user->setActive(true);
        $user->setAclRoles(new AclRoleCollection([$role]));
        $user->setCustomFields([...$customFields, CustomFieldsInstaller::SHOPBITE_SALES_CHANNEL_IDS => $branches]);

        return $user;
    }
}
