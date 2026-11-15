<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Sales;

use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Services\Bots;
use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Support\Standing;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Screens;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «💼 حساب نمایندگی», the agent's own page (the agent panel's account page, GET /account): their bot and how it stands,
 * why it sells nothing when it does not (its token, its traffic, the terms of selling), the traffic left and where it
 * is bought, the level's price per GB, their wallet and credit with the shop, the terms of selling, and the traffic
 * ledger (GET /account/traffic) a page at a time. Read only, as the panel's page: traffic is bought — and a token handed
 * over — in the main bot, a button away.
 *
 * Shown as the account; action `l` (page: 1 the newest) the ledger.
 */
final class AgentAccountScreen extends BaseScreen
{
    public const ID = 'acc';

    /** Ledger lines a page, cut from the panel's pages. */
    private const LINES = 10;

    /** The panel's own page size (Core\Database\Page::PER_PAGE). */
    private const API_PAGE = 25;

    /** What a line of the traffic was (the panel's ledger words). */
    private const TYPES = ['purchase' => 'خرید حجم', 'sale' => 'فروش', 'renewal' => 'تمدید', 'extension' => 'افزایش حجم', 'refund' => 'بازگشت حجم', 'adjust' => 'اصلاح پشتیبانی'];

    /** The page in a paragraph (the panel's header). */
    private const INTRO = 'ربات شما با همین فروشگاه کار می‌کند: پلن‌ها، قیمت‌ها و مشتری‌هایش مال شماست و سرویس‌ها روی سرورهای فروشگاه ساخته می‌شوند. هر سرویسی که ربات می‌فروشد یا تمدید می‌کند، حجم پلنش را از حجم شما برمی‌دارد؛ حجمی هم که از صفحه اشتراک‌ها به سرویسی اضافه می‌کنید از همین حجم کم می‌شود.';

    public function __construct(private readonly Bots $bots) {}

    public function allows(AdminSeat $seat): bool
    {
        return $seat->isAgent();
    }

    public function show(Admin $admin, array $args): View
    {
        $answer = $admin->api->get('/account');
        $account = Json::part($answer, 'account');
        $bot = Json::part($account, 'bot');
        $standing = Json::part($account, 'standing');
        $shortage = Json::part($answer, 'traffic_shortage');

        $view = View::make('💼 حساب نمایندگی', 'مدیریت');
        $warnings = array_values(array_filter([
            // A bot this update came through has its token: the panel's «not connected» notices never apply here.
            Json::text($bot, 'problem') !== null ? '⚠️ ' . Fmt::e(Json::text($bot, 'problem')) : null,
            $shortage !== [] ? Standing::shortage($shortage, Fmt::e(Standing::BUY_TRAFFIC)) : null,
            Standing::toAgent($standing === [] ? null : $standing),
        ]));
        foreach ($warnings as $warning) {
            $view->line($warning);
        }
        if ($warnings !== []) {
            $view->line();
        }
        $this->figures($view, $account, $shortage === []);

        $view->line()->line('<b>🤖 ربات شما</b>');
        if ($bot === []) {
            $view->line(Fmt::NONE);
        } else {
            $username = Json::text($bot, 'username');
            $view->facts([
                'ربات' => ($username !== null ? Fmt::ltr('@' . $username) : 'بدون توکن') . ' · ' . self::status($bot),
                'نام' => Fmt::e(Json::text($bot, 'title') ?? Fmt::NONE),
                'اولین اتصال' => Json::text($bot, 'connected_at') !== null ? Fmt::date(Json::text($bot, 'connected_at')) : Fmt::NONE,
            ])->line('<i>برای عوض کردن توکن، توکن تازه را از ربات اصلی بفرستید.</i>');
        }

        $view->line()->line('<b>📋 شرایط فروش</b>');
        if ($standing !== []) {
            $view->facts(Standing::facts($standing));
        }
        $view->line('<i>پایان نمایندگی و کف خرید حجم ماهانه را پشتیبانی فروشگاه اصلی تعیین می‌کند. بعد از پایان نمایندگی، یا ماهی که خرید حجمش به کف نرسد، در ربات شما ' . Standing::SUSPENDED_SALES . ' تازه انجام نمی‌شود؛ سرویس‌های فعلی مشتری‌ها کار می‌کنند.</i>')
            ->note(Fmt::e(self::INTRO));

        $main = $this->bots->username(CurrentBot::main());
        $view->row(
            Button::to('📊 گردش حجم', self::ID, 'l', 1),
            $main !== '' ? Button::url('🛒 خرید حجم در ربات اصلی', 'https://t.me/' . rawurlencode($main)) : null,
        );

        return $view->row(Button::to('🔄 تازه‌سازی', self::ID))->back(Screens::HOME);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        return $action === 'l' ? $this->ledger($admin, self::page($args)) : $this->show($admin, $args);
    }

    /**
     * The traffic left, the price per GB of their level, their wallet (a debt below zero) and credit — and where traffic
     * is bought, under the traffic while it still sells (else the shortage's notice says it).
     *
     * @param array<string, mixed> $account
     */
    private function figures(View $view, array $account, bool $sells): void
    {
        $bot = Json::part($account, 'bot');
        $level = Json::part($account, 'level');
        $balance = Json::text($account, 'balance') ?? '0';
        $debt = str_starts_with($balance, '-');
        $credit = Json::text($account, 'credit_limit') ?? '0';
        $view->lines(
            '📦 حجم باقی‌مانده: <b>' . ($bot === [] ? Fmt::NONE : Fmt::bytes((int) ($bot['traffic_balance'] ?? 0))) . '</b>' . ($sells ? "\n<i>" . Fmt::e(Standing::BUY_TRAFFIC) . '</i>' : ''),
            '🏷 قیمت هر گیگابایت: ' . ($level === [] ? 'نمایندگی فعال نیست' : '<b>' . Fmt::money(Json::text($level, 'price_per_gb')) . '</b> · سطح «' . Fmt::e(Json::text($level, 'name')) . '»'),
            '👛 کیف پول شما در فروشگاه: <b>' . Fmt::money(ltrim($balance, '-')) . ($debt ? ' بدهی' : '') . '</b> · '
                . ((float) $credit > 0 ? 'اعتبار خرید: ' . Fmt::money($credit) . ($debt ? ' (در حال استفاده)' : '') : 'بدون اعتبار خرید'),
        );
    }

    /**
     * The bot in a word (the panel's agentBotStatus): switched off, waiting for its token, kept from running, running.
     *
     * @param array<string, mixed> $bot
     */
    private static function status(array $bot): string
    {
        return match (true) {
            ($bot['status'] ?? '') === 'disabled' => '⏸ غیرفعال',
            !Json::on($bot, 'connected') => '🟡 وصل نشده',
            Json::text($bot, 'problem') !== null => '🟡 مشکل دارد',
            default => '🟢 فعال',
        };
    }

    /**
     * «📊 گردش حجم»: page `$page` of the traffic's lines, the newest first — purchases, sales, renewals, extensions,
     * refunds and adjustments, each with the balance after it.
     *
     * @throws ApiRefusal
     */
    private function ledger(Admin $admin, int $page): View
    {
        $start = ($page - 1) * self::LINES;
        $apiPage = intdiv($start, self::API_PAGE) + 1;
        $answer = $admin->api->get('/account/traffic', ['page' => $apiPage]);
        $lines = Json::rows($answer, 'lines');
        $meta = Json::part($answer, 'meta');
        $offset = $start % self::API_PAGE;
        if ($offset + self::LINES > self::API_PAGE && $apiPage < (int) ($meta['last_page'] ?? 1)) {
            $lines = [...$lines, ...Json::rows($admin->api->get('/account/traffic', ['page' => $apiPage + 1]), 'lines')];
        }
        $lines = array_slice($lines, $offset, self::LINES);
        $total = (int) ($meta['total'] ?? count($lines));
        $pages = max(1, (int) ceil($total / self::LINES));
        if ($page > $pages) {
            return $this->ledger($admin, $pages);
        }

        $view = View::make('📊 گردش حجم', 'مدیریت', 'حساب نمایندگی')
            ->line('خرید حجم، فروش، تمدید و افزایش حجم سرویس‌ها، و بازگشت حجمی که به سرویس نرسید.')
            ->line(Fmt::number($total) . ' مورد' . ($pages > 1 ? ' · صفحه ' . Fmt::number($page) . ' از ' . Fmt::number($pages) : ''))
            ->line();
        if ($lines === []) {
            $view->line('هنوز گردشی ندارد. خرید حجم، فروش، تمدید و افزایش حجم سرویس‌های ربات این‌جا ثبت می‌شوند.');
        }
        foreach ($lines as $line) {
            $bytes = (int) ($line['bytes'] ?? 0);
            $description = Json::text($line, 'description');
            $reviewer = Json::text($line, 'reviewer');
            $view->line(($bytes > 0 ? '➕ ' : '➖ ') . Fmt::bytes(abs($bytes)) . ' · ' . Fmt::e(Sales::word(self::TYPES, $line['type'] ?? ''))
                // The shop's own notes name orders by number («فروش · سفارش #33»): each kept whole.
                . ($description !== null ? ' · ' . (preg_replace('/#\d+/', "\u{2068}\$0\u{2069}", Fmt::e(Fmt::cut($description, 60))) ?? '') : '')
                . "\n    " . Fmt::date(Json::text($line, 'created_at')) . ' · مانده ' . Fmt::bytes((int) ($line['balance_after'] ?? 0))
                . ($reviewer !== null ? ' · ' . Sales::reviewer($reviewer, Json::text($line, 'reviewer_name')) : ''));
        }
        $view->pager($page, $pages, self::ID, 'l');

        return $view->back(self::ID, Nav::SHOW);
    }
}
