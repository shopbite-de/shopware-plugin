<?php

declare(strict_types=1);

namespace ShopBite\MultiChannelGroup\SalesChannel;

use ShopBite\MultiChannelGroup\MultiChannelGroupDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\MappingEntityDefinition;
use Shopware\Core\System\SalesChannel\SalesChannelDefinition;

final class MultiChannelGroupSalesChannelDefinition extends MappingEntityDefinition
{
    public const string ENTITY_NAME = 'shopbite_multi_channel_group_sales_channels';

    #[\Override]
    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    #[\Override]
    public function getEntityClass(): string
    {
        return MultiChannelGroupSalesChannelEntity::class;
    }

    #[\Override]
    public function getCollectionClass(): string
    {
        return MultiChannelGroupSalesChannelCollection::class;
    }

    #[\Override]
    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            new FkField('multi_channel_group_id', 'multiChannelGroupId', MultiChannelGroupDefinition::class)->addFlags(new PrimaryKey(), new Required()),
            new FkField('sales_channel_id', 'salesChannelId', SalesChannelDefinition::class)->addFlags(new PrimaryKey(), new Required()),
            new ManyToOneAssociationField('multiChannelGroup', 'multi_channel_group_id', MultiChannelGroupDefinition::class, 'id', false),
            new ManyToOneAssociationField('salesChannel', 'sales_channel_id', SalesChannelDefinition::class, 'id', false),
        ]);
    }
}
