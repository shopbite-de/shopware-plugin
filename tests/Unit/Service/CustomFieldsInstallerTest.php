<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ShopBite\Service\CustomFieldsInstaller;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
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
                if (!isset($data[0]['name']) || $data[0]['name'] !== 'shopbite_product_set') {
                    return false;
                }

                $customFields = $data[0]['customFields'];
                $found = false;
                foreach ($customFields as $field) {
                    if ($field['name'] === 'shopbite_receipt_print_type') {
                        $found = true;
                        if ($field['config']['defaultValue'] !== 'label') {
                            return false;
                        }
                    }
                }
                return $found;
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

        $idSearchResult = $this->createMock(IdSearchResult::class);
        $idSearchResult->method('getIds')->willReturn(['fieldset-id']);

        $this->customFieldSetRepository->method('searchIds')->willReturn($idSearchResult);

        $this->customFieldSetRelationRepository->expects($this->once())
            ->method('upsert')
            ->with($this->callback(function (array $data) {
                return count($data) === 1 && $data[0]['customFieldSetId'] === 'fieldset-id' && $data[0]['entityName'] === 'product';
            }), $context);

        $this->installer->addRelations($context);
    }
}
