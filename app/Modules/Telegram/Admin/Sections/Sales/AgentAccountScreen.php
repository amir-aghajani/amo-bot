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
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Screens;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;
use App\Modules\Telegram\Messages;
use App\Support\Money;

/**
 * «💼 حساب نمایندگی», the agent's own page (the agent panel's account page, GET /account): why their bot sells nothing
 * when it does not (its token, its traffic, the terms of selling) said first; then one card of what they have with the
 * shop — the traffic left, the level and its price per GB, their wallet and credit —, their bot and how it stands, the
 * terms of selling with their latest renewal request (waiting, or how the owner decided it), and how it all works folded
 * away. Read only but for «🔄 درخواست تمدید نمایندگی» (POST /account/renewal, while `actions.request_renewal`), as the
 * panel's page: traffic is bought — and a token handed over — in the main bot, a button away; the traffic ledger (GET
 * /account/traffic) a page at a time.
 *
 * Shown as the account; action `l` (page: 1 the newest) the ledger, `rn` the renewal asked for.
 */
final class AgentAccountScreen extends BaseScreen
{
    public const ID = 'acc';

    /** The page's title, as the home's button says it. */
    public const TITLE = Icons::AGENT_ACCOUNT . ' حساب نمایندگی';

    /** The ledger's title. */
    private const LEDGER = '📒 گردش حجم';

    /** «درخواست تمدید نمایندگی»: its button, and its action. */
    private const RENEW_LABEL = '🔄 درخواست تمدید نمایندگی';
    private const RENEW = 'rn';

    /** Ledger lines a page, cut from the panel's pages. */
    private const LINES = 10;

    /** The panel's own page size (Core\Database\Page::PER_PAGE). */
    private const API_PAGE = 25;

    /** What a line of the traffic was (the panel's ledger words). */
    private const TYPES = ['purchase' => 'خرید حجم', 'sale' => 'فروش', 'renewal' => 'تمدید', 'extension' => 'افزایش حجم', 'refund' => 'بازگشت حجم', 'adjust' => 'اصلاح پشتیبانی'];

    /** How it works (the panel's header). */
    private const INTRO = 'ربات شما با همین فروشگاه کار می‌کند: پلن‌ها، قیمت‌ها و مشتری‌هایش مال شماست و سرویس‌ها روی سرورهای فروشگاه ساخته می‌شوند. هر سرویسی که ربات می‌فروشد یا تمدید می‌کند، حجم پلنش را از حجم شما برمی‌دارد؛ حجمی هم که از صفحه اشتراک‌ها به سرویسی اضافه می‌کنید از همین حجم کم می‌شود.';

    public function __construct(private readonly Bots $bots) {}

    public function allows(AdminSeat $seat): bool
    {
        return $seat->isAgent();
    }

    public function show(Admin $admin, array $args): View
    {
        return $this->view($admin->api->get('/account'));
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        return match ($action) {
            'l' => $this->ledger($admin, self::page($args)),
            self::RENEW => $this->renew($admin),
            default => $this->show($admin, $args),
        };
    }

    /**
     * The account as GET /account answers it (POST /account/renewal answers the same).
     *
     * @param array<string, mixed> $answer
     */
    private function view(array $answer): View
    {
        $account = Json::part($answer, 'account');
        $bot = Json::part($account, 'bot');
        $standing = Json::part($account, 'standing');
        $shortage = Json::part($answer, 'traffic_shortage');
        $level = Json::part($account, 'level');
        $balance = Json::text($account, 'balance') ?? '0';
        $credit = Json::text($account, 'credit_limit') ?? '0';

        $view = View::make(self::TITLE, Words::ROOT);
        // A bot this update came through has its token: the panel's «not connected» notices never apply here.
        foreach (array_filter([
            Json::text($bot, 'problem') !== null ? '⚠️ ' . Fmt::e(Json::text($bot, 'problem')) : null,
            $shortage !== [] ? Standing::shortage($shortage, Fmt::e(Standing::BUY_TRAFFIC)) : null,
            Standing::toAgent($standing === [] ? null : $standing, (Json::part($account, 'renewal')['status'] ?? null) === 'pending'),
        ]) as $warning) {
            $view->line($warning)->line();
        }

        $view->card([
            'حجم باقی‌مانده' => $bot === [] ? Fmt::NONE : Fmt::bytes((int) ($bot['traffic_balance'] ?? 0)),
            'سطح نمایندگی' => $level === [] ? 'نمایندگی فعال نیست' : Fmt::e(Json::text($level, 'name')),
            'قیمت هر گیگابایت' => $level === [] ? null : Fmt::money(Json::text($level, 'price_per_gb')),
            'کیف پول شما در فروشگاه' => Messages::balance($balance),
            // In debt: within the credit, at its end (nothing left to spend), or past it (support's debit by hand).
            'اعتبار خرید' => (float) $credit > 0 ? Fmt::money($credit) . match (Messages::isDebt($balance) ? Money::compare(Money::add($balance, $credit), 0) : null) {
                null => '',
                -1 => ' (تمام شده؛ بدهی از اعتبار گذشته است)',
                0 => ' (تمام شده)',
                default => ' (در حال استفاده)',
            } : 'بدون اعتبار خرید',
        ]);

        $view->section(Icons::BOT . ' ربات شما');
        if ($bot === []) {
            $view->line(Fmt::NONE);
        } else {
            $username = Json::text($bot, 'username');
            $view->card([
                'ربات' => ($username !== null ? Fmt::ltr('@' . $username) : 'بدون توکن') . ' · ' . self::status($bot),
                'نام' => Fmt::e(Json::text($bot, 'title') ?? Fmt::NONE),
                'اولین اتصال' => Json::text($bot, 'connected_at') !== null ? Fmt::date(Json::text($bot, 'connected_at')) : Fmt::NONE,
            ]);
        }
        // Their latest renewal request right under the end it would move on.
        $facts = $standing !== [] ? Standing::facts($standing) : [];
        $terms = array_slice($facts, 0, 1, true) + self::renewal(Json::part($account, 'renewal')) + $facts;
        if ($terms !== []) {
            $view->section('📋 شرایط فروش')->card($terms);
        }

        $main = $this->bots->username(CurrentBot::main());
        $view->row(
            $main !== '' ? Button::url('➕ خرید حجم در ربات اصلی', 'https://t.me/' . rawurlencode($main)) : null,
            Button::to(self::LEDGER, self::ID, 'l', 1),
        );
        if (Json::on(Json::part($account, 'actions'), 'request_renewal')) {
            $view->row(Button::to(self::RENEW_LABEL, self::ID, self::RENEW)->primary());
        }

        return $view->help(implode("\n\n", array_filter([
            Fmt::e(self::INTRO),
            // Where traffic is bought, said once: in the shortage's notice while it sells nothing.
            $shortage === [] ? Fmt::e(Standing::BUY_TRAFFIC) . '.' : null,
            'برای عوض کردن توکن، توکن تازه را از ربات اصلی بفرستید.',
            'پایان نمایندگی و کف خرید حجم ماهانه را پشتیبانی فروشگاه اصلی تعیین می‌کند. بعد از پایان نمایندگی، یا ماهی که خرید حجمش به کف نرسد، در ربات شما ' . Standing::SUSPENDED_SALES . ' تازه انجام نمی‌شود؛ سرویس‌های فعلی مشتری‌ها کار می‌کنند.',
            'وقتی نمایندگی تاریخ پایان دارد، با «' . self::RENEW_LABEL . '» تمدید آن را از فروشگاه اصلی بخواهید؛ بعد از بررسی، نتیجه در ربات اصلی به شما اعلام می‌شود و اگر تایید شود، پایان تازه همین‌جا دیده می‌شود.',
        ])))->back(Screens::HOME)->refresh(self::ID);
    }

    /**
     * «🔄 درخواست تمدید نمایندگی»: filed for the main shop's owner to decide, then the account with it waiting. Refused
     * (422: one waits already — two taps, a window from before —, or the agency has no end now), why — over the account
     * as it stands, never the window left with the button.
     *
     * @throws ApiRefusal
     */
    private function renew(Admin $admin): View
    {
        try {
            $answer = $admin->api->post('/account/renewal');
        } catch (ApiRefusal $refusal) {
            if ($refusal->status !== 422) {
                throw $refusal;
            }
            $admin->alert($refusal->lines());

            return $this->show($admin, []);
        }
        $admin->notice('✅ درخواست تمدید ثبت شد؛ نتیجه در ربات اصلی به شما اعلام می‌شود');

        return $this->view($answer);
    }

    /**
     * Their latest renewal request as facts of the terms: waiting since when, or how the owner decided it — approved with
     * the end it gave (none at all, «بدون پایان»), or rejected with why when they said.
     *
     * @param array<string, mixed> $renewal
     * @return array<string, string|null>
     */
    private static function renewal(array $renewal): array
    {
        $decided = Fmt::date(Json::text($renewal, 'decided_at'));
        $end = Json::text($renewal, 'extended_to');
        $reason = Json::text($renewal, 'reason');

        return match ($renewal['status'] ?? null) {
            'pending' => ['درخواست تمدید' => '🟡 در انتظار بررسی · از ' . Fmt::date(Json::text($renewal, 'created_at'))],
            'approved' => ['درخواست تمدید' => '✅ تایید شد · ' . $decided, 'پایان تازه' => $end === null ? 'بدون پایان' : Fmt::date($end)],
            'rejected' => ['درخواست تمدید' => '🔴 رد شد · ' . $decided, 'دلیل رد' => $reason === null ? null : Fmt::e($reason)],
            default => [],
        };
    }

    /**
     * The bot in a word (the panel's agentBotStatus): switched off, waiting for its token, kept from running, running.
     *
     * @param array<string, mixed> $bot
     */
    private static function status(array $bot): string
    {
        return match (true) {
            ($bot['status'] ?? '') === 'disabled' => Words::INACTIVE,
            !Json::on($bot, 'connected') => '🟡 وصل نشده',
            Json::text($bot, 'problem') !== null => '🟡 مشکل دارد',
            default => Words::ACTIVE,
        };
    }

    /**
     * «📒 گردش حجم»: page `$page` of the traffic's lines, the newest first — purchases, sales, renewals, extensions,
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

        $view = View::make(self::LEDGER, Words::ROOT, Fmt::bare(self::TITLE))->total($total, 'تراکنش حجم');
        if ($lines === []) {
            $view->line()->line('هنوز گردشی ندارد. خرید حجم، فروش، تمدید و افزایش حجم سرویس‌های ربات این‌جا ثبت می‌شوند.');
        }
        foreach ($lines as $line) {
            $bytes = (int) ($line['bytes'] ?? 0);
            $description = Json::text($line, 'description');
            $reviewer = Json::text($line, 'reviewer');
            $view->line(($bytes > 0 ? '➕ ' : '➖ ') . Fmt::bytes(abs($bytes)) . ' · ' . Fmt::e(Sales::word(self::TYPES, $line['type'] ?? ''))
                // The shop's own notes name orders by number («فروش · سفارش #33»): each kept whole.
                . ($description !== null ? ' · ' . (preg_replace('/#\d+/', "\u{2068}\$0\u{2069}", Fmt::e(Fmt::cut($description, 60))) ?? '') : ''))
                ->line('<i>' . Fmt::date(Json::text($line, 'created_at')) . ' · مانده ' . Fmt::bytes((int) ($line['balance_after'] ?? 0))
                . ($reviewer !== null ? ' · ' . Sales::reviewer($reviewer, Json::text($line, 'reviewer_name')) : '') . '</i>');
        }
        $view->pager($page, $pages, self::ID, 'l');

        return $view->help('خرید حجم، فروش، تمدید و افزایش حجم سرویس‌ها، و بازگشت حجمی که به سرویس نرسید؛ تازه‌ترین اول.')
            ->back(self::ID)->refresh(self::ID, 'l', $page);
    }
}
