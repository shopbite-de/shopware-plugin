<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ShopBite\Fulfillment\MinuteDeliveryTimes;
use ShopBite\Fulfillment\MinuteDeliveryTimesException;
use ShopBite\Service\CustomFieldsInstaller;
use ShopBite\Service\OrderPrinterRoleInstaller;
use ShopBite\Service\StaffRoleInstaller;
use ShopBite\ShopBitePlugin;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Migration\MigrationCollection;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Symfony\Component\DependencyInjection\Container;

#[CoversClass(ShopBitePlugin::class)]
#[UsesClass(MinuteDeliveryTimes::class)]
#[UsesClass(MinuteDeliveryTimesException::class)]
#[UsesClass(CustomFieldsInstaller::class)]
#[UsesClass(OrderPrinterRoleInstaller::class)]
#[UsesClass(StaffRoleInstaller::class)]
#[AllowMockObjectsWithoutExpectations]
class ShopBitePluginTest extends TestCase
{
    /** @var list<string> */
    private array $statements = [];

    /** @var list<array<string, mixed>> what blocks: rows of the blocker queries */
    private array $blockers = [];

    /** @var EntityRepository<\Shopware\Core\Framework\Api\Acl\Role\AclRoleCollection>&MockObject */
    private EntityRepository&MockObject $aclRoles;

    private ShopBitePlugin $plugin;

    protected function setUp(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(MinuteDeliveryTimes::TABLE);
        $connection->method('fetchAllAssociative')->willReturnCallback(fn (string $sql): array => str_contains($sql, 'FROM product') ? $this->blockers : []);
        $connection->method('executeStatement')->willReturnCallback(function (string $sql): int {
            $this->statements[] = $sql;

            return 1;
        });
        $this->aclRoles = $this->createMock(EntityRepository::class);

        $container = new Container();
        $container->set(Connection::class, $connection);
        $container->set(CustomFieldsInstaller::class, new CustomFieldsInstaller($this->createStub(EntityRepository::class), $this->createStub(EntityRepository::class)));
        $container->set(OrderPrinterRoleInstaller::class, new OrderPrinterRoleInstaller($this->aclRoles));
        $container->set(StaffRoleInstaller::class, new StaffRoleInstaller($this->aclRoles));

        $this->plugin = new ShopBitePlugin(true, __DIR__);
        $this->plugin->setContainer($container);
    }

    public function testRemoveMigrationsIsRefusedFirstWhileMinutesAreInUse(): void
    {
        // Shopware removes the migrations before uninstall(): a refused uninstall must keep them.
        $this->blockers = [['name' => 'P1', 'total' => 1]];

        $this->expectException(MinuteDeliveryTimesException::class);
        $this->expectExceptionMessage('ShopBite cannot be uninstalled');

        $this->plugin->removeMigrations();
    }

    public function testUninstallKeepingUserDataIsRefusedBeforeAnythingIsRemoved(): void
    {
        $this->blockers = [['name' => 'P1', 'total' => 1]];
        $this->aclRoles->expects($this->never())->method('delete');

        try {
            $this->plugin->uninstall($this->uninstallContext(true));
            $this->fail('uninstall must be refused');
        } catch (MinuteDeliveryTimesException $exception) {
            $this->assertStringContainsString('cannot be uninstalled', $exception->getMessage());
        }
        $this->assertSame([], $this->statements);
    }

    public function testUninstallRestoresTheDeliveryTimesThenDropsTheTable(): void
    {
        $this->aclRoles->expects($this->atLeastOnce())->method('delete');

        $this->plugin->uninstall($this->uninstallContext(false));

        $this->assertCount(3, $this->statements);
        $this->assertStringContainsString('remembered.previous_delivery_time_id', $this->statements[1]);
        $this->assertSame('DROP TABLE IF EXISTS shopbite_shipping_method_delivery_time', $this->statements[2]);
    }

    public function testUninstallKeepingUserDataKeepsTheTable(): void
    {
        $this->aclRoles->expects($this->never())->method('delete');

        $this->plugin->uninstall($this->uninstallContext(true));

        $this->assertNotContains('DROP TABLE IF EXISTS shopbite_shipping_method_delivery_time', $this->statements);
    }

    public function testDeactivateIsRefusedWhileMinutesAreInUse(): void
    {
        $this->blockers = [['name' => 'P1', 'total' => 1]];

        $this->expectException(MinuteDeliveryTimesException::class);
        $this->expectExceptionMessage('ShopBite cannot be deactivated');

        $this->plugin->deactivate(new DeactivateContext($this->plugin, Context::createDefaultContext(), '6.7.16.1', '1.12.0', $this->createStub(MigrationCollection::class)));
    }

    public function testActivateAssignsTheMinuteDeliveryTimes(): void
    {
        $this->plugin->activate(new ActivateContext($this->plugin, Context::createDefaultContext(), '6.7.16.1', '1.12.0', $this->createStub(MigrationCollection::class)));

        $this->assertCount(1, $this->statements);
        $this->assertStringContainsString('remembered.minute_delivery_time_id', $this->statements[0]);
    }

    private function uninstallContext(bool $keepUserData): UninstallContext
    {
        return new UninstallContext($this->plugin, Context::createDefaultContext(), '6.7.16.1', '1.12.0', $this->createStub(MigrationCollection::class), $keepUserData);
    }
}
