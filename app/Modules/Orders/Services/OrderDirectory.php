<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Core\Database\Page;
use App\Core\Database\PageRequest;
use App\Modules\Auth\CurrentPrincipal;
use App\Modules\Auth\PrincipalKind;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\Payment;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\UserDirectory;
use Illuminate\Database\Eloquent\Builder;

/**
 * The orders screen («سفارش‌ها», both panels): every order — purchases, renewals, wallet top-ups, agents' traffic — with
 * its customer, what it was for, its payments and how far it got, filtered by status, type and the days it was placed,
 * and searched by number, customer, plan or service; with what its modal may do now (`actions`, OrderActions' rules).
 */
final class OrderDirectory
{
    /** What a row shows, loaded with it. */
    public const RELATIONS = ['user', 'plan', 'server', 'subscription.server', 'payments.method'];

    /** The tab that is not a status: orders paid and not delivered — the queue that waits on support (Order::stuck()). */
    public const STUCK = 'stuck';

    public function __construct(private readonly OrderActions $actions) {}

    /**
     * One status (a tab) or STUCK, one type or all, one customer's (`user`, their page links here), placed on the days
     * asked (`from`, `to`), searched — newest first, or by amount (`sort`) —; with the stuck ones counted (the dashboard
     * and the sidebar link there) and what the list sold: how many of its orders are sales (Order::SOLD) and their amount.
     */
    public function search(PageRequest $list): Page
    {
        $query = $list->text('status') === self::STUCK ? Order::stuck() : Order::query();
        $status = $list->enum('status', OrderStatus::class);
        if ($status !== null) {
            $query->where('status', $status->value);
        }
        $type = $list->enum('type', OrderType::class);
        if ($type !== null) {
            $query->where('type', $type->value);
        }
        $user = $list->id('user');
        if ($user !== null) {
            $query->where('user_id', $user);
        }
        $list->dates()->apply($query, 'created_at');
        $list->search($query, self::applySearch(...));
        [$sold, $amount] = Page::tally((clone $query)->whereIn('status', Order::SOLD), 'amount');

        return Page::fetch($query->with(self::RELATIONS), $list, $this->present(...), $list->sort(['created' => 'id', 'amount' => 'amount'], 'created'))
            ->with(['stuck' => Order::stuck()->count(), 'sold' => $sold, 'sold_amount' => $amount]);
    }

    /** @return array<string, mixed> One row of the screen. */
    public function present(Order $order): array
    {
        $subscription = $order->subscription;
        // A renewal names no server of its own: it is where its service is.
        $server = $order->server ?? $subscription?->server;

        return [
            'id' => $order->id,
            'type' => $order->type->value,
            'status' => $order->status->value,
            'amount' => $order->amount,
            'user' => UserDirectory::presentRef($order->user),
            'plan' => $order->plan === null ? null : ['id' => $order->plan->id, 'name' => $order->plan->name],
            'server' => $server === null ? null : ['id' => $server->id, 'name' => $server->name],
            'subscription' => $subscription === null ? null : ['id' => $subscription->id, 'name' => $subscription->remote_name, 'status' => $subscription->status->value],
            // Why it failed, or why it was cancelled — as whoever reads the screen may read it.
            'notes' => self::notes($order),
            'payments' => $order->payments->map(static fn(Payment $payment): array => [
                'id' => $payment->id,
                'status' => $payment->status->value,
                'amount' => $payment->amount,
                'gateway' => $payment->method->driver,
                'method' => $payment->method->label,
                'paid_at' => $payment->paid_at?->toIso8601String(),
                'created_at' => $payment->created_at->toIso8601String(),
            ])->values()->all(),
            'paid_at' => $order->paidAt()?->toIso8601String(),
            'fulfilled_at' => $order->fulfilled_at?->toIso8601String(),
            'created_at' => $order->created_at->toIso8601String(),
            'actions' => $this->actions->allowed($order),
        ];
    }

    /**
     * Why the order failed or was cancelled, as whoever reads the screen may read it (CurrentPrincipal): the owner, who
     * runs the servers, the diagnosis of a panel that failed its delivery — its address, its answer —; anyone else — an
     * agent, the shop's admins on its website — the words kept for everyone (a panel's failure in a word).
     */
    public static function notes(Order $order): ?string
    {
        return CurrentPrincipal::get()?->kind === PrincipalKind::Owner ? $order->diagnosis ?? $order->notes : $order->notes;
    }

    /**
     * By a bare order number, the customer — name, handle, Telegram id, the way every list searches its customers —, the
     * plan's name, or the service's name on the panel. («#12» is the order numbered 12 alone: PageRequest::search().) Each
     * is found once (Page::matches()), so the orders are read through their indexes on those columns.
     *
     * @param Builder<Order> $query
     */
    private static function applySearch(Builder $query, string $term, ?int $number): void
    {
        $users = User::idsMatching($term);
        $plans = Page::matches(Page::whereContains(Plan::query()->select('id'), 'name', $term));
        $subscriptions = Page::matches(Page::whereContains(Subscription::query()->select('id'), 'remote_name', $term));

        $query->where(static function (Builder $q) use ($users, $plans, $subscriptions, $number): void {
            $q->whereIn('user_id', $users)->orWhereIn('plan_id', $plans)->orWhereIn('subscription_id', $subscriptions);
            if ($number !== null) {
                $q->orWhere('id', $number);
            }
        });
    }
}
