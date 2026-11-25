<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Support;

use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Services\Bots;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\GeneralCard;
use App\Modules\Telegram\Admin\Sections\Core\HomeScreen;
use App\Modules\Telegram\Admin\Sections\Panel\Config\AdvancedScreen;
use App\Modules\Telegram\Admin\Sections\Panel\Config\TelegramScreen;
use App\Modules\Telegram\Admin\Sections\Panel\SystemScreen;
use App\Modules\Telegram\Admin\Sections\Panel\UpdateScreen;
use App\Modules\Telegram\Admin\Sections\Sales\AgentAccountScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Screens;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsScreen;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words as UiWords;
use App\Support\LocalTime;
use Illuminate\Support\Carbon;

/**
 * «📊 داشبورد» (the panel's overview, GET /dashboard), at a glance: a greeting; an agent's terms of selling and a
 * traffic shortage when they need an eye (with the way to see to them); the range's figures in one card, each against
 * the range before — and, where the range before had none, said once under it —; the range as chips (7/30/90 days);
 * what waits on a human as buttons straight to its list on the count's tab (HomeScreen::QUEUES), and apart what waits on
 * customers; for the owner, the system only when something there needs fixing, each fix a button to its screen; and
 * the daily curve (DashboardChartScreen) and the latest orders and customers (DashboardRecentScreen) a tap away.
 *
 * Actions: `r` (days: the range), `q` (a queue's key: its list, that tab alone — no search or other narrowing).
 */
final class DashboardScreen extends BaseScreen
{
    public const ID = 'db';

    public const TITLE = Icons::DASHBOARD . ' داشبورد';

    /** The panel's ranges, in days, and the one in force at first. */
    public const RANGES = [7 => '۷ روز', 30 => '۳۰ روز', 90 => '۹۰ روز'];
    public const RANGE = 30;

    /**
     * What waits on customers, shown apart and never counted as waiting on a human (what does: the home's queues,
     * HomeScreen::QUEUES) — each count's button, and the list (and its filter) it leads to.
     */
    private const CUSTOMERS = [
        'pending_orders' => ['🕒 در انتظار پرداخت', 'or', 'status', 'pending'],
        'expiring_soon' => ['⌛️ رو به پایان', 'sb', 'status', 'expiring'],
    ];

    /** The range's figures against the range before (the server's kpis): each one's name. */
    private const FIGURES = ['revenue' => 'درآمد', 'orders' => 'سفارش‌ها', 'new_users' => 'کاربران جدید'];

    public function __construct(
        private readonly Bots $bots,
    ) {}

    public function show(Admin $admin, array $args): View
    {
        $days = self::range($admin);
        $data = self::read($admin, $days);
        $shown = self::shown($data, $days);
        $attention = Json::part($data, 'attention');
        $expiringDays = (int) ($data['expiring_days'] ?? 0);

        $view = View::make(self::TITLE, UiWords::ROOT)
            ->line('👋 ' . self::greeting() . '، ' . Fmt::e($admin->seat->user->name()));
        $standing = $this->standing($admin, $view, $data);
        $this->figures($view, Json::part($data, 'kpis'), $shown);
        [$tasks, $customers] = $this->waiting($admin, $view, $attention, $expiringDays);
        [$fixes, $system] = $admin->seat->isOwner() ? $this->system($admin) : [[], []];
        if ($system !== []) {
            $view->section(Icons::SYSTEM . ' وضعیت سیستم')->lines(...$system);
        }

        return $view->grid(1, ...$standing)
            ->row(...self::ranges(self::ID, $shown))
            ->tiles(...$tasks)
            ->tiles(...$customers)
            ->tiles(...$fixes)
            ->row(Button::to('📈 نمودار روزانه', DashboardChartScreen::ID))
            ->row(
                Button::to(DashboardRecentScreen::ORDERS_TITLE, DashboardRecentScreen::ID, Nav::SHOW, DashboardRecentScreen::ORDERS),
                Button::to(DashboardRecentScreen::USERS_TITLE, DashboardRecentScreen::ID, Nav::SHOW, DashboardRecentScreen::USERS),
            )
            ->help(implode("\n\n", [
                '<b>درآمد</b> پولی است که در این بازه واقعا وارد شد؛ خرید از کیف پول دوباره شمرده نمی‌شود، چون پولش موقع شارژ کیف پول شمرده شده است.',
                '<b>' . UiWords::WAITING . '</b>: کارهایی که دست شما را لازم دارند، هر کدام دکمه‌ای به فهرستش. 🕒 و ⌛️ در انتظار مشتری‌اند: سفارش‌هایی که هنوز پرداخت نشده و اشتراک‌هایی که تا ' . Fmt::number($expiringDays) . ' روز دیگر تمام می‌شوند.',
            ]))
            ->back(Screens::HOME)
            ->refresh(self::ID);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        if ($action === 'r' && isset(self::RANGES[self::id($args)])) {
            $admin->remember(self::ID, ['range' => self::id($args)]);
        }
        $queue = HomeScreen::QUEUES[$args[0] ?? ''] ?? self::CUSTOMERS[$args[0] ?? ''] ?? null;
        if ($action === 'q' && $queue !== null) {
            [, $screen, $filter, $value] = $queue;
            // The list as the count counted it: a search or another narrowing left on it would hide some of them.
            $admin->forget($screen);
            if ($filter !== null && $value !== null) {
                $admin->remember($screen, ['f' => [$filter => $value]]);
            }

            return $admin->open($screen);
        }

        return $this->show($admin, []);
    }

    /** The range the dashboard and its curve show: the admin's pick, RANGE at first. */
    public static function range(Admin $admin): int
    {
        $range = (int) ($admin->state(self::ID)['range'] ?? self::RANGE);

        return isset(self::RANGES[$range]) ? $range : self::RANGE;
    }

    /**
     * The panel's overview for `$days`.
     *
     * @return array<string, mixed>
     * @throws ApiRefusal
     */
    public static function read(Admin $admin, int $days): array
    {
        return $admin->api->get('/dashboard', ['range' => $days]);
    }

    /**
     * The days the overview's figures are of: the range it answered for, else the one asked.
     *
     * @param array<string, mixed> $data
     */
    public static function shown(array $data, int $days): int
    {
        $range = Json::part($data, 'range');

        return is_int($range['days'] ?? null) ? $range['days'] : $days;
    }

    /**
     * The ranges as chips (the panel's «بازه»), the one shown marked; each sets the range on `$screen`.
     *
     * @return list<Button>
     */
    public static function ranges(string $screen, int $days): array
    {
        $chips = [];
        foreach (self::RANGES as $range => $label) {
            $chips[] = Button::to(($range === $days ? UiWords::CHIP : '') . $label, $screen, 'r', $range);
        }

        return $chips;
    }

    /**
     * Where the range before had no figure to compare with, said once (Words::NO_BASELINE) — for every figure, or as
     * «—» beside those it is so for —, else what the changes are against.
     *
     * @param list<bool> $baselines Whether each figure had one
     */
    public static function against(array $baselines, int $days): string
    {
        return match (true) {
            !in_array(false, $baselines, true) => 'درصدها نسبت به ' . Fmt::number($days) . ' روز قبل از آن.',
            !in_array(true, $baselines, true) => Words::NO_BASELINE . '.',
            default => 'درصدها نسبت به ' . Fmt::number($days) . ' روز قبل از آن؛ «' . Fmt::NONE . '»: ' . Words::NO_BASELINE . '.',
        };
    }

    /**
     * The range's figures in one card — each with its change against the range before where that one had a figure —,
     * the active subscriptions and all customers beside them; what the changes are against said once under it.
     *
     * @param array<string, mixed> $kpis
     */
    private function figures(View $view, array $kpis, int $days): void
    {
        $card = [];
        $trends = [];
        foreach (self::FIGURES as $key => $label) {
            $figure = Json::part($kpis, $key);
            $value = Words::figure($figure['value'] ?? null);
            $card[$label] = '<b>' . ($key === 'revenue' ? Fmt::money($value) : Fmt::number($value)) . '</b>';
            $trends[$label] = Words::trend($value, Words::figure($figure['previous'] ?? null));
        }
        $baselines = array_map(static fn(?string $trend): bool => $trend !== null, array_values($trends));
        if (in_array(true, $baselines, true)) {
            foreach ($trends as $label => $trend) {
                $card[$label] .= ' · ' . ($trend ?? Fmt::NONE);
            }
        }
        $card['اشتراک‌های فعال'] = Fmt::number(Words::figure($kpis['active_subscriptions'] ?? null));
        $card['کل کاربران'] = Fmt::number(Words::figure($kpis['users_total'] ?? null));

        $view->section('📅 ' . Fmt::number($days) . ' روز گذشته')
            ->card($card)
            ->line('<i>' . self::against($baselines, $days) . '</i>');
    }

    /**
     * «🔔 در انتظار»: how many wait on a human — or that nothing does —, each queue a button to its list where the seat
     * may open it (else its count said); apart, what waits on customers, the same way.
     *
     * @param array<string, mixed> $attention
     * @return array{0: list<Button>, 1: list<Button>}
     */
    private function waiting(Admin $admin, View $view, array $attention, int $expiringDays): array
    {
        $total = 0;
        foreach (array_keys(HomeScreen::QUEUES) as $key) {
            $total += max(0, (int) ($attention[$key] ?? 0));
        }
        $view->line()->line($total > 0 ? '<b>' . UiWords::WAITING . '</b> · ' . Fmt::number($total) . ' کار' : UiWords::NOTHING_WAITING);

        $buttons = [[], []];
        foreach ([HomeScreen::QUEUES, self::CUSTOMERS] as $group => $queues) {
            foreach ($queues as $key => [$label, $screen]) {
                $count = max(0, (int) ($attention[$key] ?? 0));
                $label = $key === 'expiring_soon' ? $label . ' تا ' . Fmt::number($expiringDays) . ' روز' : $label;
                if ($count === 0) {
                    continue;
                }
                if ($admin->opens($screen)) {
                    $buttons[$group][] = Button::to($label . Fmt::badge($count), self::ID, 'q', $key);
                } else {
                    $view->line('• ' . Fmt::e($label) . ': <b>' . Fmt::number($count) . '</b>');
                }
            }
        }

        return $buttons;
    }

    /**
     * An agent's terms of selling and a traffic shortage, said under the greeting when they need an eye — to the agent
     * of their own bot, to the owner of the agent's —, and the ways to see to them: the agent's account and the main bot
     * where traffic is bought, or the owner's agents.
     *
     * @param array<string, mixed> $data
     * @return list<Button>
     */
    private function standing(Admin $admin, View $view, array $data): array
    {
        $seat = $admin->seat;
        $standing = is_array($data['agency_standing'] ?? null) ? $data['agency_standing'] : null;
        $selling = match (true) {
            $seat->isAgent() => Standing::toAgent($standing, Standing::aboutTheEnd($standing) && self::renewalWaits($admin)),
            $seat->isOwner() => Standing::toOwner($standing),
            default => null,
        };
        $shortage = is_array($data['traffic_shortage'] ?? null)
            ? Standing::shortage($data['traffic_shortage'], match (true) {
                $seat->isAgent() => Fmt::e(Standing::BUY_TRAFFIC),
                $seat->isOwner() => 'حجم ربات را از «نمایندگان» تنظیم کنید.',
                default => '',
            })
            : null;
        if ($selling === null && $shortage === null) {
            return [];
        }
        foreach (array_filter([$selling, $shortage]) as $notice) {
            $view->line($notice);
        }
        if ($seat->isOwner()) {
            return array_values(array_filter([Links::to($admin, Icons::AGENTS . ' نمایندگان', 'ag')]));
        }
        if (!$seat->isAgent()) {
            return [];
        }
        $main = $this->bots->username(CurrentBot::main());

        return array_values(array_filter([
            Links::to($admin, Icons::AGENT_ACCOUNT . ' حساب نمایندگی', AgentAccountScreen::ID)?->primary(),
            $main !== '' ? Button::url('🛒 خرید حجم در ربات اصلی', 'https://t.me/' . rawurlencode($main)) : null,
        ]));
    }

    /**
     * Whether the agent's renewal request waits (their account, GET /account): read only when the end needs an eye,
     * so the notice says it waits instead of a button the account no longer shows.
     */
    private static function renewalWaits(Admin $admin): bool
    {
        try {
            $account = Json::part($admin->api->get('/account'), 'account');
        } catch (ApiRefusal) {
            return false;
        }

        return (Json::part($account, 'renewal')['status'] ?? null) === 'pending';
    }

    /**
     * «وضعیت سیستم» (the owner's), only what needs fixing — the host's PHP, the shop's bot (no token, no messages, off
     * in the settings), scheduled tasks that never ran, a newer release —, each as the panel's system card says it, and
     * a button to the screen that fixes it; nothing while all is well.
     *
     * @return array{0: list<Button>, 1: list<string>} The buttons, and the lines
     */
    private function system(Admin $admin): array
    {
        try {
            $system = (array) ($admin->api->get('/system')['system'] ?? []);
        } catch (ApiRefusal) {
            return [[], []];
        }
        try {
            $update = (array) ($admin->api->get('/system/update')['update'] ?? []);
        } catch (ApiRefusal) {
            $update = [];
        }

        $lines = [];
        $fixes = [];
        $php = is_string($system['php'] ?? null) ? $system['php'] : '';
        if (($system['php_safe'] ?? true) === false) {
            // The panel's banner over every page (apps/admin/php-banner.tsx), word for word — its mark the chips' 🔴.
            $lines[] = '🔴 <b>' . Fmt::ltr('PHP') . ' این هاست (' . Fmt::ltr($php) . ') حفره امنیتی جدی دارد.</b> آن را از تنظیمات هاست (مثلا «' . Fmt::ltr('Select PHP Version') . '» در ' . Fmt::ltr('cPanel') . ') به ' . Fmt::ltr('PHP ' . (is_string($system['php_required'] ?? null) ? $system['php_required'] : '')) . ' یا بالاتر ببرید؛ تا آن وقت به‌روزرسانی فروشگاه نصب نمی‌شود.';
        }

        $bot = Json::part($system, 'bot');
        $main = (int) ($bot['id'] ?? 1) === 1;
        $username = is_string($bot['username'] ?? null) && $bot['username'] !== '' ? $bot['username'] : null;
        $name = ($main ? 'ربات اصلی' : 'ربات این فروشگاه') . ($username === null ? '' : ' ' . Fmt::ltr('@' . $username));
        $mode = is_string($bot['mode'] ?? null) ? $bot['mode'] : 'offline';
        if (!Json::on($bot, 'configured')) {
            $lines[] = '🔴 ' . $name . ': وصل نشده — ' . ($main ? 'توکن ربات تنظیم نشده است' : 'نماینده هنوز توکن رباتش را از ربات اصلی نفرستاده است');
            $fixes[] = $main ? Links::to($admin, Icons::BOT . ' تنظیم توکن ربات', TelegramScreen::ID) : null;
        } elseif ($mode === 'offline') {
            $lines[] = '🟡 ' . $name . ': پیامی نمی‌رسد — ' . Fmt::ltr('Webhook') . ' ثبت نشده است';
            $fixes[] = Links::to($admin, Icons::SYSTEM . ' ثبت Webhook', SystemScreen::ID);
        } elseif (!Json::on($bot, 'enabled')) {
            $lines[] = '🟡 ' . $name . ': غیرفعال از تنظیمات — مشتری‌ها فقط پیام غیرفعال بودن را می‌گیرند';
            $fixes[] = Links::to($admin, GeneralCard::TITLE . ' · ربات فعال باشد', GeneralCard::ID, SettingsScreen::FIELD, 'enabled');
        }

        $cron = Json::part($system, 'cron');
        if (!is_string($cron['last_run_at'] ?? null)) {
            $lines[] = '🟡 کارهای زمان‌بندی‌شده هنوز اجرا نشده‌اند';
            $fixes[] = Links::to($admin, Icons::ADVANCED . ' راه‌اندازی Cron', AdvancedScreen::ID);
        }

        $latest = is_array($update['latest'] ?? null) ? $update['latest'] : null;
        if (Json::on($update, 'available') && $latest !== null) {
            $lines[] = '🔵 نسخه تازه آماده نصب است: ' . Fmt::ltr(is_string($latest['version'] ?? null) ? $latest['version'] : null);
            $fixes[] = Links::to($admin, UpdateScreen::TITLE, UpdateScreen::ID)?->primary();
        }

        return [array_values(array_filter($fixes)), $lines];
    }

    /** «صبح بخیر» … by the hour on the shop's clock. */
    private static function greeting(): string
    {
        $hour = (int) LocalTime::of(Carbon::now())->format('G');

        return match (true) {
            $hour >= 5 && $hour < 11 => 'صبح بخیر',
            $hour >= 11 && $hour < 15 => 'ظهر بخیر',
            $hour >= 15 && $hour < 19 => 'عصر بخیر',
            default => 'شب بخیر',
        };
    }
}
