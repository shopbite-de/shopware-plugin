<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ShopBite\Service\CustomFieldsInstaller;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;

#[CoversClass(CustomFieldsInstaller::class)]
class CustomFieldsInstallerTest extends TestCase
{
    private EntityRepository&MockObject $customFieldSetRepository;
    private EntityRepository&MockObject $customFieldSetRelationRepository;
    private CustomFieldsInstaller $installer;

    protected function setUp(): void
    {
        $this->customFieldSetRepository = $this->createMock(EntityRepository::class);
        $this->customFieldSetRelationRepository = $this->createMock(EntityRepository::class);
        $this->installer = new CustomFieldsInstaller(
            $this->customFieldSetRepository,
            $this->customFieldSetRelationRepository
        );
    }

    public function testInstall(): void
    {
        $context = Context::createDefaultContext();

        $this->customFieldSetRepository->expects($this->once())
            ->method('upsert')
            ->with($this->callback(function (array $data) {
                if (count($data) !== 2) {
                    return false;
                }

                $productSet = null;
                $categorySet = null;

                foreach ($data as $set) {
                    if ($set['name'] === 'shopbite_product_set') {
                        $productSet = $set;
                    } elseif ($set['name'] === 'shopbite_category_set') {
                        $categorySet = $set;
                    }
                }

                if (!$productSet || !$categorySet) {
                    return false;
                }

                $foundReceipt = false;
                $foundCartUpsell = false;
                foreach ($productSet['customFields'] as $field) {
                    if ($field['name'] === 'shopbite_receipt_print_type') {
                        $foundReceipt = true;
                        if ($field['config']['defaultValue'] !== 'label') {
                            return false;
                        }
                    }
                    if ($field['name'] === 'shopbite_cart_upsell') {
                        $foundCartUpsell = true;
                        if ($field['type'] !== 'bool' || $field['config']['customFieldPosition'] !== 3) {
                            return false;
                        }
                    }
                }

                $foundIcon = false;
                foreach ($categorySet['customFields'] as $field) {
                    if ($field['name'] === 'shopbite_category_icon') {
                        $foundIcon = true;
                    }
                }

                return $foundReceipt && $foundCartUpsell && $foundIcon;
            }), $context);

        $this->installer->install($context);
    }

    public function testUpdate(): void
    {
        $context = Context::createDefaultContext();

        $this->customFieldSetRepository->expects($this->once())
            ->method('upsert');
        $this->customFieldSetRepository->method('search')->willReturn($this->createSearchResult([
            $this->createFieldSetMock('fieldset-product-id', 'shopbite_product_set'),
            $this->createFieldSetMock('fieldset-category-id', 'shopbite_category_set'),
        ]));
        $this->stubExistingRelations([]);
        $this->customFieldSetRelationRepository->expects($this->once())
            ->method('upsert')
            ->with($this->callback(fn (array $data) => array_column($data, 'entityName') === ['product', 'category']), $context);

        $this->installer->update($context);
    }

    public function testUninstall(): void
    {
        $context = Context::createDefaultContext();

        $this->customFieldSetRepository->expects($this->once())
            ->method('delete')
            ->with([
                ['id' => '0198be8b24a0722cac46b07e3a80f49b'],
                ['id' => '0195191263d9703ca6385732168d80f8'],
            ], $context);
        $this->customFieldSetRelationRepository->expects($this->never())
            ->method('delete');

        $this->installer->uninstall($context);
    }

    public function testAddRelations(): void
    {
        $context = Context::createDefaultContext();

        $productFieldSetMock = $this->createMock(\Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetEntity::class);
        $productFieldSetMock->method('getId')->willReturn('fieldset-product-id');
        $productFieldSetMock->method('getName')->willReturn('shopbite_product_set');

        $categoryFieldSetMock = $this->createMock(\Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetEntity::class);
        $categoryFieldSetMock->method('getId')->willReturn('fieldset-category-id');
        $categoryFieldSetMock->method('getName')->willReturn('shopbite_category_set');

        $entities = $this->createMock(\Shopware\Core\Framework\DataAbstractionLayer\EntityCollection::class);
        $entities->method('getElements')->willReturn([
            $productFieldSetMock,
            $categoryFieldSetMock,
        ]);

        $searchResult = $this->createMock(\Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult::class);
        $searchResult->method('getEntities')->willReturn($entities);

        $this->customFieldSetRepository->method('search')->willReturn($searchResult);
        $this->stubExistingRelations([]);

        $this->customFieldSetRelationRepository->expects($this->once())
            ->method('upsert')
            ->with($this->callback(function (array $data) {
                if (count($data) !== 2) {
                    return false;
                }

                $productRelation = null;
                $categoryRelation = null;

                foreach ($data as $relation) {
                    if ($relation['entityName'] === 'product') {
                        $productRelation = $relation;
                    } elseif ($relation['entityName'] === 'category') {
                        $categoryRelation = $relation;
                    }
                }

                return $productRelation !== null
                    && $productRelation['customFieldSetId'] === 'fieldset-product-id'
                    && $categoryRelation !== null
                    && $categoryRelation['customFieldSetId'] === 'fieldset-category-id';
            }), $context);

        $this->installer->addRelations($context);
    }

    public function testAddRelationsSkipsRelationThatAlreadyExistsUnderAnotherId(): void
    {
        $context = Context::createDefaultContext();

        $this->customFieldSetRepository->method('search')->willReturn($this->createSearchResult([
            $this->createFieldSetMock('fieldset-product-id', 'shopbite_product_set'),
            $this->createFieldSetMock('fieldset-category-id', 'shopbite_category_set'),
        ]));
        $this->stubExistingRelations(['category']);

        $this->customFieldSetRelationRepository->expects($this->once())
            ->method('upsert')
            ->with([[
                'id' => '0198be99dd757130a9a99df0f878bf05',
                'customFieldSetId' => 'fieldset-product-id',
                'entityName' => 'product',
            ]], $context);

        $this->installer->addRelations($context);
    }

    public function testAddRelationsDoesNothingWhenAllRelationsExist(): void
    {
        $context = Context::createDefaultContext();

        $this->customFieldSetRepository->method('search')->willReturn($this->createSearchResult([
            $this->createFieldSetMock('fieldset-product-id', 'shopbite_product_set'),
            $this->createFieldSetMock('fieldset-category-id', 'shopbite_category_set'),
        ]));
        $this->stubExistingRelations(['product', 'category']);

        $this->customFieldSetRelationRepository->expects($this->never())->method('upsert');

        $this->installer->addRelations($context);
    }

    /**
     * @param list<string> $existingEntityNames
     */
    private function stubExistingRelations(array $existingEntityNames): void
    {
        $this->customFieldSetRelationRepository->method('searchIds')
            ->willReturnCallback(function (Criteria $criteria, Context $context) use ($existingEntityNames): IdSearchResult {
                $entityName = null;
                foreach ($criteria->getFilters() as $filter) {
                    if ($filter->getField() === 'entityName') {
                        $entityName = $filter->getValue();
                    }
                }
                $ids = \in_array($entityName, $existingEntityNames, true) ? ['existing-id' => ['primaryKey' => 'existing-id', 'data' => []]] : [];

                return new IdSearchResult(\count($ids), $ids, $criteria, $context);
            });
    }

    /**
     * @param list<MockObject> $fieldSets
     */
    private function createSearchResult(array $fieldSets): MockObject
    {
        $entities = $this->createMock(\Shopware\Core\Framework\DataAbstractionLayer\EntityCollection::class);
        $entities->method('getElements')->willReturn($fieldSets);

        $searchResult = $this->createMock(\Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult::class);
        $searchResult->method('getEntities')->willReturn($entities);

        return $searchResult;
    }

    private function createFieldSetMock(string $id, string $name): MockObject
    {
        $mock = $this->createMock(\Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetEntity::class);
        $mock->method('getId')->willReturn($id);
        $mock->method('getName')->willReturn($name);
        return $mock;
    }
}
