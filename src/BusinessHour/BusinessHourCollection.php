<?php

declare(strict_types=1);

namespace ShopBite\BusinessHour;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<BusinessHourEntity>
 */
final class BusinessHourCollection extends EntityCollection
{
    #[\Override]
    protected function getExpectedClass(): string
    {
        return BusinessHourEntity::class;
    }
}
