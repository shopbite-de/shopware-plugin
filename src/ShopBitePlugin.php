<?php

declare(strict_types=1);

namespace ShopBite;

use Override;

use function Psl\Type\instance_of;

use ShopBite\Service\CustomFieldsInstaller;
use ShopBite\Service\OrderPrinterRoleInstaller;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
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
    }

    #[Override]
    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $this->getCustomFieldsInstaller()->uninstall($uninstallContext->getContext());
        $this->getOrderPrinterRoleInstaller()->uninstall($uninstallContext->getContext());
    }

    #[Override]
    public function activate(ActivateContext $activateContext): void
    {
        $this->getCustomFieldsInstaller()->addRelations($activateContext->getContext());
    }

    #[Override]
    public function update(UpdateContext $updateContext): void
    {
        $this->getCustomFieldsInstaller()->update($updateContext->getContext());
        $this->getOrderPrinterRoleInstaller()->update($updateContext->getContext());
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
}
