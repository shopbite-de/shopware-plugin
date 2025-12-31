<?php

declare(strict_types=1);

namespace ShopBite\Holiday;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<HolidayEntity>
 */
final class HolidayCollection extends EntityCollection
{
    #[\Override]
    protected function getExpectedClass(): string
    {
        return HolidayEntity::class;
    }
}
