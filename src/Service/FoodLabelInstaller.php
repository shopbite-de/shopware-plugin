<?php

declare(strict_types=1);

namespace ShopBite\Service;

use ShopBite\FoodLabel\FoodLabelCatalogue;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
use Shopware\Core\Content\Property\PropertyGroupCollection;
use Shopware\Core\Content\Property\PropertyGroupEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

/**
 * Creates the property groups "Allergene" and "Zusatzstoffe" with every option of the
 * catalogue, its position and code. A group of the same name that already exists (created
 * by the backoffice or by hand) is adopted: matching options keep their id and only get
 * code and position, missing ones are added, foreign options stay untouched.
 */
final readonly class FoodLabelInstaller
{
    /**
     * @param EntityRepository<PropertyGroupCollection> $propertyGroupRepository
     */
    public function __construct(
        private EntityRepository $propertyGroupRepository,
    ) {
    }

    public function install(Context $context): void
    {
        $writes = [];
        foreach (FoodLabelCatalogue::groups() as $fixedId => $group) {
            $existing = $this->find($fixedId, $group['name'], $context);
            $options = [];
            $position = 0;
            foreach ($group['labels'] as $key => [$code, $name]) {
                ++$position;
                $option = $existing !== null ? $this->option($existing, $name) : null;
                if ($option === null) {
                    $options[] = [
                        'id' => FoodLabelCatalogue::optionId($key),
                        'name' => $name,
                        'position' => $position,
                        'customFields' => [CustomFieldsInstaller::SHOPBITE_CODE => $code],
                    ];
                    continue;
                }
                $customFields = $option->getCustomFields() ?? [];
                if (($customFields[CustomFieldsInstaller::SHOPBITE_CODE] ?? null) === $code && $option->getPosition() === $position) {
                    continue;
                }
                $options[] = [
                    'id' => $option->getId(),
                    'position' => $position,
                    'customFields' => [...$customFields, CustomFieldsInstaller::SHOPBITE_CODE => $code],
                ];
            }

            if ($existing === null) {
                $writes[] = [
                    'id' => $fixedId,
                    'name' => $group['name'],
                    'displayType' => 'text',
                    'sortingType' => 'position',
                    'filterable' => false,
                    'visibleOnProductDetailPage' => true,
                    'options' => $options,
                ];
            } elseif ($options !== []) {
                $writes[] = ['id' => $existing->getId(), 'options' => $options];
            }
        }

        if ($writes !== []) {
            $this->propertyGroupRepository->upsert($writes, $context);
        }
    }

    public function update(Context $context): void
    {
        $this->install($context);
    }

    private function find(string $fixedId, string $name, Context $context): ?PropertyGroupEntity
    {
        $byId = $this->propertyGroupRepository
            ->search(new Criteria([$fixedId])->addAssociation('options'), $context)
            ->getEntities()
            ->first();
        if ($byId !== null) {
            return $byId;
        }

        $criteria = new Criteria()
            ->addFilter(new EqualsFilter('name', $name))
            ->addAssociation('options')
            ->setLimit(1);

        return $this->propertyGroupRepository->search($criteria, $context)->getEntities()->first();
    }

    private function option(PropertyGroupEntity $group, string $name): ?PropertyGroupOptionEntity
    {
        foreach ($group->getOptions() ?? [] as $option) {
            if (mb_strtolower((string) $option->getTranslation('name')) === mb_strtolower($name)
                || mb_strtolower((string) $option->getName()) === mb_strtolower($name)) {
                return $option;
            }
        }

        return null;
    }
}
