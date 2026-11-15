<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Support;

use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Services\Bots;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Screens;
use App\Modules\Telegram\Admin\Ui\View;
use App\Support\LocalTime;
use Illuminate\Support\Carbon;

/**
 * «📊 داشبورد» (the panel's overview, GET /dashboard): a greeting, the range's headline figures against the range
 * before, what needs a hand — an agent's terms of selling and traffic shortage first (for the agent, their account and
 * the main bot where traffic is bought a tap away), each queue a button to its list as the count's tab shows it —, apart
 * from it what waits on customers, and for the owner the system's state. The daily curve (DashboardChartScreen) and the
 * latest orders and customers (DashboardRecentScreen) are a tap away.
 *
 * Actions: `r` (days: the range), `q` (a queue's key: its list, that tab alone — no search or other narrowing).
 */
final class DashboardScreen extends BaseScreen
{
    public const ID = 'db';

    /** The panel's ranges, in days, and the one in force at first. */
    public const RANGES = [7 => '۷ روز', 30 => '۳۰ روز', 90 => '۹۰ روز'];
    public const RANGE = 30;

    /**
     * What waits on support, as the panel's attention card counts it: each count's words, and the list (and its filter)
     * it leads to.
     */
    private const QUEUES = [
        'payments_to_review' => ['💳 پرداخت‌های در انتظار بررسی', 'py', 'status', 'awaiting_review'],
        'held_payments' => ['⏸ پرداخت‌های نگه داشته شده', 'py', 'status', 'held'],
        'stuck_orders' => ['🧯 سفارش‌های نیازمند رسیدگی', 'or', 'status', 'stuck'],
        'open_tickets' => ['🎧 تیکت‌های در انتظار پاسخ', TicketsScreen::ID, 'status', 'open'],
        'pending_reviews' => ['⭐️ نظرات در انتظار بررسی', ReviewsScreen::ID, 'status', 'pending'],
        'servers_with_errors' => ['🖥 سرورهای دارای خطا', 'sv', null, null],
    ];

    /** What waits on customers, shown apart and never counted as waiting on support. */
    private const CUSTOMERS = [
        'expiring_soon' => ['⌛️ اشتراک‌های رو به انقضا', 'sb', 'status', 'expiring'],
        'pending_orders' => ['🕒 سفارش‌های در انتظار پرداخت', 'or', 'status', 'pending'],
    ];

    /** How a bot gets its updates (the panel's BOT_MODE). */
    private const BOT_MODE = ['webhook' => 'Webhook', 'polling' => 'پولینگ', 'offline' => 'مشکل دارد'];

    /** The agent's own account (Sections\Sales\AgentAccountScreen). */
    private const ACCOUNT = 'acc';

    public function __construct(
        private readonly Bots $bots,
    ) {}

    public function show(Admin $admin, array $args): View
    {
        $days = self::range($admin);
        $data = self::read($admin, $days);
        $shown = self::shown($data, $days);
        $kpis = Json::part($data, 'kpis');
        $attention = Json::part($data, 'attention');
        $expiringDays = (int) ($data['expiring_days'] ?? 0);

        $view = View::make('📊 داشبورد', 'مدیریت')
            ->line('👋 ' . self::greeting() . '، ' . Fmt::e($admin->seat->user->name()))
            ->line('خلاصه وضعیت فروشگاه در ' . Fmt::number($shown) . ' روز گذشته')
            ->line();

        $revenue = Json::part($kpis, 'revenue');
        $orders = Json::part($kpis, 'orders');
        $users = Json::part($kpis, 'new_users');
        $expiring = (int) ($attention['expiring_soon'] ?? 0);
        $view->lines(
            '💰 درآمد: <b>' . Fmt::money(self::figure($revenue['value'] ?? '0')) . '</b> · ' . Words::change(self::figure($revenue['value'] ?? 0), self::figure($revenue['previous'] ?? 0)),
            '🛒 سفارش‌ها: <b>' . Fmt::number(self::figure($orders['value'] ?? 0)) . '</b> · ' . Words::change(self::figure($orders['value'] ?? 0), self::figure($orders['previous'] ?? 0)),
            '👤 کاربران جدید: <b>' . Fmt::number(self::figure($users['value'] ?? 0)) . '</b> · ' . Words::change(self::figure($users['value'] ?? 0), self::figure($users['previous'] ?? 0)) . ' (' . Fmt::number(self::figure($kpis['users_total'] ?? 0)) . ' کاربر در کل)',
            '📡 اشتراک‌های فعال: <b>' . Fmt::number(self::figure($kpis['active_subscriptions'] ?? 0)) . '</b> · ' . ($expiring > 0
                ? Fmt::number($expiring) . ' اشتراک تا ' . Fmt::number($expiringDays) . ' روز آینده تمام می‌شود'
                : 'هیچ اشتراکی تا ' . Fmt::number($expiringDays) . ' روز آینده تمام نمی‌شود'),
            '<i>درصدها نسبت به ' . Fmt::number($shown) . ' روز قبل از آن؛ درآمد پولی است که واقعا وارد شد و خرید از کیف پول دوباره شمرده نمی‌شود.</i>',
        );

        $buttons = $this->attention($admin, $view, $data, $attention, $expiringDays);
        $fixes = $admin->seat->isOwner() ? $this->system($admin, $view) : [];

        $view->row(...self::ranges(self::ID, $shown));
        $view->grid(1, ...$buttons);
        $view->row(Button::to('📈 نمودار روزانه', DashboardChartScreen::ID));
        $view->row(Button::to('🛒 آخرین سفارش‌ها', DashboardRecentScreen::ID, Nav::SHOW, DashboardRecentScreen::ORDERS), Button::to('👥 مشتریان جدید', DashboardRecentScreen::ID, Nav::SHOW, DashboardRecentScreen::USERS));
        $view->row(...$fixes);

        return $view->row(Button::to('🔄 به‌روزرسانی', self::ID))->back(Screens::HOME);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        if ($action === 'r' && isset(self::RANGES[self::id($args)])) {
            $admin->remember(self::ID, ['range' => self::id($args)]);
        }
        $queue = self::QUEUES[$args[0] ?? ''] ?? self::CUSTOMERS[$args[0] ?? ''] ?? null;
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
            $chips[] = Button::to(($range === $days ? '● ' : '') . $label, $screen, 'r', $range);
        }

        return $chips;
    }

    /**
     * «نیازمند توجه»: the agent's terms and the traffic shortage, then the queues that wait on support — the total said
     * first —, and apart, what waits on customers; each count a button to its list where that is built.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $attention
     * @return list<Button>
     */
    private function attention(Admin $admin, View $view, array $data, array $attention, int $expiringDays): array
    {
        $seat = $admin->seat;
        $standing = is_array($data['agency_standing'] ?? null) ? $data['agency_standing'] : null;
        $selling = match (true) {
            $seat->isAgent() => Standing::toAgent($standing),
            $seat->isOwner() => Standing::toOwner($standing),
            default => null,
        };
        $shortage = is_array($data['traffic_shortage'] ?? null)
            ? Standing::shortage($data['traffic_shortage'], match (true) {
                $seat->isAgent() => Standing::BUY_TRAFFIC,
                $seat->isOwner() => 'حجم ربات را از «نمایندگان» تنظیم کنید.',
                default => '',
            })
            : null;

        $total = ($selling === null ? 0 : 1) + ($shortage === null ? 0 : 1);
        foreach (array_keys(self::QUEUES) as $key) {
            $total += max(0, (int) ($attention[$key] ?? 0));
        }

        $view->line()->line('🔔 <b>نیازمند توجه</b>' . ($total > 0 ? ' · ' . Fmt::number($total) . ' مورد منتظر اقدام شماست' : ''));
        $buttons = [];
        if ($total === 0) {
            $view->line('✅ همه‌چیز مرتب است: ' . ($seat->shop()->isMain()
                ? 'پرداخت‌ها، سفارش‌ها، تیکت‌ها، نظرات و سرورها در وضعیت عادی هستند.'
                : 'پرداخت‌ها، سفارش‌ها، تیکت‌ها و نظرات در وضعیت عادی هستند.'));
        }
        foreach (array_filter([$selling, $shortage]) as $notice) {
            $view->line($notice);
        }
        if (($selling !== null || $shortage !== null) && $seat->isOwner()) {
            $buttons[] = Links::to($admin, '🤝 نمایندگان', 'ag');
        }
        if (($selling !== null || $shortage !== null) && $seat->isAgent()) {
            $buttons[] = Links::to($admin, '💼 حساب نمایندگی', self::ACCOUNT);
            $main = $this->bots->username(CurrentBot::main());
            if ($main !== '') {
                $buttons[] = Button::url('🛒 خرید حجم در ربات اصلی', 'https://t.me/' . rawurlencode($main));
            }
        }
        foreach (self::QUEUES as $key => [$label, $screen]) {
            $buttons[] = $this->count($admin, $view, (int) ($attention[$key] ?? 0), $label, $screen, $key);
        }

        $waiting = array_filter(self::CUSTOMERS, static fn(string $key): bool => (int) ($attention[$key] ?? 0) > 0, ARRAY_FILTER_USE_KEY);
        if ($waiting !== []) {
            $view->line()->line('⏳ <b>در انتظار مشتری</b>');
            foreach ($waiting as $key => [$label, $screen]) {
                $label = $key === 'expiring_soon' ? $label . ' (' . Fmt::number($expiringDays) . ' روز)' : $label;
                $buttons[] = $this->count($admin, $view, (int) ($attention[$key] ?? 0), $label, $screen, $key);
            }
        }

        return array_values(array_filter($buttons));
    }

    /**
     * One count's line — none at zero — and its button (`q`) to the list `$screen` as the count's tab shows it, where the
     * seat may open it.
     */
    private function count(Admin $admin, View $view, int $count, string $label, string $screen, string $queue): ?Button
    {
        if ($count <= 0) {
            return null;
        }
        $view->line('• ' . Fmt::e($label) . ': <b>' . Fmt::number($count) . '</b>');

        return $admin->opens($screen) ? Button::to($label . ' (' . Fmt::number($count) . ')', self::ID, 'q', $queue) : null;
    }

    /**
     * «وضعیت سیستم» (the owner's): the shop's bot and how it gets updates, the scheduled tasks, a newer release — each
     * as the panel's system card says it; what needs the panel's settings, a button there.
     *
     * @return list<Button>
     */
    private function system(Admin $admin, View $view): array
    {
        try {
            $system = (array) ($admin->api->get('/system')['system'] ?? []);
        } catch (ApiRefusal) {
            return [];
        }
        try {
            $update = (array) ($admin->api->get('/system/update')['update'] ?? []);
        } catch (ApiRefusal) {
            $update = [];
        }

        $bot = Json::part($system, 'bot');
        $main = (int) ($bot['id'] ?? 1) === 1;
        $username = is_string($bot['username'] ?? null) && $bot['username'] !== '' ? $bot['username'] : null;
        $mode = is_string($bot['mode'] ?? null) ? $bot['mode'] : 'offline';
        $configured = Json::on($bot, 'configured');
        $enabled = Json::on($bot, 'enabled');
        $needsFix = !$configured || $mode === 'offline';

        $php = is_string($system['php'] ?? null) ? $system['php'] : '';
        $view->line()->line('🖥 <b>وضعیت سیستم</b> · نسخه ' . Fmt::ltr(is_string($system['version'] ?? null) ? $system['version'] : null) . ' · ' . Fmt::ltr('PHP ' . $php));
        if (($system['php_safe'] ?? true) === false) {
            // The panel's banner over every page (apps/admin/php-banner.tsx), word for word.
            $view->line('🛡 <b>' . Fmt::ltr('PHP') . ' این هاست (' . Fmt::ltr($php) . ') حفره امنیتی جدی دارد.</b> آن را از تنظیمات هاست (مثلا «' . Fmt::ltr('Select PHP Version') . '» در ' . Fmt::ltr('cPanel') . ') به ' . Fmt::ltr('PHP ' . (is_string($system['php_required'] ?? null) ? $system['php_required'] : '')) . ' یا بالاتر ببرید؛ تا آن وقت به‌روزرسانی فروشگاه نصب نمی‌شود.');
        }
        $health = match (true) {
            !$configured => '🔴',
            $mode === 'offline' || !$enabled => '🟡',
            default => '🟢',
        };
        $state = match (true) {
            !$configured => 'وصل نشده',
            $mode !== 'offline' && !$enabled => 'غیرفعال از تنظیمات',
            default => self::BOT_MODE[$mode] ?? $mode,
        };
        $detail = match (true) {
            !$configured => $main ? 'توکن ربات تنظیم نشده است' : 'نماینده هنوز توکن رباتش را از ربات اصلی نفرستاده است',
            $mode === 'offline' => 'پیامی نمی‌رسد: ' . Fmt::ltr('Webhook') . ' ثبت نشده است',
            default => ($username === null ? 'توکن تنظیم شده · ' : '') . 'آخرین پیام: ' . Words::ago(is_string($bot['last_update_at'] ?? null) ? $bot['last_update_at'] : null),
        };
        $view->line("{$health} " . ($main ? 'ربات اصلی' : 'ربات این فروشگاه') . ($username === null ? '' : ' ' . Fmt::ltr('@' . $username)) . ': ' . Fmt::e($state) . ' — ' . $detail);

        $cron = Json::part($system, 'cron');
        $ran = is_string($cron['last_run_at'] ?? null) ? $cron['last_run_at'] : null;
        $view->line($ran !== null
            ? '🟢 کارهای زمان‌بندی‌شده: فعال — آخرین اجرا: ' . Words::ago($ran) . ' · ' . Fmt::number((int) ($cron['tasks'] ?? 0)) . ' وظیفه'
            : '🟡 کارهای زمان‌بندی‌شده: تنظیم نشده — هنوز اجرا نشده‌اند');
        $needsFix = $needsFix || $ran === null;

        $latest = is_array($update['latest'] ?? null) ? $update['latest'] : null;
        if (Json::on($update, 'available') && $latest !== null) {
            $view->line('🔵 نسخه تازه: آماده نصب — به‌روزرسانی به نسخه ' . Fmt::ltr(is_string($latest['version'] ?? null) ? $latest['version'] : null));
            $needsFix = true;
        }

        return $needsFix ? array_values(array_filter([Links::to($admin, '🧰 تنظیمات پنل', 'pp')])) : [];
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

    /** A figure of the panel's JSON (a count, or money as a decimal string). */
    private static function figure(mixed $value): string
    {
        return is_numeric($value) ? (string) $value : '0';
    }
}
