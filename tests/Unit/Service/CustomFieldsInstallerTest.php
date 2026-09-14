<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ShopBite\Service\CustomFieldsInstaller;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

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

        $this->installer->update($context);
    }

    public function testUninstall(): void
    {
        $context = Context::createDefaultContext();

        $this->customFieldSetRepository->expects($this->once())
            ->method('delete');
        $this->customFieldSetRelationRepository->expects($this->once())
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

    private function createFieldSetMock(string $id, string $name): MockObject
    {
        $mock = $this->createMock(\Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetEntity::class);
        $mock->method('getId')->willReturn($id);
        $mock->method('getName')->willReturn($name);
        return $mock;
    }
}
