<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ShopBite\Service\OrderPrinterRoleInstaller;
use Shopware\Core\Framework\Api\Acl\Role\AclRoleCollection;
use Shopware\Core\Framework\Api\Acl\Role\AclRoleEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

#[CoversClass(OrderPrinterRoleInstaller::class)]
class OrderPrinterRoleInstallerTest extends TestCase
{
    private const array EXPECTED_PRIVILEGES = [
        'order:read',
        'order:update',
        'order_address:read',
        'order_delivery:read',
        'order_line_item:read',
        'shipping_method:read',
        'state_machine_state:read',
    ];

    private EntityRepository&MockObject $aclRoleRepository;
    private OrderPrinterRoleInstaller $installer;
    private Context $context;

    protected function setUp(): void
    {
        $this->aclRoleRepository = $this->createMock(EntityRepository::class);
        $this->installer = new OrderPrinterRoleInstaller($this->aclRoleRepository);
        $this->context = Context::createDefaultContext();
    }

    public function testInstallCreatesRoleWhenMissing(): void
    {
        $this->expectSearch(null);

        $this->aclRoleRepository->expects($this->once())
            ->method('create')
            ->with($this->callback(static function (array $data): bool {
                static::assertCount(1, $data);
                static::assertTrue(Uuid::isValid($data[0]['id']));
                static::assertSame('order-printer', $data[0]['name']);
                static::assertSame(self::EXPECTED_PRIVILEGES, $data[0]['privileges']);
                static::assertNotEmpty($data[0]['description']);

                return true;
            }), $this->context);
        $this->aclRoleRepository->expects($this->never())->method('update');

        $this->installer->install($this->context);
    }

    public function testInstallIsIdempotentWhenRoleIsUpToDate(): void
    {
        $existing = $this->createRole(array_reverse(self::EXPECTED_PRIVILEGES));
        $existing->setDescription(OrderPrinterRoleInstaller::DESCRIPTION);
        $this->expectSearch($existing);

        $this->aclRoleRepository->expects($this->never())->method('create');
        $this->aclRoleRepository->expects($this->never())->method('update');

        $this->installer->update($this->context);
    }

    public function testUpdateReplacesChangedPrivilegesOfExistingRole(): void
    {
        $existing = $this->createRole(['order:read', 'customer:read']);
        $this->expectSearch($existing);

        $this->aclRoleRepository->expects($this->never())->method('create');
        $this->aclRoleRepository->expects($this->once())
            ->method('update')
            ->with($this->callback(static function (array $data) use ($existing): bool {
                static::assertCount(1, $data);
                static::assertSame($existing->getId(), $data[0]['id']);
                static::assertSame(self::EXPECTED_PRIVILEGES, $data[0]['privileges']);
                static::assertArrayNotHasKey('name', $data[0]);

                return true;
            }), $this->context);

        $this->installer->update($this->context);
    }

    public function testUninstallDeletesExistingRole(): void
    {
        $existing = $this->createRole(self::EXPECTED_PRIVILEGES);
        $this->expectSearch($existing);

        $this->aclRoleRepository->expects($this->once())
            ->method('delete')
            ->with([['id' => $existing->getId()]], $this->context);

        $this->installer->uninstall($this->context);
    }

    public function testUninstallDoesNothingWithoutRole(): void
    {
        $this->expectSearch(null);

        $this->aclRoleRepository->expects($this->never())->method('delete');

        $this->installer->uninstall($this->context);
    }

    private function expectSearch(?AclRoleEntity $role): void
    {
        $collection = new AclRoleCollection($role === null ? [] : [$role]);
        $result = $this->createStub(EntitySearchResult::class);
        $result->method('getEntities')->willReturn($collection);

        $this->aclRoleRepository->expects($this->once())
            ->method('search')
            ->with($this->callback(static function (Criteria $criteria): bool {
                $filters = $criteria->getFilters();
                static::assertCount(1, $filters);
                static::assertInstanceOf(EqualsFilter::class, $filters[0]);
                static::assertSame('name', $filters[0]->getField());
                static::assertSame('order-printer', $filters[0]->getValue());

                return true;
            }), $this->context)
            ->willReturn($result);
    }

    /**
     * @param list<string> $privileges
     */
    private function createRole(array $privileges): AclRoleEntity
    {
        $role = new AclRoleEntity();
        $role->setId(Uuid::randomHex());
        $role->setName('order-printer');
        $role->setPrivileges($privileges);

        return $role;
    }

}
