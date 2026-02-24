<?php

declare(strict_types=1);

namespace ShopBite\Service;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetCollection;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSetRelation\CustomFieldSetRelationCollection;
use Shopware\Core\System\CustomField\CustomFieldTypes;

final readonly class CustomFieldsInstaller
{
    private const string CUSTOM_FIELDSET_NAME = 'shopbite_product_set';
    private const string CATEGORY_CUSTOM_FIELDSET_NAME = 'shopbite_category_set';
    public const string SHOPBITE_RECEIPT_PRINT_TYPE = 'shopbite_receipt_print_type';
    public const string SHOPBITE_CATEGORY_ICON = 'shopbite_category_icon';

    private const array CUSTOM_FIELDSET = [
        'id' => '0198be8b24a0722cac46b07e3a80f49b',
        'name' => self::CUSTOM_FIELDSET_NAME,
        'config' => [
            'label' => [
                'en-GB' => 'ShopBite',
                'de-DE' => 'ShopBite',
                Defaults::LANGUAGE_SYSTEM => 'ShopBite',
            ],
        ],
        'customFields' => [
            [
                'id' => '0198be8b24a0722cac46b07e3a9686ec',
                'name' => 'shopbite_delivery_time_factor',
                'type' => CustomFieldTypes::INT,
                'config' => [
                    'label' => [
                        'en-GB' => 'Delivery Time Factor',
                        'de-DE' => 'Lieferzeitfaktor',
                        Defaults::LANGUAGE_SYSTEM => 'Delivery Time Factor',
                    ],
                    'customFieldPosition' => 1,
                ],
            ],
            [
                'id' => '01944f2b1875706596395b8d23966952',
                'name' => self::SHOPBITE_RECEIPT_PRINT_TYPE,
                'type' => CustomFieldTypes::SELECT,
                'config' => [
                    'label' => [
                        'en-GB' => 'Receipt Print Type',
                        'de-DE' => 'Quittungsdruck-Typ',
                        Defaults::LANGUAGE_SYSTEM => 'Receipt Print Type',
                    ],
                    'componentName' => 'sw-single-select',
                    'customFieldType' => 'select',
                    'options' => [
                        [
                            'label' => [
                                'en-GB' => 'Label',
                                'de-DE' => 'Bezeichnung',
                                Defaults::LANGUAGE_SYSTEM => 'Label',
                            ],
                            'value' => 'label',
                        ],
                        [
                            'label' => [
                                'en-GB' => 'Number',
                                'de-DE' => 'Nummer',
                                Defaults::LANGUAGE_SYSTEM => 'Number',
                            ],
                            'value' => 'number',
                        ],
                    ],
                    'defaultValue' => 'label',
                    'customFieldPosition' => 2,
                ],
            ],
        ],
    ];

    private const array CATEGORY_CUSTOM_FIELDSET = [
        'id' => '0195191263d9703ca6385732168d80f8',
        'name' => self::CATEGORY_CUSTOM_FIELDSET_NAME,
        'config' => [
            'label' => [
                'en-GB' => 'ShopBite Category',
                'de-DE' => 'ShopBite Kategorie',
                Defaults::LANGUAGE_SYSTEM => 'ShopBite Category',
            ],
        ],
        'customFields' => [
            [
                'id' => '0195191263d9703ca638573216c5932a',
                'name' => self::SHOPBITE_CATEGORY_ICON,
                'type' => CustomFieldTypes::TEXT,
                'config' => [
                    'label' => [
                        'en-GB' => 'Icon',
                        'de-DE' => 'Icon',
                        Defaults::LANGUAGE_SYSTEM => 'Icon',
                    ],
                    'helpText' => 'Icon name from https://icones.js.org. Example i-lucide-heart',
                    'customFieldPosition' => 1,
                ],
            ],
        ],
    ];

    /**
     * @param EntityRepository<CustomFieldSetCollection>         $customFieldSetRepository
     * @param EntityRepository<CustomFieldSetRelationCollection> $customFieldSetRelationRepository
     */
    public function __construct(
        private EntityRepository $customFieldSetRepository,
        private EntityRepository $customFieldSetRelationRepository,
    ) {
    }

    public function install(Context $context): void
    {
        $this->customFieldSetRepository->upsert([
            self::CUSTOM_FIELDSET,
            self::CATEGORY_CUSTOM_FIELDSET,
        ], $context);
    }

    public function update(Context $context): void
    {
        $this->install($context);
    }

    public function uninstall(Context $context): void
    {
        $this->customFieldSetRepository->delete(array_merge(
            self::CUSTOM_FIELDSET['customFields'],
            self::CATEGORY_CUSTOM_FIELDSET['customFields']
        ), $context);
        $this->customFieldSetRelationRepository->delete([
            self::CUSTOM_FIELDSET,
            self::CATEGORY_CUSTOM_FIELDSET,
        ], $context);
    }

    public function addRelations(Context $context): void
    {
        $upserts = [];
        $ids = $this->getCustomFieldSetIds($context);

        if (isset($ids[self::CUSTOM_FIELDSET_NAME])) {
            $upserts[] = [
                'id' => '0198be99dd757130a9a99df0f878bf05',
                'customFieldSetId' => $ids[self::CUSTOM_FIELDSET_NAME],
                'entityName' => 'product',
            ];
        }

        if (isset($ids[self::CATEGORY_CUSTOM_FIELDSET_NAME])) {
            $upserts[] = [
                'id' => '0195191263d9703ca63857321a007137',
                'customFieldSetId' => $ids[self::CATEGORY_CUSTOM_FIELDSET_NAME],
                'entityName' => 'category',
            ];
        }

        if ($upserts !== []) {
            $this->customFieldSetRelationRepository->upsert($upserts, $context);
        }
    }

    /**
     * @return array<string, string>
     */
    private function getCustomFieldSetIds(Context $context): array
    {
        $criteria = new Criteria();

        $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
            new EqualsFilter('name', self::CUSTOM_FIELDSET_NAME),
            new EqualsFilter('name', self::CATEGORY_CUSTOM_FIELDSET_NAME),
        ]));

        $entities = $this->customFieldSetRepository->search($criteria, $context)->getEntities();

        $result = [];
        foreach ($entities->getElements() as $fieldset) {
            $result[$fieldset->getName()] = $fieldset->getId();
        }

        return $result;
    }
}
