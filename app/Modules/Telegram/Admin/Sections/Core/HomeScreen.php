<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Core;

use App\Modules\Auth\Services\SessionPresenter;
use App\Modules\Settings\Services\BotSettingsScreen;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\SeatKind;
use App\Modules\Telegram\Admin\Sections\Bot\SwitchesScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Screens;
use App\Modules\Telegram\Admin\Ui\Settings\BotGroupSource;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «🏠 مدیریت فروشگاه»: the management's home — whose shop, as what, and whether its bot is on for customers (GET
 * /bot/settings' `enabled`: 🟢, or 🔴 with what customers get; nothing said when the panel refuses it); today's figures
 * (the last day of the dashboard's week: what came in, the orders, the new customers); what waits on a human now, each
 * queue a button straight to its list filtered as the count counted it; the sections the seat may open, as the panel's
 * sidebar has them; «⚡️ کلیدهای اصلی» beside the bot's own switch («⏸ خاموش کردن ربات», asked first as its card asks
 * it; «▶️ روشن کردن ربات» at once — saved as «عمومی» saves it); «🔎 جستجو»; the owner's shop switcher («🏪»); and
 * «🔄 تازه‌سازی». The dashboard refused, what waits comes from the queues alone and today's card is left out.
 *
 * Actions: `q` (queue: its list, filtered), `bot` (1|0: the bot on or off; `y` once asked).
 */
final class HomeScreen extends BaseScreen
{
    /**
     * What waits on a human, in the order said: the dashboard's key => [label, list screen, filter, value]. A filter of
     * null opens the list as it is.
     */
    public const QUEUES = [
        'payments_to_review' => ['🧾 رسیدها', 'py', 'status', 'awaiting_review'],
        'stuck_orders' => ['🧯 سفارش گیرکرده', 'or', 'status', 'stuck'],
        'open_tickets' => [Icons::SUPPORT . ' تیکت‌ها', 'tk', 'status', 'open'],
        'pending_reviews' => [Icons::REVIEWS . ' نظرات', 'rv', 'status', 'pending'],
        'held_payments' => ['✋ پرداخت نگه‌داشته', 'py', 'status', 'held'],
        'servers_with_errors' => [Icons::SERVERS . ' سرور با خطا', 'sv', null, null],
    ];

    /** The dashboard's range whose last day is today (DashboardStats::RANGES' shortest). */
    private const WEEK = 7;

    /** The bot's own switch on the home, saying the state a tap sets. */
    public const TURN_OFF = '⏸ خاموش کردن ربات';
    public const TURN_ON = '▶️ روشن کردن ربات';

    /** The bot's own switch, as «عمومی» (`general`) has it. */
    private const ENABLED = 'enabled';

    public function __construct(
        private readonly Screens $screens,
        private readonly SessionPresenter $shops,
        private readonly BotSettingsScreen $settings,
    ) {}

    public function show(Admin $admin, array $args): View
    {
        $seat = $admin->seat;
        $shop = $this->shops->shop($seat->shop());
        $role = match (true) {
            $seat->isAdmin() => Icons::BOT_ADMINS . ' مدیر ربات',
            $seat->kind === SeatKind::Owner => Icons::OWNER . ' مالک',
            default => Icons::AGENTS . ' نماینده',
        };
        $view = View::make(Icons::HOME . ' مدیریت فروشگاه')
            ->line(Icons::SHOP_SWITCH . ' ' . Fmt::e((string) $shop['name']) . ' · ' . $role);
        $enabled = self::enabled($admin);
        if ($enabled !== null) {
            $view->line($enabled ? '🟢 ربات روشن است' : '🔴 <b>ربات خاموش است</b> · مشتری‌ها فقط پیام «ربات فعلا غیرفعال است» را می‌گیرند.');
        }

        $entries = Screens::homeEntries();
        [$today, $waiting] = $this->figures($admin, $entries);
        if ($today !== null) {
            $view->section('📅 امروز')->card([
                'درآمد' => Fmt::money(Json::text($today, 'revenue') ?? '0'),
                'سفارش‌ها' => Fmt::number((int) ($today['orders'] ?? 0)),
                'کاربران تازه' => Fmt::number((int) ($today['users'] ?? 0)),
            ]);
        }

        $queues = [];
        $total = 0;
        foreach (self::QUEUES as $key => [$label, $screen]) {
            $count = max(0, (int) ($waiting[$key] ?? 0));
            if ($count > 0 && $admin->opens($screen)) {
                $total += $count;
                $queues[] = Button::to($label . Fmt::badge($count), Screens::HOME, 'q', $key);
            }
        }
        $view->line()->line($total === 0 ? Words::NOTHING_WAITING : '<b>' . Words::WAITING . '</b> · ' . Fmt::number($total) . ' کار');

        $view->tiles(...$queues)
            ->grid(2, ...$this->screens->buttons($seat, $entries))
            ->row(
                $admin->opens(SwitchesScreen::ID) ? Button::to(SwitchesScreen::TITLE, SwitchesScreen::ID, Nav::SHOW, 'from.' . Screens::HOME) : null,
                match ($enabled) {
                    true => Button::to(self::TURN_OFF, Screens::HOME, 'bot', 0)->danger(),
                    false => Button::to(self::TURN_ON, Screens::HOME, 'bot', 1)->success(),
                    null => null,
                },
            )
            ->row(Button::to(Words::SEARCH, Screens::SEARCH));
        if ($seat->isOwner()) {
            $view->row(Button::to(Icons::SHOP_SWITCH . ' تعویض فروشگاه', 'shop'));
        }

        return $view->refresh(Screens::HOME);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        if ($action === 'bot' && in_array($args[0] ?? null, ['0', '1'], true)) {
            return $this->turn($admin, $args[0] === '1', ($args[1] ?? '') === 'y');
        }
        $queue = self::QUEUES[$args[0] ?? ''] ?? null;
        if ($action === 'q' && $queue !== null) {
            [, $screen, $filter, $value] = $queue;
            // The list as the count counted it: a search or another narrowing left on it would hide some of them.
            $admin->forget($screen);
            if ($filter !== null && $value !== null) {
                $admin->remember($screen, ['f' => [$filter => $value]]);
            }

            return $admin->open($screen);
        }

        return $this->show($admin, $args);
    }

    /**
     * The bot set on or off for customers, as «عمومی» saves it (the `general` group whole, the one field changed), and the
     * home again saying so: off asked first in the card's words (SwitchesScreen::asks()) — customers held back —, on at
     * once; a state it already is in (a second tap, an older window) is said, nothing saved.
     *
     * @throws ApiRefusal
     */
    private function turn(Admin $admin, bool $on, bool $asked): View
    {
        $values = BotGroupSource::settings($admin);
        if (Json::on($values, self::ENABLED) === $on) {
            $admin->notice('ربات همین حالا ' . ($on ? 'روشن' : 'خاموش') . ' است');

            return $this->show($admin, []);
        }
        $ask = $asked ? null : SwitchesScreen::asks($admin, self::ENABLED, $on);
        if ($ask !== null) {
            return $admin->confirm($ask['question'], Button::to($ask['yes'], Screens::HOME, 'bot', (int) $on, 'y'), Button::to(Words::CANCEL, Screens::HOME), $ask['detail'], danger: !$on);
        }
        $values = (new BotGroupSource('general', $this->settings))->save($admin, $values, self::ENABLED, $on);
        $admin->notice(Json::on($values, self::ENABLED) ? '✅ ربات روشن شد' : '⏸ ربات خاموش شد');

        return $this->show($admin, []);
    }

    /** Whether the shop's bot is on for customers (GET /bot/settings); null when the panel would not say. */
    private static function enabled(Admin $admin): ?bool
    {
        try {
            $values = BotGroupSource::settings($admin);
        } catch (ApiRefusal) {
            return null;
        }

        return array_key_exists(self::ENABLED, $values) ? Json::on($values, self::ENABLED) : null;
    }

    /**
     * Today's figures (the dashboard's last day: revenue, orders, users) and what waits (its `attention`), read once;
     * the dashboard refused, no figures and what waits from the queues alone.
     *
     * @param list<array{0: string, 1: string, 2?: string}> $entries
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>}
     */
    private function figures(Admin $admin, array $entries): array
    {
        try {
            $dashboard = $admin->api->get('/dashboard', ['range' => self::WEEK]);
        } catch (ApiRefusal) {
            return [null, $this->screens->queues($admin, $entries)];
        }
        $series = Json::rows($dashboard, 'series');
        $today = $series === [] ? null : $series[array_key_last($series)];

        return [$today, Json::part($dashboard, 'attention')];
    }
}
