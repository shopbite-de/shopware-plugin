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
    public const string SHOPBITE_CART_UPSELL = 'shopbite_cart_upsell';
    public const string SHOPBITE_CATEGORY_ICON = 'shopbite_category_icon';
    private const string USER_CUSTOM_FIELDSET_NAME = 'shopbite_user_set';
    /** Sales channels (branches) a backoffice user works for; empty = all (management only). */
    public const string SHOPBITE_SALES_CHANNEL_IDS = 'shopbite_sales_channel_ids';
    private const string PROPERTY_OPTION_CUSTOM_FIELDSET_NAME = 'shopbite_property_option_set';
    /** Code of an allergen or additive option (A-R, 1-12), see FoodLabelCatalogue. */
    public const string SHOPBITE_CODE = 'shopbite_code';
    /** The allergen and additive lists of a dish are complete (also when empty). */
    public const string SHOPBITE_ALLERGENS_CHECKED = 'shopbite_allergens_checked';
    private const string ORDER_CUSTOM_FIELDSET_NAME = 'shopbite_order_set';
    /** "Jetzt drucken" in the backoffice: when the receipt was requested (again). */
    public const string SHOPBITE_PRINT_REQUESTED_AT = 'shopbite_print_requested_at';
    /** Set by the order printer after every successful print. */
    public const string SHOPBITE_PRINTED_AT = 'shopbite_printed_at';

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
            [
                'id' => '01a0a0388d5f7300984a4380d0bf449d',
                'name' => self::SHOPBITE_CART_UPSELL,
                'type' => CustomFieldTypes::BOOL,
                'config' => [
                    'label' => [
                        'en-GB' => 'Cart suggestion (up-selling)',
                        'de-DE' => 'Warenkorb-Empfehlung (Up-Selling)',
                        Defaults::LANGUAGE_SYSTEM => 'Cart suggestion (up-selling)',
                    ],
                    'helpText' => [
                        'en-GB' => 'Suggested in the cart as a one-click add-on. For variants, set it on the single variant.',
                        'de-DE' => 'Wird im Warenkorb als Empfehlung zum Hinzufügen mit einem Klick angezeigt. Bei Varianten an der einzelnen Variante setzen.',
                        Defaults::LANGUAGE_SYSTEM => 'Suggested in the cart as a one-click add-on. For variants, set it on the single variant.',
                    ],
                    'componentName' => 'sw-field',
                    'type' => 'checkbox',
                    'customFieldType' => 'checkbox',
                    'customFieldPosition' => 3,
                ],
            ],
            [
                'id' => '703040431f5540f2a3138262057636a8',
                'name' => self::SHOPBITE_ALLERGENS_CHECKED,
                'type' => CustomFieldTypes::BOOL,
                'config' => [
                    'label' => [
                        'en-GB' => 'Allergens and additives checked',
                        'de-DE' => 'Allergene und Zusatzstoffe geprüft',
                        Defaults::LANGUAGE_SYSTEM => 'Allergens and additives checked',
                    ],
                    'helpText' => [
                        'en-GB' => 'The properties "Allergene" and "Zusatzstoffe" of this dish are complete, also when none is selected.',
                        'de-DE' => 'Die Eigenschaften "Allergene" und "Zusatzstoffe" dieses Gerichts sind vollständig, auch wenn keine ausgewählt ist.',
                        Defaults::LANGUAGE_SYSTEM => 'The properties "Allergene" and "Zusatzstoffe" of this dish are complete, also when none is selected.',
                    ],
                    'componentName' => 'sw-field',
                    'type' => 'checkbox',
                    'customFieldType' => 'checkbox',
                    'customFieldPosition' => 4,
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

    private const array USER_CUSTOM_FIELDSET = [
        'id' => '7883d489fd114d80b1cc67a32ff64665',
        'name' => self::USER_CUSTOM_FIELDSET_NAME,
        'config' => [
            'label' => [
                'en-GB' => 'ShopBite Backoffice',
                'de-DE' => 'ShopBite Backoffice',
                Defaults::LANGUAGE_SYSTEM => 'ShopBite Backoffice',
            ],
        ],
        'customFields' => [
            [
                'id' => 'c768f0f89aae4ac9955deaf4e4f739a9',
                'name' => self::SHOPBITE_SALES_CHANNEL_IDS,
                'type' => CustomFieldTypes::SELECT,
                'config' => [
                    'label' => [
                        'en-GB' => 'Branches (sales channels)',
                        'de-DE' => 'Filialen (Verkaufskanäle)',
                        Defaults::LANGUAGE_SYSTEM => 'Branches (sales channels)',
                    ],
                    'helpText' => [
                        'en-GB' => 'The backoffice only shows these sales channels to the user. Staff without a branch cannot log in; management without a branch sees all.',
                        'de-DE' => 'Das Backoffice zeigt dem Benutzer nur diese Verkaufskanäle. Personal ohne Filiale kommt nicht hinein, Management ohne Filiale sieht alle.',
                        Defaults::LANGUAGE_SYSTEM => 'The backoffice only shows these sales channels to the user. Staff without a branch cannot log in; management without a branch sees all.',
                    ],
                    'componentName' => 'sw-entity-multi-id-select',
                    'entity' => 'sales_channel',
                    'customFieldType' => 'entity',
                    'customFieldPosition' => 1,
                ],
            ],
        ],
    ];

    private const array PROPERTY_OPTION_CUSTOM_FIELDSET = [
        'id' => '2a57924ff9ab45d3be0e944abfde3f9d',
        'name' => self::PROPERTY_OPTION_CUSTOM_FIELDSET_NAME,
        'config' => [
            'label' => [
                'en-GB' => 'ShopBite Property',
                'de-DE' => 'ShopBite Eigenschaft',
                Defaults::LANGUAGE_SYSTEM => 'ShopBite Property',
            ],
        ],
        'customFields' => [
            [
                'id' => '42ea560f24a540ac866e95c31b57c95b',
                'name' => self::SHOPBITE_CODE,
                'type' => CustomFieldTypes::TEXT,
                'config' => [
                    'label' => [
                        'en-GB' => 'Code on the menu',
                        'de-DE' => 'Kürzel auf der Karte',
                        Defaults::LANGUAGE_SYSTEM => 'Code on the menu',
                    ],
                    'helpText' => [
                        'en-GB' => 'Allergen (A-R) or additive (1-12) code shown next to the dish. Set by the plugin.',
                        'de-DE' => 'Kürzel des Allergens (A-R) oder Zusatzstoffs (1-12) neben dem Gericht. Wird vom Plugin gesetzt.',
                        Defaults::LANGUAGE_SYSTEM => 'Allergen (A-R) or additive (1-12) code shown next to the dish. Set by the plugin.',
                    ],
                    'customFieldPosition' => 1,
                ],
            ],
        ],
    ];

    private const array ORDER_CUSTOM_FIELDSET = [
        'id' => '7c4c6700834a4839acabecf5c4f3e5b3',
        'name' => self::ORDER_CUSTOM_FIELDSET_NAME,
        'config' => [
            'label' => [
                'en-GB' => 'ShopBite Receipt',
                'de-DE' => 'ShopBite Bon',
                Defaults::LANGUAGE_SYSTEM => 'ShopBite Receipt',
            ],
        ],
        'customFields' => [
            [
                'id' => '400421443d34408f9d658564ec1deef6',
                'name' => self::SHOPBITE_PRINT_REQUESTED_AT,
                'type' => CustomFieldTypes::DATETIME,
                'config' => [
                    'label' => [
                        'en-GB' => 'Print requested at',
                        'de-DE' => 'Druck angefordert um',
                        Defaults::LANGUAGE_SYSTEM => 'Print requested at',
                    ],
                    'helpText' => [
                        'en-GB' => 'Set by "Jetzt drucken" in the backoffice; the order printer prints the receipt again.',
                        'de-DE' => 'Wird von „Jetzt drucken“ im Backoffice gesetzt; der Bondrucker druckt den Bon erneut.',
                        Defaults::LANGUAGE_SYSTEM => 'Set by "Jetzt drucken" in the backoffice; the order printer prints the receipt again.',
                    ],
                    'componentName' => 'sw-field',
                    'type' => 'datetime',
                    'customFieldType' => 'date',
                    'dateType' => 'datetime',
                    'customFieldPosition' => 1,
                ],
            ],
            [
                'id' => '79880f7ccd2140ef817d96150f94bdeb',
                'name' => self::SHOPBITE_PRINTED_AT,
                'type' => CustomFieldTypes::DATETIME,
                'config' => [
                    'label' => [
                        'en-GB' => 'Printed at',
                        'de-DE' => 'Gedruckt um',
                        Defaults::LANGUAGE_SYSTEM => 'Printed at',
                    ],
                    'helpText' => [
                        'en-GB' => 'Set by the order printer after every successful print.',
                        'de-DE' => 'Wird vom Bondrucker nach jedem erfolgreichen Druck gesetzt.',
                        Defaults::LANGUAGE_SYSTEM => 'Set by the order printer after every successful print.',
                    ],
                    'componentName' => 'sw-field',
                    'type' => 'datetime',
                    'customFieldType' => 'date',
                    'dateType' => 'datetime',
                    'customFieldPosition' => 2,
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
            self::USER_CUSTOM_FIELDSET,
            self::PROPERTY_OPTION_CUSTOM_FIELDSET,
            self::ORDER_CUSTOM_FIELDSET,
        ], $context);
    }

    public function update(Context $context): void
    {
        $this->install($context);
        // Sets added in a later plugin version would otherwise never get their relation,
        // because activate() does not run again for an already active plugin.
        $this->addRelations($context);
    }

    public function uninstall(Context $context): void
    {
        // Custom fields and relations are removed via CascadeDelete.
        $this->customFieldSetRepository->delete([
            ['id' => self::CUSTOM_FIELDSET['id']],
            ['id' => self::CATEGORY_CUSTOM_FIELDSET['id']],
            ['id' => self::USER_CUSTOM_FIELDSET['id']],
            ['id' => self::PROPERTY_OPTION_CUSTOM_FIELDSET['id']],
            ['id' => self::ORDER_CUSTOM_FIELDSET['id']],
        ], $context);
    }

    public function addRelations(Context $context): void
    {
        $ids = $this->getCustomFieldSetIds($context);
        $wanted = [
            self::CUSTOM_FIELDSET_NAME => ['id' => '0198be99dd757130a9a99df0f878bf05', 'entityName' => 'product'],
            self::CATEGORY_CUSTOM_FIELDSET_NAME => ['id' => '0195191263d9703ca63857321a007137', 'entityName' => 'category'],
            self::USER_CUSTOM_FIELDSET_NAME => ['id' => '3a7f247ccc574eaba89c03083ecf6ec6', 'entityName' => 'user'],
            self::PROPERTY_OPTION_CUSTOM_FIELDSET_NAME => ['id' => '3d93acca858c4899ac8d3e7abd3dcb6d', 'entityName' => 'property_group_option'],
            self::ORDER_CUSTOM_FIELDSET_NAME => ['id' => '6e1132387bc34e8293be2797ca868b92', 'entityName' => 'order'],
        ];

        $upserts = [];
        foreach ($wanted as $setName => $relation) {
            if (!isset($ids[$setName])) {
                continue;
            }
            // A relation can already exist under another id (created in the Admin or by an older
            // plugin version); inserting ours would violate the unique key on set + entity.
            if ($this->hasRelation($ids[$setName], $relation['entityName'], $context)) {
                continue;
            }
            $upserts[] = [
                'id' => $relation['id'],
                'customFieldSetId' => $ids[$setName],
                'entityName' => $relation['entityName'],
            ];
        }

        if ($upserts !== []) {
            $this->customFieldSetRelationRepository->upsert($upserts, $context);
        }
    }

    private function hasRelation(string $customFieldSetId, string $entityName, Context $context): bool
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customFieldSetId', $customFieldSetId));
        $criteria->addFilter(new EqualsFilter('entityName', $entityName));

        return $this->customFieldSetRelationRepository->searchIds($criteria, $context)->getTotal() > 0;
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
            new EqualsFilter('name', self::USER_CUSTOM_FIELDSET_NAME),
            new EqualsFilter('name', self::PROPERTY_OPTION_CUSTOM_FIELDSET_NAME),
            new EqualsFilter('name', self::ORDER_CUSTOM_FIELDSET_NAME),
        ]));

        $entities = $this->customFieldSetRepository->search($criteria, $context)->getEntities();

        $result = [];
        foreach ($entities->getElements() as $fieldset) {
            $result[$fieldset->getName()] = $fieldset->getId();
        }

        return $result;
    }
}
