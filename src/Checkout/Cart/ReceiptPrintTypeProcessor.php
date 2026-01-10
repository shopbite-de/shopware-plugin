<?php

declare(strict_types=1);

namespace ShopBite\Checkout\Cart;

use ShopBite\Service\CustomFieldsInstaller;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartDataCollectorInterface;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

final readonly class ReceiptPrintTypeProcessor implements CartProcessorInterface, CartDataCollectorInterface
{
    private const string DATA_KEY = 'shopbite-products';

    /**
     * @param EntityRepository<ProductCollection> $productRepository
     */
    public function __construct(
        private EntityRepository $productRepository,
    ) {
    }

    #[\Override]
    public function collect(
        CartDataCollection $data,
        Cart $original,
        SalesChannelContext $context,
        CartBehavior $behavior
    ): void {
        $productLineItems = $original->getLineItems()->filterFlatByType(LineItem::PRODUCT_LINE_ITEM_TYPE);
        $productIds = array_filter(array_map(static fn (LineItem $lineItem) => $lineItem->getReferencedId(), $productLineItems));

        $filteredIds = [];
        foreach ($productIds as $id) {
            if (!$data->has(self::DATA_KEY . $id)) {
                $filteredIds[] = $id;
            }
        }

        if (empty($filteredIds)) {
            return;
        }

        $criteria = new Criteria($filteredIds);
        $products = $this->productRepository->search($criteria, $context->getContext())->getEntities();

        foreach ($products as $product) {
            $data->set(self::DATA_KEY . $product->getId(), $product);
        }
    }

    #[\Override]
    public function process(
        CartDataCollection $data,
        Cart $original,
        Cart $toCalculate,
        SalesChannelContext $context,
        CartBehavior $behavior
    ): void {
        foreach ($toCalculate->getLineItems()->filterFlatByType(LineItem::PRODUCT_LINE_ITEM_TYPE) as $lineItem) {
            $productId = $lineItem->getReferencedId();
            if ($productId === null) {
                continue;
            }

            /** @var ProductEntity|null $product */
            $product = $data->get(self::DATA_KEY . $productId);

            if ($product === null) {
                continue;
            }

            $customFields = $product->getCustomFields() ?? [];
            if (!isset($customFields[CustomFieldsInstaller::SHOPBITE_RECEIPT_PRINT_TYPE])) {
                continue;
            }

            $lineItem->setPayloadValue(
                CustomFieldsInstaller::SHOPBITE_RECEIPT_PRINT_TYPE,
                $customFields[CustomFieldsInstaller::SHOPBITE_RECEIPT_PRINT_TYPE]
            );
        }
    }
}
