<?php

declare(strict_types=1);

namespace ShopBite;

use Doctrine\DBAL\Connection;
use Override;

use function Psl\Type\instance_of;

use ShopBite\Fulfillment\MinuteDeliveryTimes;
use ShopBite\Service\CustomFieldsInstaller;
use ShopBite\Service\FoodLabelInstaller;
use ShopBite\Service\OrderPrinterRoleInstaller;
use ShopBite\Service\StaffRoleInstaller;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;

final class ShopBitePlugin extends Plugin
{
    #[Override]
    public function install(InstallContext $installContext): void
    {
        $this->getCustomFieldsInstaller()->install($installContext->getContext());
        $this->getOrderPrinterRoleInstaller()->install($installContext->getContext());
        $this->getStaffRoleInstaller()->install($installContext->getContext());
        $this->getFoodLabelInstaller()->install($installContext->getContext());
    }

    #[Override]
    public function uninstall(UninstallContext $uninstallContext): void
    {
        // Shopware deactivates the plugin first; this refuses again for an inactive plugin that
        // got minute delivery times since, before anything is removed.
        $this->getMinuteDeliveryTimes()->assertNotInUse('uninstalled');
        $this->getMinuteDeliveryTimes()->deactivate();

        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $this->getMinuteDeliveryTimes()->dropTable();
        $this->getCustomFieldsInstaller()->uninstall($uninstallContext->getContext());
        $this->getOrderPrinterRoleInstaller()->uninstall($uninstallContext->getContext());
        $this->getStaffRoleInstaller()->uninstall($uninstallContext->getContext());
    }

    /**
     * Shopware removes the migration records before uninstall() (without keep user data), so the
     * check that refuses the uninstall runs here first: a refused uninstall keeps them and a later
     * update does not run every migration again.
     */
    #[Override]
    public function removeMigrations(): void
    {
        $this->getMinuteDeliveryTimes()->assertNotInUse('uninstalled');

        parent::removeMigrations();
    }

    #[Override]
    public function activate(ActivateContext $activateContext): void
    {
        $this->getCustomFieldsInstaller()->addRelations($activateContext->getContext());
        $this->getMinuteDeliveryTimes()->activate();
    }

    /**
     * Shopware core does not know the delivery time unit minute: the shipping methods the plugin
     * moved to minutes get their previous delivery time back, refused while anything else uses one.
     */
    #[Override]
    public function deactivate(DeactivateContext $deactivateContext): void
    {
        $this->getMinuteDeliveryTimes()->deactivate();
    }

    #[Override]
    public function update(UpdateContext $updateContext): void
    {
        $this->getCustomFieldsInstaller()->update($updateContext->getContext());
        $this->getOrderPrinterRoleInstaller()->update($updateContext->getContext());
        $this->getStaffRoleInstaller()->update($updateContext->getContext());
        $this->getFoodLabelInstaller()->update($updateContext->getContext());
    }

    /**
     * @psalm-suppress PossiblyNullReference The container is set before any lifecycle method runs
     */
    private function getMinuteDeliveryTimes(): MinuteDeliveryTimes
    {
        return new MinuteDeliveryTimes(instance_of(Connection::class)->coerce($this->container->get(Connection::class)));
    }

    private function getCustomFieldsInstaller(): CustomFieldsInstaller
    {
        if ($this->container->has(CustomFieldsInstaller::class)) {
            return instance_of(CustomFieldsInstaller::class)->coerce($this->container->get(CustomFieldsInstaller::class));
        }

        return new CustomFieldsInstaller(
            $this->container->get('custom_field_set.repository'),
            $this->container->get('custom_field_set_relation.repository')
        );
    }

    /**
     * @psalm-suppress PossiblyNullReference The container is set before any lifecycle method runs
     * @psalm-suppress ArgumentTypeCoercion acl_role.repository is an EntityRepository<AclRoleCollection>
     */
    private function getOrderPrinterRoleInstaller(): OrderPrinterRoleInstaller
    {
        if ($this->container->has(OrderPrinterRoleInstaller::class)) {
            return instance_of(OrderPrinterRoleInstaller::class)->coerce($this->container->get(OrderPrinterRoleInstaller::class));
        }

        return new OrderPrinterRoleInstaller($this->container->get('acl_role.repository'));
    }

    /**
     * @psalm-suppress PossiblyNullReference The container is set before any lifecycle method runs
     * @psalm-suppress ArgumentTypeCoercion acl_role.repository is an EntityRepository<AclRoleCollection>
     */
    private function getStaffRoleInstaller(): StaffRoleInstaller
    {
        if ($this->container->has(StaffRoleInstaller::class)) {
            return instance_of(StaffRoleInstaller::class)->coerce($this->container->get(StaffRoleInstaller::class));
        }

        return new StaffRoleInstaller($this->container->get('acl_role.repository'));
    }

    /**
     * @psalm-suppress PossiblyNullReference The container is set before any lifecycle method runs
     * @psalm-suppress ArgumentTypeCoercion property_group.repository is an EntityRepository<PropertyGroupCollection>
     */
    private function getFoodLabelInstaller(): FoodLabelInstaller
    {
        if ($this->container->has(FoodLabelInstaller::class)) {
            return instance_of(FoodLabelInstaller::class)->coerce($this->container->get(FoodLabelInstaller::class));
        }

        return new FoodLabelInstaller($this->container->get('property_group.repository'));
    }
}
