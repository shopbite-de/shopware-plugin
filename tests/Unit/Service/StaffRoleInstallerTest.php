<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ShopBite\Service\StaffRoleInstaller;
use Shopware\Core\Framework\Api\Acl\Role\AclRoleCollection;
use Shopware\Core\Framework\Api\Acl\Role\AclRoleEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;

#[CoversClass(StaffRoleInstaller::class)]
#[AllowMockObjectsWithoutExpectations]
class StaffRoleInstallerTest extends TestCase
{
    private EntityRepository&MockObject $aclRoleRepository;
    private StaffRoleInstaller $installer;
    private Context $context;

    protected function setUp(): void
    {
        $this->aclRoleRepository = $this->createMock(EntityRepository::class);
        $this->installer = new StaffRoleInstaller($this->aclRoleRepository);
        $this->context = Context::createDefaultContext();
    }

    public function testPrivilegesAreSortedAndUnique(): void
    {
        foreach ([StaffRoleInstaller::MANAGEMENT_PRIVILEGES, StaffRoleInstaller::PERSONAL_PRIVILEGES] as $privileges) {
            $sorted = array_values(array_unique($privileges));
            sort($sorted);
            static::assertSame($sorted, $privileges);
        }
    }

    public function testPersonalCannotEditTheMenu(): void
    {
        static::assertNotContains('product:update', StaffRoleInstaller::PERSONAL_PRIVILEGES);
        static::assertContains('product:update', StaffRoleInstaller::MANAGEMENT_PRIVILEGES);
        static::assertContains('order:update', StaffRoleInstaller::PERSONAL_PRIVILEGES);
    }

    public function testInstallCreatesBothRolesWithFixedIds(): void
    {
        $this->expectSearch([]);
        $this->aclRoleRepository->expects($this->once())
            ->method('upsert')
            ->with($this->callback(static function (array $data): bool {
                static::assertSame(
                    [StaffRoleInstaller::MANAGEMENT_ROLE_ID, StaffRoleInstaller::PERSONAL_ROLE_ID],
                    array_column($data, 'id'),
                );
                static::assertSame(StaffRoleInstaller::MANAGEMENT_PRIVILEGES, $data[0]['privileges']);
                static::assertSame('ShopBite Personal', $data[1]['name']);

                return true;
            }), $this->context);

        $this->installer->install($this->context);
    }

    public function testUpdateDoesNothingWhenRolesAreUpToDate(): void
    {
        $this->expectSearch(array_map(
            fn (string $id): AclRoleEntity => $this->role($id, array_reverse(StaffRoleInstaller::roles()[$id]['privileges'])),
            array_keys(StaffRoleInstaller::roles()),
        ));
        $this->aclRoleRepository->expects($this->never())->method('upsert');

        $this->installer->update($this->context);
    }

    public function testUpdateRewritesOnlyTheChangedRole(): void
    {
        $this->expectSearch([
            $this->role(StaffRoleInstaller::MANAGEMENT_ROLE_ID, StaffRoleInstaller::MANAGEMENT_PRIVILEGES),
            $this->role(StaffRoleInstaller::PERSONAL_ROLE_ID, ['order:read']),
        ]);
        $this->aclRoleRepository->expects($this->once())
            ->method('upsert')
            ->with($this->callback(static function (array $data): bool {
                static::assertCount(1, $data);
                static::assertSame(StaffRoleInstaller::PERSONAL_ROLE_ID, $data[0]['id']);
                static::assertSame(StaffRoleInstaller::PERSONAL_PRIVILEGES, $data[0]['privileges']);

                return true;
            }), $this->context);

        $this->installer->update($this->context);
    }

    public function testUninstallDeletesBothRoles(): void
    {
        $this->aclRoleRepository->expects($this->once())
            ->method('delete')
            ->with([['id' => StaffRoleInstaller::MANAGEMENT_ROLE_ID], ['id' => StaffRoleInstaller::PERSONAL_ROLE_ID]], $this->context);

        $this->installer->uninstall($this->context);
    }

    /**
     * @param list<AclRoleEntity> $roles
     */
    private function expectSearch(array $roles): void
    {
        $result = $this->createStub(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new AclRoleCollection($roles));
        $this->aclRoleRepository->expects($this->once())
            ->method('search')
            ->with($this->callback(static function (Criteria $criteria): bool {
                static::assertSame(array_keys(StaffRoleInstaller::roles()), $criteria->getIds());

                return true;
            }), $this->context)
            ->willReturn($result);
    }

    /**
     * @param list<string> $privileges
     */
    private function role(string $id, array $privileges): AclRoleEntity
    {
        $role = new AclRoleEntity();
        $role->setId($id);
        $role->setName(StaffRoleInstaller::roles()[$id]['name']);
        $role->setDescription(StaffRoleInstaller::roles()[$id]['description']);
        $role->setPrivileges($privileges);

        return $role;
    }
}
