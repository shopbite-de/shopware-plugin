<?php

declare(strict_types=1);

namespace ShopBite\Staff;

use ShopBite\Service\CustomFieldsInstaller;
use ShopBite\Service\StaffRoleInstaller;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Util\Random;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Language\LanguageCollection;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\User\UserCollection;
use Shopware\Core\System\User\UserEntity;

/**
 * Accounts for the people of a restaurant who work in the ShopBite backoffice: a Shopware
 * user with one of the two staff roles and the branches (sales channels) the person works for.
 * Used by the shopbite:staff:* commands; the backoffice itself cannot create users (that needs
 * a token with the user-verified scope).
 */
final readonly class StaffDirectory
{
    public const array ROLES = [
        'management' => StaffRoleInstaller::MANAGEMENT_ROLE_ID,
        'personal' => StaffRoleInstaller::PERSONAL_ROLE_ID,
    ];

    /**
     * @param EntityRepository<UserCollection>         $userRepository
     * @param EntityRepository<EntityCollection<Entity>> $aclUserRoleRepository (mapping acl_user_role)
     * @param EntityRepository<SalesChannelCollection> $salesChannelRepository
     * @param EntityRepository<LanguageCollection>     $languageRepository
     *
     * @psalm-suppress PossiblyUnusedMethod Constructed by the DI container
     */
    public function __construct(
        private EntityRepository $userRepository,
        private EntityRepository $aclUserRoleRepository,
        private EntityRepository $salesChannelRepository,
        private EntityRepository $languageRepository,
    ) {
    }

    public static function generatePassword(): string
    {
        return Random::getAlphanumericString(16);
    }

    /**
     * @param list<string> $branches sales channel ids
     */
    public function create(
        string $username,
        string $password,
        string $role,
        array $branches,
        string $firstName,
        string $lastName,
        ?string $email,
        Context $context,
    ): void {
        if ($this->find($username, $context) !== null) {
            throw new StaffException(\sprintf('A user "%s" already exists.', $username));
        }
        $roleId = $this->roleId($role);
        $this->assertBranches($role, $branches, $context);

        $id = Uuid::randomHex();
        $this->userRepository->create([[
            'id' => $id,
            'username' => $username,
            'password' => $password,
            'firstName' => $firstName,
            'lastName' => $lastName,
            // Shopware requires a unique address; .invalid never receives mail
            'email' => $email ?? $username . '@staff.shopbite.invalid',
            'localeId' => $this->systemLocaleId($context),
            'admin' => false,
            'active' => true,
            'aclRoles' => [['id' => $roleId]],
            'customFields' => [CustomFieldsInstaller::SHOPBITE_SALES_CHANNEL_IDS => $branches],
        ]], $context);
    }

    /**
     * Changes only what is given (null = keep).
     *
     * @param list<string>|null $branches
     */
    public function update(
        string $username,
        ?string $role,
        ?array $branches,
        ?string $password,
        ?string $firstName,
        ?string $lastName,
        ?string $email,
        ?bool $active,
        Context $context,
    ): void {
        $user = $this->find($username, $context)
            ?? throw new StaffException(\sprintf('There is no user "%s".', $username));
        $currentRole = $this->roleOf($user);
        if ($currentRole === null && $role === null) {
            throw new StaffException(\sprintf('"%s" has no staff role; pass --role to give it one.', $username));
        }
        $newRole = $role ?? $currentRole;
        $newBranches = $branches ?? $this->branchesOf($user);
        $this->assertBranches($newRole, $newBranches, $context);

        if ($role !== null && $role !== $currentRole) {
            $stale = array_values(array_filter(
                self::ROLES,
                static fn (string $id): bool => $id !== self::ROLES[$role],
            ));
            $this->aclUserRoleRepository->delete(
                array_map(static fn (string $roleId): array => ['userId' => $user->getId(), 'aclRoleId' => $roleId], $stale),
                $context,
            );
        }

        $this->userRepository->update([array_filter([
            'id' => $user->getId(),
            'password' => $password,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'email' => $email,
            'active' => $active,
            'aclRoles' => $role !== null ? [['id' => $this->roleId($role)]] : null,
            'customFields' => $branches !== null
                ? [...($user->getCustomFields() ?? []), CustomFieldsInstaller::SHOPBITE_SALES_CHANNEL_IDS => $branches]
                : null,
        ], static fn (mixed $value): bool => $value !== null)], $context);
    }

    /**
     * @return list<array{username: string, name: string, role: string, branches: list<string>, active: bool}>
     */
    public function list(Context $context): array
    {
        $criteria = new Criteria()
            ->addAssociation('aclRoles')
            ->addFilter(new EqualsAnyFilter('aclRoles.id', array_values(self::ROLES)))
            ->addSorting(new FieldSorting('username'));
        $users = $this->userRepository->search($criteria, $context)->getEntities();

        $channelIds = [];
        foreach ($users as $user) {
            $channelIds = [...$channelIds, ...$this->branchesOf($user)];
        }
        $channelIds = array_values(array_unique($channelIds));
        $names = [];
        if ($channelIds !== []) {
            foreach ($this->salesChannelRepository->search(new Criteria($channelIds), $context)->getEntities() as $channel) {
                $names[$channel->getId()] = (string) $channel->getTranslation('name');
            }
        }

        $rows = [];
        foreach ($users as $user) {
            $rows[] = [
                'username' => $user->getUsername(),
                'name' => trim($user->getFirstName() . ' ' . $user->getLastName()),
                'role' => $this->roleOf($user) ?? '',
                'branches' => array_map(static fn (string $id): string => $names[$id] ?? $id, $this->branchesOf($user)),
                'active' => $user->getActive(),
            ];
        }

        return $rows;
    }

    private function find(string $username, Context $context): ?UserEntity
    {
        $criteria = new Criteria()
            ->addFilter(new EqualsFilter('username', $username))
            ->addAssociation('aclRoles')
            ->setLimit(1);

        return $this->userRepository->search($criteria, $context)->getEntities()->first();
    }

    private function roleId(string $role): string
    {
        return self::ROLES[$role]
            ?? throw new StaffException(\sprintf('Unknown role "%s", use %s.', $role, implode(' or ', array_keys(self::ROLES))));
    }

    private function roleOf(UserEntity $user): ?string
    {
        foreach (self::ROLES as $name => $id) {
            if ($user->getAclRoles()?->has($id)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function branchesOf(UserEntity $user): array
    {
        $value = $user->getCustomFields()[CustomFieldsInstaller::SHOPBITE_SALES_CHANNEL_IDS] ?? [];

        return \is_array($value) ? array_values(array_filter($value, \is_string(...))) : [];
    }

    /**
     * Staff must belong to a branch (the backoffice refuses them otherwise); management
     * without a branch sees all.
     *
     * @param list<string> $branches
     */
    private function assertBranches(string $role, array $branches, Context $context): void
    {
        $this->roleId($role);
        if ($role === 'personal' && $branches === []) {
            throw new StaffException('Staff (role personal) needs at least one --branch.');
        }
        if ($branches === []) {
            return;
        }
        foreach ($branches as $branch) {
            if (!Uuid::isValid($branch)) {
                throw new StaffException(\sprintf('"%s" is not a sales channel id.', $branch));
            }
        }
        $found = $this->salesChannelRepository->searchIds(new Criteria($branches), $context)->getIds();
        $missing = array_diff($branches, $found);
        if ($missing !== []) {
            throw new StaffException(\sprintf('Unknown sales channel: %s.', implode(', ', $missing)));
        }
    }

    private function systemLocaleId(Context $context): string
    {
        $language = $this->languageRepository->search(new Criteria([Defaults::LANGUAGE_SYSTEM]), $context)->getEntities()->first()
            ?? throw new StaffException('The system language is missing.');

        return $language->getLocaleId();
    }
}
