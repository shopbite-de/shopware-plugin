<?php

declare(strict_types=1);

namespace ShopBite\Checkout\Cart\Ordering;

use Psr\Clock\ClockInterface;
use ShopBite\BusinessHour\BusinessHourCollection;
use ShopBite\Fulfillment\ShippingMethodFulfillment;
use ShopBite\Holiday\HolidayCollection;
use ShopBite\Settings\RestaurantSettings;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartValidatorInterface;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\SalesChannelApiSource;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\Clock\NativeClock;

/**
 * Blocks orders from the Store API while ordering is paused for the sales
 * channel, on holidays and outside the opening hours (see OrderingWindow), using the lead time
 * of the cart's selected shipping method.
 * Orders entered in the administration (Admin API, or the order proxy with
 * its price overwrite permission) are not checked, nor is an empty cart.
 *
 * @psalm-api
 */
final readonly class OrderingWindowValidator implements CartValidatorInterface
{
    public const string DEFAULT_TIME_ZONE = 'Europe/Berlin';

    /** Set by the administration's order proxy (CheckoutPermissions::ALLOW_PRODUCT_PRICE_OVERWRITES). */
    private const string ADMIN_ORDER_PERMISSION = 'allowProductPriceOverwrites';

    /**
     * @param EntityRepository<BusinessHourCollection> $businessHourRepository
     * @param EntityRepository<HolidayCollection>      $holidayRepository
     *
     * @psalm-suppress PossiblyUnusedMethod Constructed by the container
     */
    public function __construct(
        private RestaurantSettings $settings,
        private EntityRepository $businessHourRepository,
        private EntityRepository $holidayRepository,
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    #[\Override]
    public function validate(Cart $cart, ErrorCollection $errors, SalesChannelContext $context): void
    {
        if ($cart->getLineItems()->count() === 0
            || !$context->getContext()->getSource() instanceof SalesChannelApiSource
            || $context->hasPermission(self::ADMIN_ORDER_PERMISSION)
        ) {
            return;
        }

        $salesChannelId = $context->getSalesChannelId();
        $settings = $this->settings->read($salesChannelId, $context->getSalesChannel()->getNavigationCategoryId());
        if (!$settings['orderingOpen']) {
            $errors->add(new OrderingClosedError(OrderingWindow::PAUSED));

            return;
        }

        $now = $this->clock->now();

        $hoursCriteria = new Criteria();
        $hoursCriteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelId));

        $holidayCriteria = new Criteria();
        $holidayCriteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelId));
        $holidayCriteria->addFilter(new RangeFilter('end', [
            RangeFilter::GTE => $now->setTimezone(new \DateTimeZone('UTC'))->modify('-2 days')->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]));

        $window = new OrderingWindow(
            $this->businessHourRepository->search($hoursCriteria, $context->getContext())->getEntities(),
            $this->holidayRepository->search($holidayCriteria, $context->getContext())->getEntities(),
            $this->timeZone($context),
            ShippingMethodFulfillment::leadTimeMinutes($context->getShippingMethod()),
        );

        $reason = $window->closedReason($now);
        if ($reason !== null) {
            $errors->add(new OrderingClosedError($reason));
        }
    }

    /**
     * The sales channel's business time zone (Shopware 6.7.13+), else Europe/Berlin.
     */
    private function timeZone(SalesChannelContext $context): \DateTimeZone
    {
        $salesChannel = $context->getSalesChannel();
        // Read as a var: the field does not exist before 6.7.13.
        $timeZone = $salesChannel->getVars()['businessTimeZone'] ?? null;

        try {
            return new \DateTimeZone(\is_string($timeZone) && $timeZone !== '' ? $timeZone : self::DEFAULT_TIME_ZONE);
        } catch (\Exception) {
            return new \DateTimeZone(self::DEFAULT_TIME_ZONE);
        }
    }
}
