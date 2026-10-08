<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Core\Database\DatabaseManager;
use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Services\ServerSelector;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\Payment;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\SubscriptionDirectory;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\UserDirectory;
use App\Support\LocalTime;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The numbers behind the shop's dashboard (both panels): the period's figures against the one before it, a point per
 * day for the chart, the queues that need a human — the services ending soon within the one window the subscriptions
 * screen uses too (SubscriptionDirectory::EXPIRING_DAYS) — and, in an agent's shop, traffic too short to sell anything
 * (ServerSelector::shortage()), and the latest orders and customers. Revenue is money that came in
 * (Payment::moneyIn()), never wallet spending. A figure and the one of the period before are one query.
 */
final class DashboardStats
{
    public const RANGES = [7, 30, 90];
    public const DEFAULT_RANGE = 30;
    private const RECENT_LIMIT = 8;

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ServerSelector $selector,
    ) {}

    /**
     * Everything the screen shows for a range of `$days` (one of RANGES).
     *
     * @return array<string, mixed>
     */
    public function summary(int $days): array
    {
        // The days are the shop's (its zone's midnights), the moments asked of the database UTC, as it keeps them.
        $now = now()->setTimezone(LocalTime::zone());
        $from = $now->copy()->subDays($days - 1)->startOfDay();
        $period = [$from->copy()->utc(), $now->copy()->utc()];
        $previous = [$from->copy()->subDays($days)->utc(), $from->copy()->subSecond()->utc()];

        return [
            'range' => ['days' => $days, 'from' => $from->toDateString(), 'to' => $now->toDateString()],
            'generated_at' => $now->toIso8601String(),
            'kpis' => $this->kpis($period, $previous),
            'series' => $this->series($from, $now),
            'attention' => $this->attention(),
            'traffic_shortage' => $this->selector->shortage(),
            'expiring_days' => SubscriptionDirectory::EXPIRING_DAYS,
            'recent_orders' => $this->recentOrders(),
            'recent_users' => $this->recentUsers(),
        ];
    }

    /**
     * @param array{Carbon, Carbon} $period
     * @param array{Carbon, Carbon} $previous
     * @return array<string, mixed>
     */
    private function kpis(array $period, array $previous): array
    {
        $revenue = self::compared(Payment::moneyIn(), 'paid_at', 'amount', $period, $previous);
        $orders = self::compared(Order::query(), 'created_at', null, $period, $previous);
        $users = self::compared(User::query(), 'created_at', null, $period, $previous);

        return [
            'revenue' => ['value' => self::money($revenue['value']), 'previous' => self::money($revenue['previous'])],
            'orders' => ['value' => (int) $orders['value'], 'previous' => (int) $orders['previous']],
            'new_users' => ['value' => (int) $users['value'], 'previous' => (int) $users['previous']],
            'active_subscriptions' => Subscription::active()->count(),
            'users_total' => User::query()->count(),
        ];
    }

    /**
     * A figure of the period and of the one before it, in one query: the rows counted — or their `$sum` added up — by
     * when `$column` says they happened.
     *
     * @template TModel of Model
     * @param Builder<TModel> $query
     * @param array{Carbon, Carbon} $period
     * @param array{Carbon, Carbon} $previous
     * @return array{value: mixed, previous: mixed}
     */
    private static function compared(Builder $query, string $column, ?string $sum, array $period, array $previous): array
    {
        $grammar = $query->getQuery()->getGrammar();
        $at = $grammar->wrap($query->qualifyColumn($column));
        $measure = $sum === null ? '1' : $grammar->wrap($query->qualifyColumn($sum));
        $within = "COALESCE(SUM(CASE WHEN {$at} BETWEEN ? AND ? THEN {$measure} ELSE 0 END), 0)";

        $row = $query->toBase()
            ->whereBetween($query->qualifyColumn($column), [$previous[0], $period[1]])
            ->selectRaw("{$within} AS this_period, {$within} AS last_period", [...$period, ...$previous])
            ->first();

        return ['value' => $row->this_period ?? 0, 'previous' => $row->last_period ?? 0];
    }

    /**
     * One row per day for the whole range, zero-filled.
     *
     * @return list<array{date: string, revenue: string, orders: int, users: int}>
     */
    private function series(Carbon $from, Carbon $to): array
    {
        $between = [$from->copy()->utc(), $to->copy()->utc()];
        $revenue = $this->perDay(Payment::moneyIn()->whereBetween('paid_at', $between), 'paid_at', 'SUM(amount)');
        $orders = $this->perDay(Order::query()->whereBetween('created_at', $between), 'created_at', 'COUNT(*)');
        $users = $this->perDay(User::query()->whereBetween('created_at', $between), 'created_at', 'COUNT(*)');

        $rows = [];
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $key = $day->toDateString();
            $rows[] = [
                'date' => $key,
                'revenue' => self::money($revenue[$key] ?? 0),
                'orders' => (int) ($orders[$key] ?? 0),
                'users' => (int) ($users[$key] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * @template TModel of Model
     * @param Builder<TModel> $query
     * @return array<string, mixed> The aggregate by the shop's day
     */
    private function perDay(Builder $query, string $column, string $aggregate): array
    {
        // The shop's day of a UTC moment: shifted by the zone's offset now (a zone with daylight saving may put a
        // row of the hour around its change on the neighbouring day — a chart can live with that).
        $day = $this->database->driver()->localDate($query->getQuery()->getGrammar()->wrap($query->qualifyColumn($column)), LocalTime::offset());

        $result = [];
        foreach ($query->toBase()->selectRaw("{$day} AS day, {$aggregate} AS total")->groupBy('day')->get() as $row) {
            $result[(string) $row->day] = $row->total;
        }

        return $result;
    }

    /** @return array<string, int> The shop's queues (Queues — the receipts to review, the orders stuck), then the rest. */
    private function attention(): array
    {
        return Queues::counts() + [
            'pending_orders' => Order::query()->where('status', OrderStatus::Pending->value)->count(),
            // The servers are the shop's own: an agent's shop is not told about them.
            'servers_with_errors' => CurrentBot::isMain() ? Server::query()->whereNotNull('last_error')->count() : 0,
            'expiring_soon' => Subscription::expiringWithin(SubscriptionDirectory::EXPIRING_DAYS)->count(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function recentOrders(): array
    {
        return Order::query()
            ->with(['user', 'plan'])
            ->latest('id')
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->map(static fn(Order $order): array => [
                'id' => $order->id,
                'type' => $order->type->value,
                'status' => $order->status->value,
                'amount' => $order->amount,
                'plan' => $order->plan?->name,
                'user' => UserDirectory::presentRef($order->user),
                'created_at' => $order->created_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function recentUsers(): array
    {
        return User::query()
            ->addSelect(User::balanceColumn())
            ->latest('id')
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->map(static fn(User $user): array => UserDirectory::presentRef($user) + [
                'balance' => $user->balance(),
                'created_at' => $user->created_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /** An aggregate of amounts as the database answers it (a string, a number, nothing) in Money's terms. */
    private static function money(mixed $value): string
    {
        return Money::normalize(is_numeric($value) ? (string) $value : '0');
    }
}
