<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Sales;

use App\Core\Database\DateRange;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Support\JalaliMonth;
use App\Support\LocalTime;
use App\Support\Persian;
use Illuminate\Support\Carbon;

/**
 * What narrows a sales list beside its tabs and search — the panel's selects and pills: a type, the days (its date
 * filter's presets or a range), the order, a server, a kind, one customer. Kept in the list's state as the query it
 * sends (`x`), with the words that say each (`said`) and the date preset picked (`days`), so the list shows what it is
 * narrowed to and its filter screen what is in force.
 */
final class Narrowing
{
    /** The date filter's presets, the panel's, counted back from today on the shop's clock. */
    public const DAYS = [
        'today' => 'امروز',
        'yesterday' => 'دیروز',
        'week' => '۷ روز اخیر',
        'thirty' => '۳۰ روز اخیر',
        'month' => 'این ماه',
        'last' => 'ماه قبل',
    ];

    /**
     * The list's narrowing as the query its route takes.
     *
     * @param array<string, mixed> $state The list's state
     * @return array<string, string>
     */
    public static function query(array $state): array
    {
        $kept = Json::part($state, 'x');
        $query = [];
        foreach ($kept as $key => $value) {
            if (is_scalar($value) && (string) $value !== '') {
                $query[(string) $key] = (string) $value;
            }
        }

        return $query;
    }

    /**
     * What the list is narrowed to, in words («نوع: خرید», «تاریخ: ۷ روز اخیر»).
     *
     * @param array<string, mixed> $state The list's state
     * @return list<string>
     */
    public static function words(array $state): array
    {
        $query = self::query($state);
        $said = Json::part($state, 'said');
        $words = [];
        foreach ($said as $key => $word) {
            if (is_string($word) && isset($query[(string) $key])) {
                $words[] = $word;
            }
        }
        $period = self::period($state);
        if ($period !== null) {
            $words[] = 'تاریخ: ' . $period;
        }

        return $words;
    }

    /**
     * The days the list is narrowed to, in words — its preset's while they are still the preset's days, else the days
     * themselves («۱ مهر ۱۴۰۵ تا ۱۴ مهر ۱۴۰۵»); null for none.
     *
     * @param array<string, mixed> $state The list's state
     */
    public static function period(array $state): ?string
    {
        $query = self::query($state);
        $from = $query['from'] ?? '';
        $to = $query['to'] ?? '';
        if ($from === '' && $to === '') {
            return null;
        }
        $preset = is_string($state['days'] ?? null) ? $state['days'] : null;
        if ($preset !== null && isset(self::DAYS[$preset]) && self::preset($preset) === [$from, $to]) {
            return self::DAYS[$preset];
        }
        $word = static fn(string $day): string => Persian::date(new \DateTimeImmutable($day . ' 12:00', LocalTime::zone()));

        return match (true) {
            $from !== '' && $to !== '' => $from === $to ? $word($from) : $word($from) . ' تا ' . $word($to),
            $from !== '' => 'از ' . $word($from),
            default => 'تا ' . $word($to),
        };
    }

    /**
     * The date preset in force: its key while the list's days are still its days; null for none.
     *
     * @param array<string, mixed> $state The list's state
     */
    public static function presetOf(array $state): ?string
    {
        $query = self::query($state);
        $preset = is_string($state['days'] ?? null) ? $state['days'] : null;

        return $preset !== null && isset(self::DAYS[$preset]) && self::preset($preset) === [$query['from'] ?? '', $query['to'] ?? ''] ? $preset : null;
    }

    /**
     * Narrow the list `$list` by `$values` (a value '' takes its narrowing off), each said in `$said` — from its first
     * page.
     *
     * @param array<string, string> $values
     * @param array<string, string> $said
     */
    public static function put(Admin $admin, string $list, array $values, array $said = []): void
    {
        $state = $admin->state($list);
        $query = self::query($state);
        $words = Json::part($state, 'said');
        foreach ($values as $key => $value) {
            if ($value === '') {
                unset($query[$key], $words[$key]);

                continue;
            }
            $query[$key] = $value;
            if (isset($said[$key])) {
                $words[$key] = $said[$key];
            }
        }
        $admin->remember($list, ['x' => $query, 'said' => $words, 'page' => 1]);
    }

    /** Narrow the list `$list` to the days from `$from` to `$to` (DateRange::FORMAT; '' for open), a preset's when named. */
    public static function dates(Admin $admin, string $list, string $from, string $to, ?string $preset = null): void
    {
        self::put($admin, $list, ['from' => $from, 'to' => $to]);
        $admin->remember($list, ['days' => $preset]);
    }

    /** The list `$list` narrowed by nothing, from its first page; its tab and search stay. */
    public static function clear(Admin $admin, string $list): void
    {
        $admin->remember($list, ['x' => [], 'said' => [], 'days' => null, 'page' => 1]);
    }

    /**
     * The days a preset names, as of today on the shop's clock: [from, to].
     *
     * @return array{0: string, 1: string}
     */
    public static function preset(string $key): array
    {
        $today = LocalTime::of(Carbon::now());
        $day = static fn(\DateTimeImmutable $moment): string => $moment->format(DateRange::FORMAT);

        return match ($key) {
            'yesterday' => [$day($today->modify('-1 day')), $day($today->modify('-1 day'))],
            'week' => [$day($today->modify('-6 days')), $day($today)],
            'thirty' => [$day($today->modify('-29 days')), $day($today)],
            'month' => self::month(JalaliMonth::now()),
            'last' => self::month(JalaliMonth::now()->previous()),
            default => [$day($today), $day($today)],
        };
    }

    /**
     * The days an admin typed — one, or a range: Jalali («۱۴۰۵/۰۷/۰۱ تا ۱۴۰۵/۰۷/۱۵») or Gregorian (2026-10-01) — as
     * [from, to], earlier first; null when a date is none.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function typed(string $text): ?array
    {
        if (preg_match_all('/(\d{4})\s*[\/\-.]\s*(\d{1,2})\s*[\/\-.]\s*(\d{1,2})/', Persian::latinDigits($text), $found, PREG_SET_ORDER) < 1) {
            return null;
        }
        $days = [];
        foreach (array_slice($found, 0, 2) as $date) {
            $day = self::day((int) $date[1], (int) $date[2], (int) $date[3]);
            if ($day === null) {
                return null;
            }
            $days[] = $day;
        }
        sort($days);

        return [$days[0], $days[count($days) - 1]];
    }

    /**
     * The first and last days of a Jalali month on the shop's clock.
     *
     * @return array{0: string, 1: string}
     */
    private static function month(JalaliMonth $month): array
    {
        return [LocalTime::of($month->start)->format(DateRange::FORMAT), LocalTime::of($month->end->copy()->subSecond())->format(DateRange::FORMAT)];
    }

    /**
     * A date as the panels send a day (DateRange::FORMAT): a Gregorian one as it is, a Jalali one (a year before 1700)
     * found by walking from its estimate to the day whose Jalali date it is; null for a day there is none of.
     */
    private static function day(int $year, int $month, int $day): ?string
    {
        if ($year >= 1700) {
            return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
        }
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31 || ($month > 6 && $day > 30)) {
            return null;
        }
        $offset = $month <= 6 ? ($month - 1) * 31 + $day - 1 : 186 + ($month - 7) * 30 + $day - 1;
        $moment = (new \DateTimeImmutable(sprintf('%04d-03-21 12:00', $year + 621), LocalTime::zone()))->modify("+{$offset} days");
        $wanted = sprintf('%04d%02d%02d', $year, $month, $day);
        for ($step = 0; $step < 4; $step++) {
            $found = vsprintf('%04d%02d%02d', Persian::jalali($moment));
            if ($found === $wanted) {
                return $moment->format(DateRange::FORMAT);
            }
            $moment = $moment->modify($found < $wanted ? '+1 day' : '-1 day');
        }

        return null;
    }
}
