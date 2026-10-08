<?php

declare(strict_types=1);

namespace App\Core\Database;

use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The days a paged list is narrowed to, as PageRequest::dates() read them: whole days of the shop's calendar
 * (LocalTime), the first and the last both included, either alone. On a query they are the moments the database keeps
 * (UTC): from the shop's midnight that starts the first day up to the one that ends the last — the dashboard's way of
 * turning the shop's days into moments.
 */
final class DateRange
{
    /** A day as the panels send it: a Gregorian calendar date. */
    public const FORMAT = 'Y-m-d';

    private function __construct(
        /** The first moment of the range (UTC); null when it has no start. */
        public readonly ?Carbon $from,
        /** The first moment past it (UTC); null when it has no end. */
        public readonly ?Carbon $until,
    ) {}

    /** The days from `$from` to `$to` (FORMAT); a bound that is not a date is no bound. */
    public static function of(string $from, string $to): self
    {
        return new self(self::midnight($from)?->utc(), self::midnight($to)?->addDay()->utc());
    }

    /**
     * The rows whose `$column` (a moment) falls on one of the days.
     *
     * @template TModel of Model
     * @param Builder<TModel> $query
     */
    public function apply(Builder $query, string $column): void
    {
        if ($this->from !== null) {
            $query->where($query->qualifyColumn($column), '>=', $this->from);
        }
        if ($this->until !== null) {
            $query->where($query->qualifyColumn($column), '<', $this->until);
        }
    }

    /** The shop's midnight that starts the day `$date` names; null for anything but a real date (2026-02-31 is none). */
    private static function midnight(string $date): ?Carbon
    {
        $day = \DateTimeImmutable::createFromFormat('!' . self::FORMAT, $date, LocalTime::zone());

        return $day !== false && $day->format(self::FORMAT) === $date ? Carbon::instance($day) : null;
    }
}
