<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ShopBite\FoodLabel\FoodLabelCatalogue;
use ShopBite\Service\FoodLabelInstaller;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
use Shopware\Core\Content\Property\PropertyGroupCollection;
use Shopware\Core\Content\Property\PropertyGroupEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;

#[CoversClass(FoodLabelInstaller::class)]
#[CoversClass(FoodLabelCatalogue::class)]
#[AllowMockObjectsWithoutExpectations]
class FoodLabelInstallerTest extends TestCase
{
    private EntityRepository&MockObject $groups;
    private FoodLabelInstaller $installer;
    private Context $context;

    protected function setUp(): void
    {
        $this->groups = $this->createMock(EntityRepository::class);
        $this->installer = new FoodLabelInstaller($this->groups);
        $this->context = Context::createDefaultContext();
    }

    public function testCatalogueHasTheFourteenAllergensAndTwelveAdditives(): void
    {
        $allergenCodes = array_values(array_unique(array_column(FoodLabelCatalogue::ALLERGENS, 0)));
        static::assertSame(['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'L', 'M', 'N', 'O', 'P', 'R'], $allergenCodes);
        static::assertSame(range(1, 12), array_map('intval', array_column(FoodLabelCatalogue::ADDITIVES, 0)));
    }

    public function testCreatesBothGroupsWithCodesAndPositions(): void
    {
        $this->groupsFound([]);
        $this->groups->expects($this->once())->method('upsert')->with($this->callback(static function (array $data): bool {
            static::assertSame([FoodLabelCatalogue::ALLERGEN_GROUP_ID, FoodLabelCatalogue::ADDITIVE_GROUP_ID], array_column($data, 'id'));
            static::assertSame('Allergene', $data[0]['name']);
            static::assertFalse($data[0]['filterable']);
            static::assertCount(26, $data[0]['options']);
            static::assertCount(12, $data[1]['options']);
            static::assertSame([
                'id' => FoodLabelCatalogue::optionId('weizen'),
                'name' => 'Weizen',
                'position' => 1,
                'customFields' => ['shopbite_code' => 'A'],
            ], $data[0]['options'][0]);
            static::assertSame(['shopbite_code' => '12'], $data[1]['options'][11]['customFields']);

            return true;
        }), $this->context);

        $this->installer->install($this->context);
    }

    public function testAdoptsAnExistingGroupOfTheSameName(): void
    {
        // created by the backoffice before the plugin knew the group: random ids, no codes
        $group = $this->group('b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0', 'Allergene', [
            $this->option('c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1', 'milch (inkl. laktose)', 1, ['other' => true]),
            $this->option('c2c2c2c2c2c2c2c2c2c2c2c2c2c2c2c2', 'Nach Rezept des Hauses', 5, null),
        ]);
        $this->groupsFound([$group]);

        $this->groups->expects($this->once())->method('upsert')->with($this->callback(static function (array $data): bool {
            static::assertSame('b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0', $data[0]['id']);
            static::assertArrayNotHasKey('name', $data[0]);
            $byId = array_column($data[0]['options'], null, 'id');
            static::assertSame(
                ['id' => 'c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1', 'position' => 12, 'customFields' => ['other' => true, 'shopbite_code' => 'G']],
                $byId['c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1'],
            );
            static::assertArrayNotHasKey('c2c2c2c2c2c2c2c2c2c2c2c2c2c2c2c2', $byId);
            static::assertCount(26, $data[0]['options']);

            return true;
        }), $this->context);

        $this->installer->install($this->context);
    }

    public function testLeavesUpToDateGroupsAlone(): void
    {
        $groups = [];
        foreach (FoodLabelCatalogue::groups() as $id => $group) {
            $options = [];
            $position = 0;
            foreach ($group['labels'] as $key => [$code, $name]) {
                $options[] = $this->option(FoodLabelCatalogue::optionId($key), $name, ++$position, ['shopbite_code' => $code]);
            }
            $groups[] = $this->group($id, $group['name'], $options);
        }
        $this->groupsFound($groups);
        $this->groups->expects($this->never())->method('upsert');

        $this->installer->update($this->context);
    }

    /**
     * Answers the lookups by fixed id first, then by name.
     *
     * @param list<PropertyGroupEntity> $groups
     */
    private function groupsFound(array $groups): void
    {
        $this->groups->method('search')->willReturnCallback(function (Criteria $criteria) use ($groups): EntitySearchResult {
            $ids = $criteria->getIds();
            $match = array_values(array_filter($groups, static function (PropertyGroupEntity $group) use ($criteria, $ids): bool {
                if ($ids !== []) {
                    return \in_array($group->getId(), $ids, true);
                }
                $filter = $criteria->getFilters()[0] ?? null;

                return $filter !== null && method_exists($filter, 'getValue') && $filter->getValue() === $group->getName();
            }));
            $result = $this->createStub(EntitySearchResult::class);
            $result->method('getEntities')->willReturn(new PropertyGroupCollection($match));

            return $result;
        });
    }

    /**
     * @param list<PropertyGroupOptionEntity> $options
     */
    private function group(string $id, string $name, array $options): PropertyGroupEntity
    {
        $group = new PropertyGroupEntity();
        $group->setId($id);
        $group->setName($name);
        $group->setOptions(new PropertyGroupOptionCollection($options));

        return $group;
    }

    /**
     * @param array<string, mixed>|null $customFields
     */
    private function option(string $id, string $name, int $position, ?array $customFields): PropertyGroupOptionEntity
    {
        $option = new PropertyGroupOptionEntity();
        $option->setId($id);
        $option->setName($name);
        $option->setPosition($position);
        $option->setCustomFields($customFields);

        return $option;
    }
}
