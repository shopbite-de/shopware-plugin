<?php

declare(strict_types=1);

namespace ShopBite\Checkout\Cart\Ordering;

use ShopBite\BusinessHour\BusinessHourCollection;
use ShopBite\Holiday\HolidayCollection;

/**
 * Whether an order can be placed now, by the rule of the storefront's delivery
 * time selection: not on a holiday (whole days in the shop's time zone), and
 * only while a service interval of today ends no earlier than now plus the
 * delivery time. An order before opening is for later today and allowed.
 *
 * Unlike the storefront, intervals that close at or after midnight
 * (closing time <= opening time, e.g. 18:00–02:00) run into the next day.
 * A few minutes of grace cover the time a customer spends in the checkout.
 */
final readonly class OrderingWindow
{
    public const string PAUSED = 'shopbite-ordering-paused';
    public const string HOLIDAY = 'shopbite-ordering-holiday';
    public const string CLOSED = 'shopbite-ordering-closed';

    public const int GRACE_MINUTES = 5;

    private const string TIME_PATTERN = '/^(\d{2}):(\d{2})$/';

    public function __construct(
        private BusinessHourCollection $businessHours,
        private HolidayCollection $holidays,
        private \DateTimeZone $timeZone,
        private int $deliveryMinutes,
    ) {
    }

    /**
     * Null when ordering is possible, else HOLIDAY or CLOSED.
     */
    public function closedReason(\DateTimeImmutable $now): ?string
    {
        $now = $now->setTimezone($this->timeZone);
        $today = $now->setTime(0, 0);

        if ($this->isHoliday($today)) {
            return self::HOLIDAY;
        }

        $earliest = $now->setTimestamp($now->getTimestamp() + 60 * ($this->deliveryMinutes - self::GRACE_MINUTES));

        // Yesterday's intervals count only as far as they run past midnight.
        foreach ([$today->modify('-1 day'), $today] as $day) {
            if ($day < $today && $this->isHoliday($day)) {
                continue;
            }
            foreach ($this->intervals($day) as [, $end]) {
                if ($end >= $earliest) {
                    return null;
                }
            }
        }

        return self::CLOSED;
    }

    private function isHoliday(\DateTimeImmutable $day): bool
    {
        $date = $day->format('Y-m-d');
        foreach ($this->holidays as $holiday) {
            $start = \DateTimeImmutable::createFromInterface($holiday->getStart())->setTimezone($this->timeZone)->format('Y-m-d');
            $end = \DateTimeImmutable::createFromInterface($holiday->getEnd())->setTimezone($this->timeZone)->format('Y-m-d');
            if ($start <= $date && $date <= $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{\DateTimeImmutable, \DateTimeImmutable}>
     */
    private function intervals(\DateTimeImmutable $day): array
    {
        $dayOfWeek = (int) $day->format('N');
        $intervals = [];
        foreach ($this->businessHours as $businessHour) {
            if ($businessHour->getDayOfWeek() !== $dayOfWeek) {
                continue;
            }
            $start = $this->at($day, $businessHour->getOpeningTime());
            $end = $this->at($day, $businessHour->getClosingTime());
            if ($start === null || $end === null) {
                continue;
            }
            if ($end <= $start) {
                $end = $end->modify('+1 day');
            }
            $intervals[] = [$start, $end];
        }

        return $intervals;
    }

    private function at(\DateTimeImmutable $day, string $time): ?\DateTimeImmutable
    {
        if (preg_match(self::TIME_PATTERN, $time, $match) !== 1) {
            return null;
        }
        $hours = (int) $match[1];
        $minutes = (int) $match[2];
        if ($minutes > 59 || $hours > 24 || ($hours === 24 && $minutes > 0)) {
            return null;
        }

        return $day->setTime($hours, $minutes);
    }
}
