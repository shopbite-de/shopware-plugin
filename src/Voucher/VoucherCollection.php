<?php

declare(strict_types=1);

namespace ShopBite\Voucher;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void              add(VoucherEntity $entity)
 * @method void              set(string $key, VoucherEntity $entity)
 * @method VoucherEntity[]   getIterator()
 * @method VoucherEntity[]   getElements()
 * @method VoucherEntity|null get(string $key)
 * @method VoucherEntity|null first()
 * @method VoucherEntity|null last()
 */
final class VoucherCollection extends EntityCollection
{
    #[\Override]
    protected function getExpectedClass(): string
    {
        return VoucherEntity::class;
    }
}
