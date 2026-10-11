<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Core\Database\Page;
use App\Core\Database\PageRequest;
use App\Modules\Auth\CurrentPrincipal;
use App\Modules\Auth\PrincipalKind;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Discounts\DTO\DiscountLine;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\Payment;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\UserDirectory;
use Illuminate\Database\Eloquent\Builder;

/**
 * The orders screen («سفارش‌ها»): orders filtered by status (or the STUCK queue), type, customer and days, searched by
 * number, customer, plan or service, with the operations allowed now (`actions`, OrderActions::allowed()).
 */
final class OrderDirectory
{
    /** What a row shows, loaded with it. */
    public const RELATIONS = ['user', 'plan', 'server', 'subscription.server', 'payments.method'];

    /** The tab that is not a status: orders paid and not delivered — the queue that waits on support (Order::stuck()). */
    public const STUCK = 'stuck';

    public function __construct(private readonly OrderActions $actions) {}

    /** A page of orders, newest first or by amount, with the stuck count and the list's sales count and total. */
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
        // A renewal, and extras, name no server of their own: they are where their service is.
        $server = $order->server ?? $subscription?->server;

        return [
            'id' => $order->id,
            'type' => $order->type->value,
            'status' => $order->status->value,
            'amount' => $order->amount,
            // A purchase or a renewal made at its plan's first-purchase or agent price: less than the plan's price.
            'pricing' => $order->pricing->value,
        ] + self::discounts($order) + [
            'user' => UserDirectory::presentRef($order->user),
            'plan' => $order->plan === null ? null : ['id' => $order->plan->id, 'name' => $order->plan->name],
            'server' => $server === null ? null : ['id' => $server->id, 'name' => $server->name],
            'subscription' => $subscription === null ? null : ['id' => $subscription->id, 'name' => $subscription->remote_name, 'status' => $subscription->status->value],
            // What it brings in traffic (an agent's, a service's extra) or in days (a service's extra time).
            'traffic_bytes' => $order->traffic_bytes,
            'days' => $order->days,
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
     * An order's discount fields, as every API presents them (the website's OrderPresenter too): the Toman in all, the
     * lines, and the code it was made with (its id null once the code was deleted).
     *
     * @return array{discount_amount: string, discounts: list<array{source: string, label: string, amount: string, percent: int|null}>, discount_code: array{id: int|null, code: string}|null}
     */
    public static function discounts(Order $order): array
    {
        $code = $order->codeLine();

        return [
            'discount_amount' => $order->discount_amount,
            'discounts' => array_map(static fn(DiscountLine $line): array => $line->present(), $order->discountLines()),
            'discount_code' => $code?->code === null ? null : ['id' => $order->discount_code_id, 'code' => $code->code],
        ];
    }

    /**
     * Why the order failed or was cancelled, for whoever reads it: the owner gets the panel's full diagnosis (address,
     * answer); anyone else (an agent, the website's admins) the safe summary.
     */
    public static function notes(Order $order): ?string
    {
        return CurrentPrincipal::get()?->kind === PrincipalKind::Owner ? $order->diagnosis ?? $order->notes : $order->notes;
    }

    /**
     * A search by order number, customer, plan name or the service's panel name. Each is matched once (Page::matches()),
     * so the orders are read through their indexes.
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
