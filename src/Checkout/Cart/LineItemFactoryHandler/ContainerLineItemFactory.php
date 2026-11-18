<?php

declare(strict_types=1);

namespace ShopBite\Checkout\Cart\LineItemFactoryHandler;

use ShopBite\Checkout\Cart\Dish\DishLineItemException;
use ShopBite\Checkout\Cart\Dish\DishText;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Cart\LineItemFactoryHandler\LineItemFactoryInterface;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * A dish: the product as first child, its extras as further product children.
 *
 * Only the structure comes from the request (children, quantity, the
 * ingredients to leave out, explicitly or as "-ingredient" in the label). The
 * label and the product number are set from the products by
 * `DishLineItemCollector`.
 *
 * @psalm-api
 */
final readonly class ContainerLineItemFactory implements LineItemFactoryInterface
{
    public const string TYPE = 'container';
    public const string PAYLOAD_WITHOUT = 'without';
    public const int MAX_CHILDREN = 30;

    private const string CHILD_ID_PATTERN = '/^[0-9A-Za-z_-]{1,64}$/';

    #[\Override]
    public function supports(string $type): bool
    {
        return self::TYPE === $type;
    }

    #[\Override]
    public function create(array $data, SalesChannelContext $context): LineItem
    {
        if (!isset($data['children'])) {
            throw DishLineItemException::invalid('children', 'is required');
        }

        $lineItem = new LineItem((string) $data['id'], self::TYPE, null, self::quantity($data['quantity'] ?? 1, 'quantity'));
        $lineItem->markModified();

        $lineItem->setRemovable(true);
        $lineItem->setStackable(true);

        $this->update($lineItem, $data, $context);

        return $lineItem;
    }

    #[\Override]
    public function update(LineItem $lineItem, array $data, SalesChannelContext $context): void
    {
        if (isset($data['quantity'])) {
            $quantity = self::quantity($data['quantity'], 'quantity');
            if ($quantity !== $lineItem->getQuantity()) {
                $lineItem->setQuantity($quantity);
            }
        }

        // The label is only a hint for the ingredients to leave out (the storefront writes them
        // as "-ingredient" into it); of the payload only the explicit list is taken.
        $label = $data['label'] ?? null;
        if (\is_string($label)) {
            $lineItem->setLabel(DishText::label($label));
        }
        $payload = $data['payload'] ?? null;
        if (\is_array($payload) && \array_key_exists(self::PAYLOAD_WITHOUT, $payload)) {
            $lineItem->setPayloadValue(self::PAYLOAD_WITHOUT, DishText::withoutFromPayload($payload[self::PAYLOAD_WITHOUT]));
        } elseif (\is_string($label) && $lineItem->hasPayloadValue(self::PAYLOAD_WITHOUT)) {
            $lineItem->removePayloadValue(self::PAYLOAD_WITHOUT);
        }

        if (\array_key_exists('children', $data)) {
            $children = $this->children($data['children'], $lineItem->getQuantity());
            $lineItem->setChildren($children);
            $lineItem->setReferencedId($children->first()?->getReferencedId());
        }
    }

    private function children(mixed $children, int $quantity): LineItemCollection
    {
        if (!\is_array($children) || !array_is_list($children) || $children === []) {
            throw DishLineItemException::invalid('children', 'must be a non-empty list');
        }
        if (\count($children) > self::MAX_CHILDREN) {
            throw DishLineItemException::invalid('children', 'must have at most ' . self::MAX_CHILDREN . ' entries');
        }

        $collection = new LineItemCollection();
        foreach ($children as $child) {
            if (!\is_array($child)) {
                throw DishLineItemException::invalid('children', 'must be a list of line items');
            }
            if (($child['type'] ?? LineItem::PRODUCT_LINE_ITEM_TYPE) !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
                throw DishLineItemException::invalid('children.type', 'must be "product"');
            }

            $id = $child['id'] ?? null;
            if (!\is_string($id) || preg_match(self::CHILD_ID_PATTERN, $id) !== 1) {
                throw DishLineItemException::invalid('children.id', 'must be an id of letters, digits, "-" and "_"');
            }
            if ($collection->has($id)) {
                throw DishLineItemException::invalid('children.id', 'must be unique');
            }

            $referencedId = $child['referencedId'] ?? $id;
            if (!\is_string($referencedId) || !Uuid::isValid($referencedId)) {
                throw DishLineItemException::invalid('children.referencedId', 'must be a product id');
            }

            // Product and extras count once per dish, whatever the request says.
            self::quantity($child['quantity'] ?? 1, 'children.quantity');

            $collection->add(new LineItem($id, LineItem::PRODUCT_LINE_ITEM_TYPE, $referencedId, $quantity));
        }

        return $collection;
    }

    private static function quantity(mixed $quantity, string $field): int
    {
        if (!\is_int($quantity) || $quantity < 1) {
            throw DishLineItemException::invalid($field, 'must be an integer of at least 1');
        }

        return $quantity;
    }
}
