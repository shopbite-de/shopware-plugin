<?php

declare(strict_types=1);

namespace ShopBite\Checkout\Cart\Dish;

use ShopBite\Checkout\Cart\LineItemFactoryHandler\ContainerLineItemFactory;
use ShopBite\Service\CustomFieldsInstaller;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartDataCollectorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Sets label and payload of dish line items from the products, so the order
 * and the receipt show what the cart was calculated with.
 *
 * Runs after Shopware's product collector (priority 5000), which has loaded
 * the products (with their properties) and set the labels of the children.
 * Label: `<product> +<extra> ... -<ingredient> ...`, the format the receipt
 * printer expects. Ingredients to leave out are kept only if they are options
 * of the product's property group "Hauptzutaten" (the ones the storefront
 * offers to deselect).
 *
 * @psalm-api
 */
final readonly class DishLineItemCollector implements CartDataCollectorInterface
{
    public const string MAIN_INGREDIENTS_GROUP = 'Hauptzutaten';

    /** Key of Shopware's ProductCartProcessor in the cart data collection. */
    private const string PRODUCT_DATA_KEY = 'product-';

    #[\Override]
    public function collect(CartDataCollection $data, Cart $original, SalesChannelContext $context, CartBehavior $behavior): void
    {
        foreach ($original->getLineItems()->filterType(ContainerLineItemFactory::TYPE) as $dish) {
            $main = $this->mainChild($dish);
            if ($main === null) {
                // The product is gone (removed by the product processor, which reports it).
                $original->getLineItems()->remove($dish->getId());

                continue;
            }

            $product = $data->get(self::PRODUCT_DATA_KEY . $main->getReferencedId());
            if (!$product instanceof SalesChannelProductEntity) {
                continue;
            }

            $this->enrich($dish, $main, $product);
        }
    }

    private function mainChild(LineItem $dish): ?LineItem
    {
        // Dishes added before the referenced id was set: the product is the first child.
        $productId = $dish->getReferencedId() ?? $dish->getChildren()->first()?->getReferencedId();
        if ($productId === null) {
            return null;
        }
        $dish->setReferencedId($productId);

        foreach ($dish->getChildren() as $child) {
            if ($child->getType() === LineItem::PRODUCT_LINE_ITEM_TYPE && $child->getReferencedId() === $productId) {
                return $child;
            }
        }

        return null;
    }

    private function enrich(LineItem $dish, LineItem $main, SalesChannelProductEntity $product): void
    {
        $requested = $dish->getPayload()[ContainerLineItemFactory::PAYLOAD_WITHOUT] ?? [];
        $ingredients = $this->mainIngredients($product);
        $without = array_values(array_filter(
            \is_array($requested) ? $requested : [],
            static fn (mixed $name): bool => \is_string($name) && isset($ingredients[$name]),
        ));

        $label = (string) $product->getTranslation('name');
        foreach ($dish->getChildren() as $child) {
            $extra = $child->getLabel();
            if ($child !== $main && $extra !== null && $extra !== '') {
                $label .= ' +' . $extra;
            }
        }
        foreach ($without as $name) {
            $label .= ' -' . $name;
        }
        $dish->setLabel(DishText::clean($label));

        foreach (array_keys($dish->getPayload()) as $key) {
            $dish->removePayloadValue($key);
        }
        $dish->setPayloadValue('productNumber', $product->getProductNumber());
        $dish->setPayloadValue(ContainerLineItemFactory::PAYLOAD_WITHOUT, $without);

        $customFields = $product->getTranslation('customFields');
        $customFields = \is_array($customFields) ? $customFields : ($product->getCustomFields() ?? []);
        $printType = $customFields[CustomFieldsInstaller::SHOPBITE_RECEIPT_PRINT_TYPE] ?? null;
        if (\is_string($printType) && $printType !== '') {
            $dish->setPayloadValue(CustomFieldsInstaller::SHOPBITE_RECEIPT_PRINT_TYPE, $printType);
        }
    }

    /**
     * @return array<string, true>
     */
    private function mainIngredients(SalesChannelProductEntity $product): array
    {
        $names = [];
        foreach ($product->getProperties() ?? [] as $option) {
            $group = $option->getGroup();
            if ($group === null || !\in_array(self::MAIN_INGREDIENTS_GROUP, [$group->getName(), $group->getTranslation('name')], true)) {
                continue;
            }
            foreach ([$option->getTranslation('name'), $option->getName()] as $name) {
                if (\is_string($name) && $name !== '') {
                    $names[$name] = true;
                }
            }
        }

        return $names;
    }
}
