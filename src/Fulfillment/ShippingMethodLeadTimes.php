<?php

declare(strict_types=1);

namespace ShopBite\Fulfillment;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use ShopBite\Staff\BranchBinding;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\DeliveryTime\DeliveryTimeCollection;

/**
 * Fulfillment and lead time of the active shipping methods of a sales channel, for the Store
 * API config route and the backoffice settings, and the write of a lead time. The lead time is the
 * shipping method's Shopware delivery time (see ShippingMethodFulfillment), so it applies to every
 * sales channel that uses the method.
 */
final readonly class ShippingMethodLeadTimes
{
    /**
     * @param EntityRepository<ShippingMethodCollection> $shippingMethodRepository
     * @param EntityRepository<DeliveryTimeCollection>   $deliveryTimeRepository
     *
     * @psalm-suppress PossiblyUnusedMethod Constructed by the container
     */
    public function __construct(
        private EntityRepository $shippingMethodRepository,
        private EntityRepository $deliveryTimeRepository,
        private Connection $connection,
        private MinuteDeliveryTimes $minuteDeliveryTimes,
    ) {
    }

    /**
     * The lead time of a shipping method, loading its delivery time when the entity comes without
     * it (the sales channel context loads its shipping method without associations).
     */
    public function leadTimeMinutes(ShippingMethodEntity $method, Context $context): int
    {
        if ($method->getDeliveryTime() !== null) {
            return ShippingMethodFulfillment::leadTimeMinutes($method);
        }
        // Read as a var: the typed property is uninitialised on an entity built without it.
        $deliveryTimeId = $method->getVars()['deliveryTimeId'] ?? null;
        if (!\is_string($deliveryTimeId) || $deliveryTimeId === '') {
            return ShippingMethodFulfillment::DEFAULT_LEAD_TIME_MINUTES;
        }
        $deliveryTime = $this->deliveryTimeRepository->search(new Criteria([$deliveryTimeId]), $context)->getEntities()->first();

        return ShippingMethodFulfillment::deliveryTimeMinutes($deliveryTime) ?? ShippingMethodFulfillment::DEFAULT_LEAD_TIME_MINUTES;
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
     * `editable`: whether a user with these branches may change the lead time, i.e. every sales
     * channel of the method is one of them (null = not restricted, see BranchBinding).
     *
     * @param list<string>|null $allowedSalesChannelIds
     *
     * @return list<array{id: string, name: string, fulfillment: string, leadTimeMinutes: int, leadTimeMinutesSet: bool, editable: bool}>
     */
    public function backoffice(string $salesChannelId, Context $context, ?array $allowedSalesChannelIds): array
    {
        $methods = $this->activeMethods($salesChannelId, $context);
        $channelsByMethod = $allowedSalesChannelIds === null
            ? []
            : $this->salesChannelIds(array_map(static fn (ShippingMethodEntity $method): string => $method->getId(), $methods));

        return array_map(static fn (ShippingMethodEntity $method): array => [
            'id' => $method->getId(),
            'name' => ShippingMethodFulfillment::name($method),
            'fulfillment' => ShippingMethodFulfillment::fulfillment($method),
            'leadTimeMinutes' => ShippingMethodFulfillment::leadTimeMinutes($method),
            'leadTimeMinutesSet' => ShippingMethodFulfillment::isLeadTimeSet($method),
            'editable' => self::editable($channelsByMethod[$method->getId()] ?? [], $allowedSalesChannelIds),
        ], $methods);
    }

    /**
     * @param list<string>      $salesChannelIds        the channels of a shipping method
     * @param list<string>|null $allowedSalesChannelIds the user's branches, null = not restricted
     */
    public static function editable(array $salesChannelIds, ?array $allowedSalesChannelIds): bool
    {
        foreach ($salesChannelIds as $salesChannelId) {
            if (!BranchBinding::permits($allowedSalesChannelIds, $salesChannelId)) {
                return false;
            }
        }

        return true;
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
     * Assigns each shipping method a delivery time of exactly that many minutes: an existing one
     * with unit minute and min = max = the minutes, else a new one named "<n> Minuten" (German
     * languages) or "<n> minutes" (all others). Existing delivery times are never changed, since
     * other shipping methods or products may use them. The method's previous delivery time is
     * remembered for deactivating the plugin (MinuteDeliveryTimes). The shipping method is written
     * through the DAL (cache invalidation) in a system context: the staff roles may change lead
     * times through the settings route without write access to shipping methods.
     *
     * @param array<string, int> $leadTimes minutes by shipping method id
     */
    public function write(array $leadTimes): void
    {
        if ($leadTimes === []) {
            return;
        }
        $deliveryTimeIds = $this->minuteDeliveryTimes->existing();
        $locales = null;
        foreach (array_unique($leadTimes) as $minutes) {
            if (!isset($deliveryTimeIds[$minutes])) {
                $locales ??= $this->minuteDeliveryTimes->locales();
                $deliveryTimeIds[$minutes] = $this->minuteDeliveryTimes->create($minutes, $locales);
            }
        }
        $current = $this->minuteDeliveryTimes->current(array_map('strval', array_keys($leadTimes)));

        $updates = [];
        foreach ($leadTimes as $id => $minutes) {
            $updates[] = ['id' => $id, 'deliveryTimeId' => $deliveryTimeIds[$minutes]];
        }
        $this->shippingMethodRepository->update($updates, new Context(new SystemSource()));

        foreach ($leadTimes as $id => $minutes) {
            $before = $current[$id] ?? null;
            $previous = $before !== null && $before['unit'] !== ShippingMethodFulfillment::UNIT_MINUTE ? $before['id'] : null;
            $this->minuteDeliveryTimes->remember($id, $previous, $deliveryTimeIds[$minutes]);
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
        $criteria->addAssociation('deliveryTime');
        $criteria->addSorting(new FieldSorting('position'), new FieldSorting('name'));

        return array_values($this->shippingMethodRepository->search($criteria, $context)->getEntities()->getElements());
    }
}
