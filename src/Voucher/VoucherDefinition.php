<?php

declare(strict_types=1);

namespace ShopBite\Voucher;

use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\PriceField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\System\SalesChannel\SalesChannelDefinition;

final class VoucherDefinition extends EntityDefinition
{
    public const string ENTITY_NAME = 'shopbite_voucher';

    #[\Override]
    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    #[\Override]
    public function getEntityClass(): string
    {
        return VoucherEntity::class;
    }

    #[\Override]
    public function getCollectionClass(): string
    {
        return VoucherCollection::class;
    }

    #[\Override]
    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            new IdField('id', 'id')
                ->addFlags(new ApiAware(), new PrimaryKey(), new Required()),

            new StringField('code', 'code')
                ->addFlags(new ApiAware(), new Required()),

            new PriceField('value', 'value')
                ->addFlags(new ApiAware(), new Required()),

            new PriceField('remaining_value', 'remainingValue')
                ->addFlags(new ApiAware(), new Required()),

            new DateTimeField('valid_from', 'validFrom')
                ->addFlags(new ApiAware()),

            new DateTimeField('valid_until', 'validUntil')
                ->addFlags(new ApiAware()),

            new BoolField('is_active', 'isActive')
                ->addFlags(new ApiAware()),

            new FkField('created_by_order_id', 'createdByOrderId', OrderDefinition::class),
            new ManyToOneAssociationField('createdByOrder', 'created_by_order_id', OrderDefinition::class, 'id', false),

            new FkField('sales_channel_id', 'salesChannelId', SalesChannelDefinition::class),
            new ManyToOneAssociationField('salesChannel', 'sales_channel_id', SalesChannelDefinition::class, 'id', false),
        ]);
    }
}
