<?php

declare(strict_types=1);

namespace ShopBite\Checkout\Cart\Dish;

use ShopBite\Checkout\Cart\LineItemFactoryHandler\ContainerLineItemFactory;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartDataCollectorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Sets label and payload of dish line items from the products, so the order
 * and the receipt show what the cart was calculated with.
 *
 * Runs after Shopware's product collector (priority 5000), which has set the
 * labels of the children. The main products (with their properties) are taken
 * from its data or, when it did not load them in this calculation (e.g. on an
 * update of an already calculated cart), loaded here.
 *
 * Label: `<product> +<extra> ... -<ingredient> ...`, the format the receipt
 * printer prints. Ingredients to leave out come from `payload.without`, or,
 * without that key (storefront request, carts stored before), from the label;
 * only options of the product's property group "Hauptzutaten" count (the ones
 * the storefront offers to deselect). Payload: `productNumber` and `without`.
 * A dish gets no receipt print type, so the printer prints its label.
 *
 * @psalm-api
 */
final readonly class DishLineItemCollector implements CartDataCollectorInterface
{
    public const string MAIN_INGREDIENTS_GROUP = 'Hauptzutaten';

    /** Key of Shopware's ProductCartProcessor in the cart data collection. */
    private const string PRODUCT_DATA_KEY = 'product-';

    /** Products loaded here. */
    private const string DISH_PRODUCT_DATA_KEY = 'shopbite-dish-product-';

    /**
     * @param SalesChannelRepository<ProductCollection> $productRepository
     *
     * @psalm-suppress PossiblyUnusedMethod Constructed by the container
     */
    public function __construct(private SalesChannelRepository $productRepository)
    {
    }

    #[\Override]
    public function collect(CartDataCollection $data, Cart $original, SalesChannelContext $context, CartBehavior $behavior): void
    {
        $dishes = [];
        foreach ($original->getLineItems()->filterType(ContainerLineItemFactory::TYPE) as $dish) {
            $main = $this->mainChild($dish);
            if ($main === null) {
                // The product is gone (removed by the product processor, which reports it).
                $original->getLineItems()->remove($dish->getId());

                continue;
            }
            $dishes[] = [$dish, $main, (string) $main->getReferencedId()];
        }
        if ($dishes === []) {
            return;
        }

        $missing = [];
        foreach ($dishes as [, , $productId]) {
            if ($this->product($data, $productId) === null) {
                $missing[$productId] = $productId;
            }
        }
        if ($missing !== []) {
            $criteria = new Criteria(array_values($missing));
            $criteria->addAssociation('properties.group');
            foreach ($this->productRepository->search($criteria, $context)->getEntities() as $product) {
                $data->set(self::DISH_PRODUCT_DATA_KEY . $product->getUniqueIdentifier(), $product);
            }
        }

        foreach ($dishes as [$dish, $main, $productId]) {
            $product = $this->product($data, $productId);
            if ($product !== null) {
                $this->enrich($dish, $main, $product);
            }
        }
    }

    private function product(CartDataCollection $data, string $productId): ?SalesChannelProductEntity
    {
        foreach ([self::PRODUCT_DATA_KEY, self::DISH_PRODUCT_DATA_KEY] as $prefix) {
            $product = $data->get($prefix . $productId);
            if ($product instanceof SalesChannelProductEntity) {
                return $product;
            }
        }

        return null;
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
        $name = (string) $product->getTranslation('name');
        $extras = [];
        foreach ($dish->getChildren() as $child) {
            $extra = $child->getLabel();
            if ($child !== $main && $extra !== null && $extra !== '') {
                $extras[] = $extra;
            }
        }
        $ingredients = $this->mainIngredients($product);

        $payload = $dish->getPayload();
        $requested = \array_key_exists(ContainerLineItemFactory::PAYLOAD_WITHOUT, $payload)
            ? $payload[ContainerLineItemFactory::PAYLOAD_WITHOUT]
            : DishText::withoutFromLabel((string) $dish->getLabel(), [$name, (string) $main->getLabel()], $extras, array_keys($ingredients));
        $without = array_values(array_unique(array_filter(
            \is_array($requested) ? $requested : [],
            static fn (mixed $ingredient): bool => \is_string($ingredient) && isset($ingredients[$ingredient]),
        )));

        $label = $name;
        foreach ($extras as $extra) {
            $label .= ' +' . $extra;
        }
        foreach ($without as $ingredient) {
            $label .= ' -' . $ingredient;
        }
        $dish->setLabel(DishText::clean($label));

        foreach (array_keys($payload) as $key) {
            $dish->removePayloadValue((string) $key);
        }
        $dish->setPayloadValue('productNumber', $product->getProductNumber());
        $dish->setPayloadValue(ContainerLineItemFactory::PAYLOAD_WITHOUT, $without);
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
