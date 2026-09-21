<?php

declare(strict_types=1);

namespace ShopBite\Service;

use Shopware\Core\Framework\Api\Acl\Role\AclRoleCollection;
use Shopware\Core\Framework\Api\Acl\Role\AclRoleEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Ships the ACL role for the Admin API integration of the ShopBite Order Printer.
 */
final readonly class OrderPrinterRoleInstaller
{
    public const string ROLE_NAME = 'order-printer';

    public const string DESCRIPTION = 'ShopBite Order Printer: finds open orders, reads deliveries for the receipt and marks printed orders as in progress. Managed by the ShopBite plugin.';

    /**
     * Exactly what the order printer's Admin API requests need:
     * - POST /api/search/order (filter on stateMachineState)
     * - POST /api/search/order-delivery (associations order, order.lineItems, order.stateMachineState,
     *   shippingOrderAddress, shippingMethod)
     * - POST /api/_action/order/{id}/state/process (guarded by order:update, state is written in system scope).
     */
    public const array PRIVILEGES = [
        'order:read',
        'order:update',
        'order_address:read',
        'order_delivery:read',
        'order_line_item:read',
        'shipping_method:read',
        'state_machine_state:read',
    ];

    /**
     * @param EntityRepository<AclRoleCollection> $aclRoleRepository
     */
    public function __construct(
        private EntityRepository $aclRoleRepository,
    ) {
    }

    public function install(Context $context): void
    {
        $role = $this->findRole($context);

        if ($role === null) {
            $this->aclRoleRepository->create([[
                'id' => Uuid::randomHex(),
                'name' => self::ROLE_NAME,
                'description' => self::DESCRIPTION,
                'privileges' => self::PRIVILEGES,
            ]], $context);

            return;
        }

        $privileges = $role->getPrivileges();
        sort($privileges);

        if ($privileges === self::PRIVILEGES && $role->getDescription() === self::DESCRIPTION) {
            return;
        }

        $this->aclRoleRepository->update([[
            'id' => $role->getId(),
            'description' => self::DESCRIPTION,
            'privileges' => self::PRIVILEGES,
        ]], $context);
    }

    public function update(Context $context): void
    {
        $this->install($context);
    }

    public function uninstall(Context $context): void
    {
        $role = $this->findRole($context);

        if ($role === null) {
            return;
        }

        $this->aclRoleRepository->delete([['id' => $role->getId()]], $context);
    }

    private function findRole(Context $context): ?AclRoleEntity
    {
        $criteria = new Criteria()
            ->addFilter(new EqualsFilter('name', self::ROLE_NAME))
            ->setLimit(1);

        return $this->aclRoleRepository->search($criteria, $context)->getEntities()->first();
    }
}
