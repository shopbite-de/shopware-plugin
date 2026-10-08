<?php

declare(strict_types=1);

namespace ShopBite\Wishlist;

use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ReferenceVersionField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\System\SalesChannel\SalesChannelDefinition;

/**
 * A configured dish on a customer's wishlist: product (variant) plus deselected
 * ingredients and selected extras. Shopware's core wishlist only stores product ids.
 *
 * @psalm-suppress UnusedClass registered via services.xml
 */
final class WishlistItemDefinition extends EntityDefinition
{
    public const string ENTITY_NAME = 'shopbite_wishlist_item';

    #[\Override]
    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    #[\Override]
    public function getEntityClass(): string
    {
        return WishlistItemEntity::class;
    }

    #[\Override]
    public function getCollectionClass(): string
    {
        return WishlistItemCollection::class;
    }

    #[\Override]
    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            new IdField('id', 'id')->addFlags(new ApiAware(), new PrimaryKey(), new Required()),

            new FkField('customer_id', 'customerId', CustomerDefinition::class)->addFlags(new Required()),
            new ManyToOneAssociationField('customer', 'customer_id', CustomerDefinition::class, 'id', false),

            new FkField('sales_channel_id', 'salesChannelId', SalesChannelDefinition::class)->addFlags(new ApiAware(), new Required()),
            new ManyToOneAssociationField('salesChannel', 'sales_channel_id', SalesChannelDefinition::class, 'id', false),

            new FkField('product_id', 'productId', ProductDefinition::class)->addFlags(new ApiAware(), new Required()),
            new ReferenceVersionField(ProductDefinition::class)->addFlags(new ApiAware(), new Required()),
            new ManyToOneAssociationField('product', 'product_id', ProductDefinition::class, 'id', false),

            new StringField('product_number', 'productNumber', 64)->addFlags(new ApiAware(), new Required()),
            new JsonField('configuration', 'configuration')->addFlags(new ApiAware(), new Required()),
        ]);
    }
}
