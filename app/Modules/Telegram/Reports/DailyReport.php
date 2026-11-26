<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports;

use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderPricing;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Providers\Models\Server;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Models\Review;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Models\TestAccount;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Ticket;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Users\Models\User;
use App\Support\LocalTime;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * «گزارش روزانه»: once a day, from the hour the shop set (ReportSettings::dailyHour()), the figures of its day before —
 * one whole calendar day of its clock, midnight to midnight even on a day the clock moves (the dashboard's chart, by
 * the zone's offset now, may put that day's hour around the change on the next) — queued to its report group's topic
 * (ShopReports::daily()). Sent once: the report's row carries the day (`ref` daily:<Y-m-d>), so a day with a report is
 * never read again, and one whose report could not be queued is read again at the next run. Nothing is read while the
 * shop has no group or the topic is off.
 *
 * Income is money that came in (Payment::moneyIn(), the dashboard's revenue). A sale is an order whose payment was
 * accepted that day — a receipt approved the next morning counts on that morning — and is still kept (Order::KEPT): a
 * refunded one is none, and neither is a service given free (`pricing` gift: a prize, a referral's gift, support's free
 * order). The main shop's report adds what each agent's bot sold its own customers; an agent's report says its own shop
 * alone, with its traffic left.
 */
final class DailyReport
{
    /** What `ref` a daily report carries, the day after it. */
    public const REF = 'daily:';

    /** How many plans, servers, payment methods and agents a report names at most. */
    public const PLANS_MOST = 5;
    public const SERVERS_MOST = 3;
    public const METHODS_MOST = 4;
    public const AGENTS_MOST = 10;

    /** The payment methods past METHODS_MOST, as one. */
    public const OTHER = 'سایر';

    /** The lines sales are said in, and the kinds of order each takes, in the report's order. */
    public const LINES = [
        'purchase' => [OrderType::Purchase],
        'renewal' => [OrderType::Renewal],
        'extras' => [OrderType::ExtraTraffic, OrderType::ExtraTime, OrderType::ExtraDevices],
        'topup' => [OrderType::WalletTopUp],
        'traffic' => [OrderType::Traffic],
    ];

    /** What a sale of no plan — a service the customer built, or one whose plan was deleted since — is called. */
    private const NO_PLAN = 'سرویس دلخواه یا پلن حذف‌شده';

    public function __construct(
        private readonly ReportGroupState $group,
        private readonly ReportSettings $settings,
        private readonly ShopReports $reports,
    ) {}

    /** Queue the current shop's report of its day before, when it is due and not queued yet; whether one was. */
    public function sendIfDue(): bool
    {
        if ($this->group->chatId() === null || !$this->settings->enabled(Topic::Daily)) {
            return false;
        }
        $now = Carbon::instance(LocalTime::of(now()));
        if ($now->hour < $this->settings->dailyHour()) {
            return false;
        }
        $day = $now->copy()->startOfDay()->subDay();
        $ref = self::REF . $day->format('Y-m-d');
        if (ReportMessage::query()->where('ref', $ref)->exists()) {
            return false;
        }

        $this->reports->daily($this->figures($day), $ref);

        return true;
    }

    /** The current shop's figures of the day `$day` falls on, on the shop's clock. */
    public function figures(Carbon $day): DailyFigures
    {
        $date = $day->copy()->setTimezone(LocalTime::zone())->startOfDay();
        $day = self::dayStart($date);
        $from = $day->copy()->utc();
        $to = self::dayStart($date->copy()->addDay())->utc();
        $main = CurrentBot::isMain();

        return new DailyFigures(
            $day,
            self::income($from, $to),
            self::income(self::dayStart($date->copy()->subDay())->utc(), $from),
            self::methods($from, $to),
            self::sales($from, $to),
            self::plans($from, $to),
            self::servers($from, $to),
            (int) self::sold($from, $to)->whereIn('orders.type', OrderType::FOR_THEMSELVES)->distinct()->count('orders.user_id'),
            User::query()->where('created_at', '>=', $from)->where('created_at', '<', $to)->count(),
            TestAccount::query()->where('created_at', '>=', $from)->where('created_at', '<', $to)->count(),
            Subscription::active()->where('is_test', false)->count(),
            self::waiting(),
            $main ? self::agents($from, $to) : null,
            $main ? null : CurrentBot::get()->trafficBalance(),
        );
    }

    /**
     * The first moment, on the shop's clock, of the calendar day `$date` falls on — worked out from the date alone, so a
     * day ends where the next begins: a midnight a DST change skips (Africa/Cairo) starts the day at the hour it jumps
     * to, one it repeats (America/Havana) at its first time. Moving a Carbon by a day keeps its offset, and would put
     * such an hour in two days or in none.
     */
    private static function dayStart(Carbon $date): Carbon
    {
        return Carbon::parse($date->format('Y-m-d') . ' 00:00:00', LocalTime::zone());
    }

    /**
     * What waits on support now, as the dashboard counts it: receipts to review, orders stuck, tickets open, reviews to
     * decide.
     *
     * @return array<string, int>
     */
    private static function waiting(): array
    {
        return [
            'payments_to_review' => Payment::query()->where('status', PaymentStatus::AwaitingReview->value)->count(),
            'stuck_orders' => Order::stuck()->count(),
            'open_tickets' => Ticket::query()->where('status', TicketStatus::Open->value)->count(),
            'pending_reviews' => Review::query()->where('status', ReviewStatus::Pending->value)->count(),
        ];
    }

    /** Money that came in between `$from` (inclusive) and `$to` (exclusive), UTC. */
    private static function income(Carbon $from, Carbon $to): string
    {
        return self::money(Payment::moneyIn()->where('paid_at', '>=', $from)->where('paid_at', '<', $to)->sum('amount'));
    }

    /**
     * That income by payment method, the largest first; past METHODS_MOST, the rest as one (OTHER).
     *
     * @return list<array{name: string, total: string}>
     */
    private static function methods(Carbon $from, Carbon $to): array
    {
        $rows = Payment::moneyIn()->where('paid_at', '>=', $from)->where('paid_at', '<', $to)
            ->toBase()->selectRaw('payment_method_id AS method, SUM(amount) AS total')->groupBy('payment_method_id')->orderByDesc('total')->get();
        $names = PaymentMethod::query()->whereIn('id', $rows->pluck('method')->all())->pluck('label', 'id')->all();

        $methods = [];
        $rest = '0';
        foreach ($rows as $n => $row) {
            if ($n < self::METHODS_MOST) {
                $methods[] = ['name' => (string) ($names[(int) $row->method] ?? '—'), 'total' => self::money($row->total)];
            } else {
                $rest = Money::add($rest, self::money($row->total));
            }
        }
        if (Money::isPositive($rest)) {
            $methods[] = ['name' => self::OTHER, 'total' => $rest];
        }

        return $methods;
    }

    /**
     * The orders sold, by line (LINES): how many, their amount and — an agent's traffic — their bytes; lines not sold
     * left out.
     *
     * @return array<string, array{count: int, amount: string, bytes: int}>
     */
    private static function sales(Carbon $from, Carbon $to): array
    {
        $query = self::sold($from, $to);
        $grammar = $query->getQuery()->getGrammar();
        [$type, $amount, $bytes] = [$grammar->wrap('orders.type'), $grammar->wrap('orders.amount'), $grammar->wrap('orders.traffic_bytes')];
        $byType = [];
        foreach ($query->toBase()->selectRaw("{$type} AS kind, COUNT(*) AS sold, COALESCE(SUM({$amount}), 0) AS total, COALESCE(SUM({$bytes}), 0) AS bytes")->groupBy('orders.type')->get() as $row) {
            $byType[(string) $row->kind] = $row;
        }

        $sales = [];
        foreach (self::LINES as $line => $types) {
            $count = 0;
            $total = '0';
            $sum = 0;
            foreach ($types as $kind) {
                $row = $byType[$kind->value] ?? null;
                if ($row !== null) {
                    $count += (int) $row->sold;
                    $total = Money::add($total, self::money($row->total));
                    $sum += (int) $row->bytes;
                }
            }
            if ($count > 0) {
                $sales[$line] = ['count' => $count, 'amount' => Money::normalize($total), 'bytes' => $sum];
            }
        }

        return $sales;
    }

    /**
     * Purchases and renewals by plan, the most sold first (then the most money), PLANS_MOST of them.
     *
     * @return list<array{name: string, count: int, amount: string}>
     */
    private static function plans(Carbon $from, Carbon $to): array
    {
        $query = self::sold($from, $to)->whereIn('orders.type', [OrderType::Purchase->value, OrderType::Renewal->value]);
        $grammar = $query->getQuery()->getGrammar();
        [$plan, $amount] = [$grammar->wrap('orders.plan_id'), $grammar->wrap('orders.amount')];
        $rows = $query->toBase()
            ->selectRaw("{$plan} AS plan, COUNT(*) AS sold, COALESCE(SUM({$amount}), 0) AS total")
            ->groupBy('orders.plan_id')->orderByDesc('sold')->orderByDesc('total')->limit(self::PLANS_MOST)->get();
        // A plan of the shop's own, whichever shop's it is now (an order keeps the plan it was sold on).
        $names = Plan::query()->withoutGlobalScope(CurrentBot::SCOPE)->whereIn('id', $rows->pluck('plan')->filter()->all())->pluck('name', 'id')->all();

        return array_values($rows->map(static fn(object $row): array => [
            'name' => $row->plan === null ? self::NO_PLAN : (string) ($names[(int) $row->plan] ?? self::NO_PLAN),
            'count' => (int) $row->sold,
            'amount' => self::money($row->total),
        ])->all());
    }

    /**
     * Purchases and renewals by server, the most sold first, SERVERS_MOST of them; orders on no server left out.
     *
     * @return list<array{name: string, count: int}>
     */
    private static function servers(Carbon $from, Carbon $to): array
    {
        $query = self::sold($from, $to)->whereIn('orders.type', [OrderType::Purchase->value, OrderType::Renewal->value])->whereNotNull('orders.server_id');
        $server = $query->getQuery()->getGrammar()->wrap('orders.server_id');
        $rows = $query->toBase()->selectRaw("{$server} AS server, COUNT(*) AS sold")->groupBy('orders.server_id')->orderByDesc('sold')->limit(self::SERVERS_MOST)->get();
        $names = Server::query()->whereIn('id', $rows->pluck('server')->all())->pluck('name', 'id')->all();

        return array_values($rows->map(static fn(object $row): array => [
            'name' => (string) ($names[(int) $row->server] ?? '—'),
            'count' => (int) $row->sold,
        ])->all());
    }

    /**
     * What each agent's bot sold its own customers — their purchases, renewals and extras —, the most money first: the
     * AGENTS_MOST first named, with them all and how many are left out. Null when no agent's bot sold anything.
     *
     * @return array{rows: list<array{bot: string, agent: string, count: int, amount: string}>, count: int, amount: string, more: int}|null
     */
    private static function agents(Carbon $from, Carbon $to): ?array
    {
        $shops = Bot::query()->whereKeyNot(Bot::MAIN)->pluck('id')->all();
        if ($shops === []) {
            return null;
        }
        $query = self::sold($from, $to, anyShop: true)->whereIn('payments.bot_id', $shops)->whereIn('orders.type', OrderType::FOR_THEMSELVES);
        $grammar = $query->getQuery()->getGrammar();
        [$bot, $amount] = [$grammar->wrap('payments.bot_id'), $grammar->wrap('orders.amount')];
        $rows = $query->toBase()->selectRaw("{$bot} AS shop, COUNT(*) AS sold, COALESCE(SUM({$amount}), 0) AS total")
            ->groupBy('payments.bot_id')->orderByDesc('total')->orderByDesc('sold')->get();
        if ($rows->isEmpty()) {
            return null;
        }

        $named = $rows->take(self::AGENTS_MOST);
        $bots = Bot::query()->with('agent')->whereIn('id', $named->pluck('shop')->all())->get()->keyBy('id');
        $count = 0;
        $total = '0';
        foreach ($rows as $row) {
            $count += (int) $row->sold;
            $total = Money::add($total, self::money($row->total));
        }

        return [
            'rows' => array_values($named->map(static function (object $row) use ($bots): array {
                $shop = $bots->get((int) $row->shop);

                return [
                    'bot' => $shop?->username !== null ? '@' . $shop->username : ($shop->title ?? "\u{200E}#" . (int) $row->shop),
                    'agent' => $shop?->agent?->name() ?? '—',
                    'count' => (int) $row->sold,
                    'amount' => self::money($row->total),
                ];
            })->all()),
            'count' => $count,
            'amount' => Money::normalize($total),
            'more' => max(0, $rows->count() - self::AGENTS_MOST),
        ];
    }

    /**
     * Payments accepted between `$from` (inclusive) and `$to` (exclusive), UTC, with the orders they paid that are still
     * kept: the day's sales — each order once, by the payment that paid it (Order::paidBy()) —, but a service given free
     * (`pricing` gift), which is none. The current shop's; every shop's with `$anyShop`.
     *
     * @return Builder<Payment>
     */
    private static function sold(Carbon $from, Carbon $to, bool $anyShop = false): Builder
    {
        return Payment::query()
            ->when($anyShop, static fn(Builder $query) => $query->withoutGlobalScope(CurrentBot::SCOPE))
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('payments.status', PaymentStatus::Paid->value)
            ->where('payments.paid_at', '>=', $from)
            ->where('payments.paid_at', '<', $to)
            // An online payment that came after its order closed went to the wallet (Payment::wentToWallet()): income, no sale.
            ->whereNull('payments.invoice->to_wallet')
            ->whereIn('orders.status', Order::KEPT)
            ->where('orders.pricing', '!=', OrderPricing::Gift->value);
    }

    /** A database aggregate of amounts (a string, a number or null) as Money's decimal string. */
    private static function money(mixed $value): string
    {
        return Money::normalize(is_numeric($value) ? (string) $value : '0');
    }
}
