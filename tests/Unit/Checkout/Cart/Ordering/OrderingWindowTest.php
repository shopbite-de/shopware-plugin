<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Checkout\Cart\Ordering;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShopBite\BusinessHour\BusinessHourCollection;
use ShopBite\BusinessHour\BusinessHourEntity;
use ShopBite\Checkout\Cart\Ordering\OrderingWindow;
use ShopBite\Holiday\HolidayCollection;
use ShopBite\Holiday\HolidayEntity;

#[CoversClass(OrderingWindow::class)]
class OrderingWindowTest extends TestCase
{
    private const string BERLIN = 'Europe/Berlin';

    /**
     * 2026-11-16 is a Monday. Monday 11:30–14:00 and 17:00–22:00, no hours on
     * Tuesday, Friday 18:00–02:00 (overnight), Saturday 17:00–00:00.
     *
     * @return iterable<string, array{string, string|null}>
     */
    public static function berlinTimes(): iterable
    {
        yield 'during lunch' => ['2026-11-16 12:00', null];
        yield 'before opening, order for later today' => ['2026-11-16 09:00', null];
        yield 'between the intervals' => ['2026-11-16 15:00', null];
        yield 'last slot reachable' => ['2026-11-16 21:35', null];
        yield 'last slot passed' => ['2026-11-16 21:40', OrderingWindow::CLOSED];
        yield 'after closing' => ['2026-11-16 23:00', OrderingWindow::CLOSED];
        yield 'day without hours' => ['2026-11-17 12:00', OrderingWindow::CLOSED];
        yield 'overnight, before midnight' => ['2026-11-20 23:30', null];
        yield 'overnight, after midnight' => ['2026-11-21 01:30', null];
        yield 'closing at midnight' => ['2026-11-21 23:30', null];
        yield 'after closing at midnight' => ['2026-11-22 00:10', OrderingWindow::CLOSED];
    }

    #[DataProvider('berlinTimes')]
    public function testOpeningHours(string $berlinTime, ?string $expected): void
    {
        $now = new \DateTimeImmutable($berlinTime, new \DateTimeZone(self::BERLIN));

        $this->assertSame($expected, $this->window()->closedReason($now));
    }

    public function testOvernightHoursDoNotReachTheNextEvening(): void
    {
        // Saturday 02:30: Friday's interval is over, Saturday opens at 17:00 (order for later).
        $window = $this->window();
        $this->assertNull($window->closedReason($this->berlin('2026-11-21 02:30')));

        // Sunday 01:00: nothing open since Saturday 00:00 and nothing later on Sunday.
        $this->assertSame(OrderingWindow::CLOSED, $window->closedReason($this->berlin('2026-11-22 01:00')));
    }

    public function testTimeIsTakenInTheGivenTimeZone(): void
    {
        // 21:30 UTC is 22:30 in Berlin: closed there, although 21:30 would be open.
        $now = new \DateTimeImmutable('2026-11-16 21:30', new \DateTimeZone('UTC'));

        $this->assertSame(OrderingWindow::CLOSED, $this->window()->closedReason($now));
        $this->assertNull($this->window(timeZone: 'UTC')->closedReason($now));
    }

    public function testDeliveryTimeMovesTheLastSlot(): void
    {
        $now = $this->berlin('2026-11-16 21:00');

        $this->assertNull($this->window(deliveryMinutes: 60)->closedReason($now));
        $this->assertSame(OrderingWindow::CLOSED, $this->window(deliveryMinutes: 70)->closedReason($now));
    }

    public function testHolidayClosesTheWholeDay(): void
    {
        // Stored in UTC like the DAL does: Monday 00:00 to 23:59 in Berlin.
        $holidays = [$this->holiday('2026-11-15 23:00', '2026-11-16 22:59')];

        $this->assertSame(OrderingWindow::HOLIDAY, $this->window($holidays)->closedReason($this->berlin('2026-11-16 12:00')));
        $this->assertNull($this->window($holidays)->closedReason($this->berlin('2026-11-20 23:30')));
    }

    public function testHolidaySpanningSeveralDaysIncludesBothEnds(): void
    {
        $holidays = [$this->holiday('2026-11-19 23:00', '2026-11-20 23:00')];

        $this->assertSame(OrderingWindow::HOLIDAY, $this->window($holidays)->closedReason($this->berlin('2026-11-20 19:00')));
        $this->assertSame(OrderingWindow::HOLIDAY, $this->window($holidays)->closedReason($this->berlin('2026-11-21 18:00')));
    }

    public function testHolidayDateIsTakenInTheGivenTimeZone(): void
    {
        // 23:30 UTC on Monday is already Tuesday in Berlin.
        $holidays = [$this->holiday('2026-11-16 23:30', '2026-11-16 23:30')];

        $this->assertNull($this->window($holidays)->closedReason($this->berlin('2026-11-16 12:00')));
    }

    public function testOvernightIntervalOfAHolidayDoesNotCount(): void
    {
        // Friday is a holiday; at Saturday 01:30 only Saturday's own hours count.
        $holidays = [$this->holiday('2026-11-19 23:00', '2026-11-20 22:59')];
        $hours = [$this->hour(5, '18:00', '02:00')];

        $this->assertSame(
            OrderingWindow::CLOSED,
            $this->window($holidays, $hours)->closedReason($this->berlin('2026-11-21 01:30')),
        );
    }

    public function testInvalidTimesAreIgnored(): void
    {
        $hours = [$this->hour(1, 'abc', '22:00'), $this->hour(1, '11:00', '25:99')];

        $this->assertSame(OrderingWindow::CLOSED, $this->window(hours: $hours)->closedReason($this->berlin('2026-11-16 12:00')));
    }

    private function berlin(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone(self::BERLIN));
    }

    /**
     * @param list<HolidayEntity>           $holidays
     * @param list<BusinessHourEntity>|null $hours
     */
    private function window(
        array $holidays = [],
        ?array $hours = null,
        string $timeZone = self::BERLIN,
        int $deliveryMinutes = 30,
    ): OrderingWindow {
        $hours ??= [
            $this->hour(1, '11:30', '14:00'),
            $this->hour(1, '17:00', '22:00'),
            $this->hour(5, '18:00', '02:00'),
            $this->hour(6, '17:00', '00:00'),
        ];

        return new OrderingWindow(
            new BusinessHourCollection($hours),
            new HolidayCollection($holidays),
            new \DateTimeZone($timeZone),
            $deliveryMinutes,
        );
    }

    private function hour(int $dayOfWeek, string $opening, string $closing): BusinessHourEntity
    {
        $hour = new BusinessHourEntity();
        $hour->setId(bin2hex(random_bytes(16)));
        $hour->setDayOfWeek($dayOfWeek);
        $hour->setOpeningTime($opening);
        $hour->setClosingTime($closing);

        return $hour;
    }

    private function holiday(string $startUtc, string $endUtc): HolidayEntity
    {
        $utc = new \DateTimeZone('UTC');
        $holiday = new HolidayEntity();
        $holiday->setId(bin2hex(random_bytes(16)));
        $holiday->setStart(new \DateTimeImmutable($startUtc, $utc));
        $holiday->setEnd(new \DateTimeImmutable($endUtc, $utc));

        return $holiday;
    }
}
