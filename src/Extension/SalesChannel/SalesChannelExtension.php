<?php

declare(strict_types=1);

namespace ShopBite\Extension\SalesChannel;

use ShopBite\BusinessHour\BusinessHourDefinition;
use ShopBite\Holiday\HolidayDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\System\SalesChannel\SalesChannelDefinition;

class SalesChannelExtension extends EntityExtension
{
    #[\Override]
    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(
            new OneToManyAssociationField(
                propertyName: 'shopbiteBusinessHours',
                referenceClass: BusinessHourDefinition::class,
                referenceField: 'sales_channel_id'
            )
        );
        $collection->add(
            new OneToManyAssociationField(
                propertyName: 'shopbiteHolidays',
                referenceClass: HolidayDefinition::class,
                referenceField: 'sales_channel_id'
            )
        );
    }

    public function getDefinitionClass(): string
    {
        return SalesChannelDefinition::class;
    }

    #[\Override]
    public function getEntityName(): string
    {
        return SalesChannelDefinition::ENTITY_NAME;
    }
}
