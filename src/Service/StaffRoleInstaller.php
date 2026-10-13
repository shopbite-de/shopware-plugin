<?php

declare(strict_types=1);

namespace ShopBite\Service;

use Shopware\Core\Framework\Api\Acl\Role\AclRoleCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * Ships the ACL roles for the people of a restaurant who work in the ShopBite backoffice
 * (https://github.com/shopbite-de/backoffice). The backoffice logs in with the person's own
 * Shopware user, so these roles decide what that person may do.
 *
 * - Management: the whole menu (dishes, prices, variants, extras, sections, SEO), opening hours, orders.
 * - Personal: today's orders, done/cancel, pausing online orders and the delivery time.
 *
 * Fixed ids, so users created with shopbite:staff:create keep their role across updates.
 */
final readonly class StaffRoleInstaller
{
    public const string MANAGEMENT_ROLE_ID = '269557440406425e9dc87470cd0e9ffb';

    public const string PERSONAL_ROLE_ID = '493f47fa65684ad8829c852f523fe45c';

    public const string MANAGEMENT_ROLE_NAME = 'ShopBite Management';

    public const string PERSONAL_ROLE_NAME = 'ShopBite Personal';

    /**
     * Everything the backoffice requests for the menu, opening hours, orders and settings.
     * Every association or filter path of a search needs <entity>:read (AclCriteriaValidator).
     * Ordering and delivery time go through the plugin's settings route (shopbite_settings:*).
     */
    public const array MANAGEMENT_PRIVILEGES = [
        'category:create',
        'category:read',
        'category:update',
        'currency:read',
        'order:read',
        'order:update',
        'order_address:read',
        'order_customer:read',
        'order_delivery:read',
        'order_line_item:read',
        'order_transaction:read',
        'payment_method:read',
        'product:create',
        'product:delete',
        'product:read',
        'product:update',
        'product_category:create',
        'product_category:delete',
        'product_configurator_setting:create',
        'product_configurator_setting:delete',
        'product_configurator_setting:read',
        'product_configurator_setting:update',
        'product_cross_selling:create',
        'product_cross_selling:delete',
        'product_cross_selling:read',
        'product_cross_selling:update',
        'product_cross_selling_assigned_products:create',
        'product_cross_selling_assigned_products:delete',
        'product_cross_selling_assigned_products:read',
        'product_cross_selling_assigned_products:update',
        'product_option:create',
        'product_option:delete',
        'product_property:create',
        'product_property:delete',
        'product_stream:create',
        'product_stream:delete',
        'product_stream:read',
        'product_stream:update',
        'product_stream_filter:create',
        'product_stream_filter:read',
        'product_stream_filter:update',
        'product_tag:create',
        'product_tag:delete',
        'product_visibility:create',
        'product_visibility:delete',
        'product_visibility:read',
        'product_visibility:update',
        'property_group:create',
        'property_group:read',
        'property_group:update',
        'property_group_option:create',
        'property_group_option:read',
        'property_group_option:update',
        'sales_channel:read',
        'seo_url:create',
        'seo_url:read',
        'seo_url:update',
        'shipping_method:read',
        'shopbite_business_hour:create',
        'shopbite_business_hour:delete',
        'shopbite_business_hour:read',
        'shopbite_settings:read',
        'shopbite_settings:update',
        'state_machine:read',
        'state_machine_history:create',
        'state_machine_state:read',
        'state_machine_transition:read',
        'tag:create',
        'tag:read',
        'tax:read',
    ];

    /**
     * Today's orders with deliveries and line items, the order state transitions, and the
     * ordering settings per sales channel (plugin settings route, no system_config).
     */
    public const array PERSONAL_PRIVILEGES = [
        'order:read',
        'order:update',
        'order_address:read',
        'order_customer:read',
        'order_delivery:read',
        'order_line_item:read',
        'order_transaction:read',
        'payment_method:read',
        'sales_channel:read',
        'shipping_method:read',
        'shopbite_settings:read',
        'shopbite_settings:update',
        'state_machine:read',
        'state_machine_history:create',
        'state_machine_state:read',
        'state_machine_transition:read',
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
        $existing = $this->aclRoleRepository
            ->search(new Criteria(array_keys(self::roles())), $context)
            ->getEntities();

        $writes = [];
        foreach (self::roles() as $id => $role) {
            $current = $existing->get($id);
            if ($current !== null) {
                $privileges = $current->getPrivileges();
                sort($privileges);
                if ($privileges === $role['privileges']
                    && $current->getName() === $role['name']
                    && $current->getDescription() === $role['description']) {
                    continue;
                }
            }
            $writes[] = ['id' => $id, ...$role];
        }

        if ($writes !== []) {
            $this->aclRoleRepository->upsert($writes, $context);
        }
    }

    public function update(Context $context): void
    {
        $this->install($context);
    }

    public function uninstall(Context $context): void
    {
        $this->aclRoleRepository->delete(
            array_map(static fn (string $id): array => ['id' => $id], array_keys(self::roles())),
            $context,
        );
    }

    /**
     * @return array<string, array{name: string, description: string, privileges: list<string>}>
     */
    public static function roles(): array
    {
        return [
            self::MANAGEMENT_ROLE_ID => [
                'name' => self::MANAGEMENT_ROLE_NAME,
                'description' => 'ShopBite backoffice: menu, prices, opening hours and orders of all branches. Managed by the ShopBite plugin.',
                'privileges' => self::MANAGEMENT_PRIVILEGES,
            ],
            self::PERSONAL_ROLE_ID => [
                'name' => self::PERSONAL_ROLE_NAME,
                'description' => 'ShopBite backoffice: today\'s orders, pausing online orders and the delivery time of the own branch. Managed by the ShopBite plugin.',
                'privileges' => self::PERSONAL_PRIVILEGES,
            ],
        ];
    }
}
