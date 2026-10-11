<?php

declare(strict_types=1);

namespace ShopBite\Voucher;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void                 add(RedemptionEntity $entity)
 * @method void                 set(string $key, RedemptionEntity $entity)
 * @method RedemptionEntity[]   getIterator()
 * @method RedemptionEntity[]   getElements()
 * @method RedemptionEntity|null get(string $key)
 * @method RedemptionEntity|null first()
 * @method RedemptionEntity|null last()
 */
final class RedemptionCollection extends EntityCollection
{
    #[\Override]
    protected function getExpectedClass(): string
    {
        return RedemptionEntity::class;
    }
}
