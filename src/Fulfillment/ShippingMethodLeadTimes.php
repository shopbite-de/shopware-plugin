<?php

declare(strict_types=1);

namespace ShopBite\Fulfillment;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use ShopBite\Service\CustomFieldsInstaller;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Fulfillment and lead time of the active shipping methods of a sales channel, for the Store
 * API config route and the backoffice settings, and the write of a lead time. A lead time is
 * stored on the shipping method, so it applies to every sales channel that uses the method.
 */
final readonly class ShippingMethodLeadTimes
{
    /**
     * @param EntityRepository<ShippingMethodCollection> $shippingMethodRepository
     *
     * @psalm-suppress PossiblyUnusedMethod Constructed by the container
     */
    public function __construct(
        private EntityRepository $shippingMethodRepository,
        private Connection $connection,
    ) {
    }

    /**
     * @return list<array{id: string, fulfillment: string, leadTimeMinutes: int}>
     */
    public function storefront(string $salesChannelId, Context $context): array
    {
        return array_map(static fn (ShippingMethodEntity $method): array => [
            'id' => $method->getId(),
            'fulfillment' => ShippingMethodFulfillment::fulfillment($method),
            'leadTimeMinutes' => ShippingMethodFulfillment::leadTimeMinutes($method),
        ], $this->activeMethods($salesChannelId, $context));
    }

    /**
     * @return list<array{id: string, name: string, fulfillment: string, leadTimeMinutes: int, leadTimeMinutesSet: bool}>
     */
    public function backoffice(string $salesChannelId, Context $context): array
    {
        return array_map(static fn (ShippingMethodEntity $method): array => [
            'id' => $method->getId(),
            'name' => ShippingMethodFulfillment::name($method),
            'fulfillment' => ShippingMethodFulfillment::fulfillment($method),
            'leadTimeMinutes' => ShippingMethodFulfillment::leadTimeMinutes($method),
            'leadTimeMinutesSet' => ShippingMethodFulfillment::isLeadTimeSet($method),
        ], $this->activeMethods($salesChannelId, $context));
    }

    /**
     * The sales channels each of the given shipping methods is assigned to (hex ids).
     *
     * @param list<string> $shippingMethodIds
     *
     * @return array<string, list<string>>
     */
    public function salesChannelIds(array $shippingMethodIds): array
    {
        if ($shippingMethodIds === []) {
            return [];
        }
        /** @var list<array{shipping_method_id: string, sales_channel_id: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(shipping_method_id)) AS shipping_method_id, LOWER(HEX(sales_channel_id)) AS sales_channel_id
             FROM sales_channel_shipping_method WHERE shipping_method_id IN (:ids)',
            ['ids' => array_map(Uuid::fromHexToBytes(...), $shippingMethodIds)],
            ['ids' => ArrayParameterType::BINARY],
        );

        $channels = [];
        foreach ($rows as $row) {
            $channels[$row['shipping_method_id']][] = $row['sales_channel_id'];
        }

        return $channels;
    }

    /**
     * Writes the lead times (custom fields are merged, other fields stay). Runs in a system
     * context: the staff roles may change lead times through the settings route without write
     * access to shipping methods, and the value is stored in the system language, which every
     * other language falls back to.
     *
     * @param array<string, int> $leadTimes minutes by shipping method id
     */
    public function write(array $leadTimes): void
    {
        $updates = [];
        foreach ($leadTimes as $id => $minutes) {
            $updates[] = ['id' => $id, 'customFields' => [CustomFieldsInstaller::SHOPBITE_LEAD_TIME_MINUTES => $minutes]];
        }
        if ($updates !== []) {
            $this->shippingMethodRepository->update($updates, new Context(new SystemSource()));
        }
    }

    /**
     * @return list<ShippingMethodEntity>
     */
    private function activeMethods(string $salesChannelId, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('salesChannels.id', $salesChannelId));
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addSorting(new FieldSorting('position'), new FieldSorting('name'));

        return array_values($this->shippingMethodRepository->search($criteria, $context)->getEntities()->getElements());
    }
}
