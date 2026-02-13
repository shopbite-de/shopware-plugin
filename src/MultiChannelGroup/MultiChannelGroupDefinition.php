<?php

declare(strict_types=1);

namespace ShopBite\MultiChannelGroup;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\System\SalesChannel\SalesChannelDefinition;

final class MultiChannelGroupDefinition extends EntityDefinition
{
    public const string ENTITY_NAME = 'shopbite_multi_channel_group';

    #[\Override]
    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    #[\Override]
    public function getEntityClass(): string
    {
        return MultiChannelGroupEntity::class;
    }

    #[\Override]
    public function getCollectionClass(): string
    {
        return MultiChannelGroupCollection::class;
    }

    #[\Override]
    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            new IdField('id', 'id')->addFlags(new ApiAware(), new PrimaryKey(), new Required()),
            new StringField('name', 'name')->addFlags(new ApiAware(), new Required()),
            new ManyToManyAssociationField('salesChannels', SalesChannelDefinition::class, 'shopbite_multi_channel_group_sales_channels', 'multi_channel_group_id', 'sales_channel_id')->addFlags(new ApiAware()),
        ]);
    }
}
