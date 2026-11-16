<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Support;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words as UiWords;
use App\Support\LocalTime;
use App\Support\Persian;
use Illuminate\Support\Carbon;

/**
 * «📈 نمودار روزانه» (the dashboard's chart): one metric at a time over the dashboard's range — its total and change
 * against the range before (the server's, as the dashboard's figures say them), the daily average and the best day in
 * one card —, the daily curve in bars, and the days one by one, newest first (a 90-day range by its weeks), folded away
 * until tapped. The metric and the range are chips.
 *
 * Actions: `m` (metric), `r` (days: the dashboard's range).
 */
final class DashboardChartScreen extends BaseScreen
{
    public const ID = 'dbc';

    /** Each metric: its name, what it counts, and its headline figure among the server's (the dashboard's). */
    private const METRICS = [
        'revenue' => ['درآمد', 'پولی که هر روز واقعا وارد شد؛ خرید از کیف پول دوباره شمرده نمی‌شود.', 'revenue'],
        'orders' => ['سفارش‌ها', 'تعداد سفارش‌های ثبت‌شده در هر روز.', 'orders'],
        'users' => ['کاربران جدید', 'تعداد ثبت‌نام‌ها در هر روز.', 'new_users'],
    ];

    /** The longest range listed day by day; a longer one is listed by its weeks. */
    private const DAILY_MOST = 31;

    public function show(Admin $admin, array $args): View
    {
        $metric = (string) ($admin->state(self::ID)['metric'] ?? 'revenue');
        $metric = isset(self::METRICS[$metric]) ? $metric : 'revenue';
        [$label, $about, $kpi] = self::METRICS[$metric];
        $range = DashboardScreen::range($admin);
        $data = DashboardScreen::read($admin, $range);
        $days = DashboardScreen::shown($data, $range);

        $points = [];
        foreach (Json::part($data, 'series') as $point) {
            if (is_array($point) && is_string($point['date'] ?? null)) {
                $points[$point['date']] = is_numeric($point[$metric] ?? null) ? (float) $point[$metric] : 0.0;
            }
        }
        $kpis = Json::part($data, 'kpis');
        $figure = is_array($kpis[$kpi] ?? null) ? $kpis[$kpi] : [];
        $total = is_numeric($figure['value'] ?? null) ? (float) $figure['value'] : array_sum($points);
        $previous = is_numeric($figure['previous'] ?? null) ? (float) $figure['previous'] : 0.0;
        $money = $metric === 'revenue';
        $empty = array_filter($points, static fn(float $value): bool => $value > 0) === [];
        $trend = Words::trend($total, $previous);

        $view = View::make('📈 ' . $label . ' · ' . Fmt::number($days) . ' روز گذشته', UiWords::ROOT, Fmt::bare(DashboardScreen::TITLE));
        if ($empty) {
            $view->line('هنوز داده‌ای برای این بازه ثبت نشده است.');
        } else {
            $peak = (string) array_search(max($points), $points, true);
            $view->card([
                'جمع' => '<b>' . self::value($total, $money) . '</b>' . ($trend === null ? '' : ' · ' . $trend),
                'میانگین روزانه' => $money ? Fmt::money((string) round($total / count($points))) : Words::decimal($total / count($points)),
                'بیشترین' => self::day($peak) . ' · ' . self::value($points[$peak], $money),
            ]);
            foreach (Words::curve(array_values($points)) as $line) {
                $view->line('<code>' . $line . '</code>');
            }
            $view->line('<i>' . DashboardScreen::against([$trend !== null], $days) . '</i>');

            $rows = [];
            foreach (self::rows($points) as [$day, $value]) {
                if ($value > 0) {
                    $rows[] = '• ' . $day . ': ' . self::value($value, $money);
                }
            }
            $view->section(count($points) > self::DAILY_MOST ? '📅 هفته به هفته' : '📅 روز به روز')
                ->line('<blockquote expandable>' . implode("\n", $rows) . '</blockquote>');
        }

        $chips = [];
        foreach (self::METRICS as $key => [$name]) {
            $chips[] = Button::to(($key === $metric ? UiWords::CHIP : '') . $name, self::ID, 'm', $key);
        }

        return $view->help(Fmt::e($about) . ' روزها و هفته‌هایی که رقمی نداشتند در فهرست نیامده‌اند؛ تازه‌ترین اول.')
            ->row(...$chips)
            ->row(...DashboardScreen::ranges(self::ID, $days))
            ->back(DashboardScreen::ID)
            ->refresh(self::ID);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        if ($action === 'm' && isset($args[0], self::METRICS[$args[0]])) {
            $admin->remember(self::ID, ['metric' => $args[0]]);
        }
        if ($action === 'r' && isset(DashboardScreen::RANGES[self::id($args)])) {
            $admin->remember(DashboardScreen::ID, ['range' => self::id($args)]);
        }

        return $this->show($admin, []);
    }

    /**
     * The days newest first — or, past DAILY_MOST of them, the weeks, each by the day it ends —, with their figures.
     *
     * @param array<string, float> $points By day, oldest first
     * @return list<array{0: string, 1: float}>
     */
    private static function rows(array $points): array
    {
        $days = array_reverse($points, true);
        if (count($days) <= self::DAILY_MOST) {
            return array_map(static fn(string $day, float $value): array => [self::day($day), $value], array_keys($days), array_values($days));
        }
        $rows = [];
        foreach (array_chunk($days, 7, true) as $week) {
            $rows[] = ['هفته منتهی به ' . self::day((string) array_key_first($week)), array_sum($week)];
        }

        return $rows;
    }

    /** A day of the shop's (Y-m-d) as the panel's chart says it: "۲۶ آبان". */
    private static function day(string $date): string
    {
        try {
            [$year, $month, $day] = Persian::jalali(Carbon::parse($date, LocalTime::zone()));
        } catch (\Throwable) {
            return Fmt::e($date);
        }

        return Persian::digits($day) . ' ' . strtok(Persian::jalaliMonth($year, $month), ' ');
    }

    private static function value(float $value, bool $money): string
    {
        return $money ? Fmt::money((string) round($value)) : Fmt::number(round($value));
    }
}
