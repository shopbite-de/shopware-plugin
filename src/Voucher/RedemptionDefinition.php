<?php

declare(strict_types=1);

namespace ShopBite\Voucher;

use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\PriceField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

final class RedemptionDefinition extends EntityDefinition
{
    public const string ENTITY_NAME = 'shopbite_voucher_redemption';

    #[\Override]
    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    #[\Override]
    public function getEntityClass(): string
    {
        return RedemptionEntity::class;
    }

    #[\Override]
    public function getCollectionClass(): string
    {
        return RedemptionCollection::class;
    }

    #[\Override]
    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            new IdField('id', 'id')
                ->addFlags(new ApiAware(), new PrimaryKey(), new Required()),

            new FkField('voucher_id', 'voucherId', VoucherDefinition::class)
                ->addFlags(new ApiAware(), new Required()),
            new ManyToOneAssociationField('voucher', 'voucher_id', VoucherDefinition::class, 'id', false),

            new FkField('order_id', 'orderId', OrderDefinition::class)
                ->addFlags(new ApiAware()),
            new ManyToOneAssociationField('order', 'order_id', OrderDefinition::class, 'id', false),

            new PriceField('amount', 'amount')
                ->addFlags(new ApiAware(), new Required()),
        ]);
    }
}
