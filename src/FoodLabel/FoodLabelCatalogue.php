<?php

declare(strict_types=1);

namespace ShopBite\FoodLabel;

use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Mandatory food information of a dish: the 14 allergens of the LMIV (Annex II, cereals
 * containing gluten and nuts by name) and the additives German menus have to mark.
 * Stored as the property groups "Allergene" and "Zusatzstoffe"; the option name is what
 * guests read, the code (A-R, 1-12) goes into the option custom field shopbite_code.
 *
 * Same catalogue as the backoffice (../backoffice/shared/utils/labels.ts), keep both in sync.
 */
final class FoodLabelCatalogue
{
    public const string ALLERGEN_GROUP = 'Allergene';
    public const string ADDITIVE_GROUP = 'Zusatzstoffe';

    public const string ALLERGEN_GROUP_ID = 'a1e5c0a3e8b44b7c9f0d2a6b4c8e1f01';
    public const string ADDITIVE_GROUP_ID = 'a1e5c0a3e8b44b7c9f0d2a6b4c8e1f02';

    /** key => [code, name] */
    public const array ALLERGENS = [
        'weizen' => ['A', 'Weizen'],
        'roggen' => ['A', 'Roggen'],
        'gerste' => ['A', 'Gerste'],
        'hafer' => ['A', 'Hafer'],
        'dinkel' => ['A', 'Dinkel'],
        'kamut' => ['A', 'Kamut'],
        'krebstiere' => ['B', 'Krebstiere'],
        'eier' => ['C', 'Eier'],
        'fisch' => ['D', 'Fisch'],
        'erdnuesse' => ['E', 'Erdnüsse'],
        'soja' => ['F', 'Soja'],
        'milch' => ['G', 'Milch (inkl. Laktose)'],
        'mandeln' => ['H', 'Mandeln'],
        'haselnuesse' => ['H', 'Haselnüsse'],
        'walnuesse' => ['H', 'Walnüsse'],
        'cashew' => ['H', 'Cashewnüsse'],
        'pekan' => ['H', 'Pekannüsse'],
        'paranuesse' => ['H', 'Paranüsse'],
        'pistazien' => ['H', 'Pistazien'],
        'macadamia' => ['H', 'Macadamianüsse'],
        'sellerie' => ['L', 'Sellerie'],
        'senf' => ['M', 'Senf'],
        'sesam' => ['N', 'Sesam'],
        'sulfite' => ['O', 'Schwefeldioxid und Sulfite'],
        'lupinen' => ['P', 'Lupinen'],
        'weichtiere' => ['R', 'Weichtiere'],
    ];

    /** key => [code, name] */
    public const array ADDITIVES = [
        'farbstoff' => ['1', 'mit Farbstoff'],
        'konservierung' => ['2', 'mit Konservierungsstoff'],
        'antioxidation' => ['3', 'mit Antioxidationsmittel'],
        'geschmacksverstaerker' => ['4', 'mit Geschmacksverstärker'],
        'geschwefelt' => ['5', 'geschwefelt'],
        'geschwaerzt' => ['6', 'geschwärzt'],
        'gewachst' => ['7', 'gewachst'],
        'phosphat' => ['8', 'mit Phosphat'],
        'suessungsmittel' => ['9', 'mit Süßungsmittel'],
        'phenylalanin' => ['10', 'enthält eine Phenylalaninquelle'],
        'koffein' => ['11', 'koffeinhaltig'],
        'chinin' => ['12', 'chininhaltig'],
    ];

    /**
     * @return array<string, array{name: string, labels: array<string, array{0: string, 1: string}>}> group id => group
     */
    public static function groups(): array
    {
        return [
            self::ALLERGEN_GROUP_ID => ['name' => self::ALLERGEN_GROUP, 'labels' => self::ALLERGENS],
            self::ADDITIVE_GROUP_ID => ['name' => self::ADDITIVE_GROUP, 'labels' => self::ADDITIVES],
        ];
    }

    /** Stable id for an option the plugin creates. */
    public static function optionId(string $key): string
    {
        return Uuid::fromStringToHex('shopbite-food-label:' . $key);
    }
}
