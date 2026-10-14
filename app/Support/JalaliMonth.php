<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * A month of the Jalali calendar on the shop's clock (LocalTime): its key («1405-07»), its name («مهر ۱۴۰۵») and when it
 * begins and ends, in UTC — what a rule of a month (an agent's minimum purchase) is judged by.
 */
final class JalaliMonth
{
    private function __construct(
        public readonly int $year,
        public readonly int $month,
        /** Its first moment, in UTC. */
        public readonly Carbon $start,
        /** The next month's first moment, in UTC: the month is [start, end). */
        public readonly Carbon $end,
    ) {}

    /** The month the moment falls in, on the shop's clock. */
    public static function of(\DateTimeInterface $moment): self
    {
        $local = LocalTime::of($moment);
        [$year, $month, $day] = Persian::jalali($local);
        $start = $local->setTime(0, 0)->modify(sprintf('-%d days', $day - 1));
        // A Jalali month is 29 to 31 days long: from its 30th day on, a day at a time until the next one begins.
        $end = $start->modify('+29 days');
        while (Persian::jalali($end)[1] === $month) {
            $end = $end->modify('+1 day');
        }

        return new self($year, $month, Carbon::instance($start)->utc(), Carbon::instance($end)->utc());
    }

    /** The month now. */
    public static function now(): self
    {
        return self::of(Carbon::now());
    }

    /** «1405-07»: what a month is kept as — two keys compare as their months do. */
    public function key(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    /** «مهر ۱۴۰۵» */
    public function name(): string
    {
        return Persian::jalaliMonth($this->year, $this->month);
    }

    public function previous(): self
    {
        return self::of($this->start->copy()->subSecond());
    }

    /** Whole days from `$moment` to the month's end, a part of one counting as one (0 once it is over). */
    public function daysLeft(\DateTimeInterface $moment): int
    {
        $seconds = $this->end->getTimestamp() - $moment->getTimestamp();

        return $seconds <= 0 ? 0 : intdiv($seconds + 86_399, 86_400);
    }
}
