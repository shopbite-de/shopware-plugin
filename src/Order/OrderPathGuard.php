<?php

declare(strict_types=1);

namespace ShopBite\Order;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\AssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * Finds paths of a criteria (associations, filters, post filters, sortings, aggregations,
 * queries, grouping and partial-loading fields, at any depth) that reach entities of the order
 * family from outside it, e.g. `sales_channel.orders` or `order.salesChannel.orders`. Only the
 * root search is filtered by branch, so such paths would load or reveal other branches' orders.
 * Paths that start in the order family and stay in it (order → lineItems) belong to the
 * filtered root and are fine.
 */
final class OrderPathGuard
{
    /**
     * The first forbidden path, or null.
     */
    public static function forbiddenPath(EntityDefinition $root, Criteria $criteria): ?string
    {
        return self::walkCriteria($root, $criteria, OrderFamily::contains($root->getEntityName()), '');
    }

    private static function walkCriteria(EntityDefinition $definition, Criteria $criteria, bool $inFamily, string $prefix): ?string
    {
        foreach (self::fieldPaths($criteria) as $path) {
            if (self::walkPath($definition, $path, $inFamily)) {
                return $prefix . $path;
            }
        }

        foreach ($criteria->getAssociations() as $name => $association) {
            $reference = self::reference($definition, (string) $name);
            if ($reference === null) {
                continue;
            }
            $referenceInFamily = OrderFamily::contains($reference->getEntityName());
            if ($referenceInFamily && !$inFamily) {
                return $prefix . $name;
            }
            $found = self::walkCriteria($reference, $association, $inFamily && $referenceInFamily, $prefix . $name . '.');
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Whether a dotted field path enters the order family from outside.
     */
    private static function walkPath(EntityDefinition $definition, string $path, bool $inFamily): bool
    {
        // Normalised like EntityDefinitionQueryHelper::getFieldsOfAccessor(): `extensions`
        // segments are dropped, a leading entity name is stripped.
        $parts = array_values(array_filter(explode('.', $path), static fn (string $part): bool => $part !== 'extensions'));
        if (($parts[0] ?? null) === $definition->getEntityName()) {
            array_shift($parts);
        }

        foreach ($parts as $part) {
            $reference = self::reference($definition, $part);
            if ($reference === null) {
                return false;
            }
            $referenceInFamily = OrderFamily::contains($reference->getEntityName());
            if ($referenceInFamily && !$inFamily) {
                return true;
            }
            $inFamily = $inFamily && $referenceInFamily;
            $definition = $reference;
        }

        return false;
    }

    private static function reference(EntityDefinition $definition, string $propertyName): ?EntityDefinition
    {
        $field = $definition->getFields()->get($propertyName);
        if (!$field instanceof AssociationField) {
            return null;
        }

        return $field instanceof ManyToManyAssociationField
            ? $field->getToManyReferenceDefinition()
            : $field->getReferenceDefinition();
    }

    /**
     * @psalm-suppress InternalMethod getFields() of filters and aggregations is what Shopware's own
     *                 search detection uses to find the joined paths
     *
     * @return list<string>
     */
    private static function fieldPaths(Criteria $criteria): array
    {
        $paths = [];
        foreach ([...$criteria->getFilters(), ...$criteria->getPostFilters()] as $filter) {
            $paths = [...$paths, ...$filter->getFields()];
        }
        foreach ($criteria->getSorting() as $sorting) {
            $paths[] = $sorting->getField();
        }
        foreach ($criteria->getAggregations() as $aggregation) {
            $paths = [...$paths, ...$aggregation->getFields()];
        }
        foreach ($criteria->getQueries() as $query) {
            $paths = [...$paths, ...$query->getFields()];
        }
        foreach ($criteria->getGroupFields() as $grouping) {
            $paths[] = $grouping->getField();
        }
        foreach ($criteria->getFields() as $field) {
            $paths[] = $field;
        }

        return array_values(array_unique(array_map('strval', $paths)));
    }
}
