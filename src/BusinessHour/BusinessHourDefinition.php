<?php

declare(strict_types=1);

namespace ShopBite\BusinessHour;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\System\SalesChannel\SalesChannelDefinition;

final class BusinessHourDefinition extends EntityDefinition
{
    public const string ENTITY_NAME = 'shopbite_business_hour';

    #[\Override]
    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    #[\Override]
    public function getEntityClass(): string
    {
        return BusinessHourEntity::class;
    }

    #[\Override]
    public function getCollectionClass(): string
    {
        return BusinessHourCollection::class;
    }

    #[\Override]
    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            new IdField('id', 'id')->addFlags(new ApiAware(), new PrimaryKey(), new Required()),
            new IntField('day_of_week', 'dayOfWeek')->addFlags(new ApiAware(), new Required()),
            new StringField('opening_time', 'openingTime')->addFlags(new ApiAware(), new Required()),
            new StringField('closing_time', 'closingTime')->addFlags(new ApiAware(), new Required()),
            new FkField('sales_channel_id', 'salesChannelId', SalesChannelDefinition::class)->addFlags(new ApiAware(), new Required()),
            new ManyToOneAssociationField('salesChannel', 'sales_channel_id', SalesChannelDefinition::class, 'id', false),
        ]);
    }
}
