<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Core\Database\Page;
use App\Core\Database\PageRequest;
use App\Modules\Auth\Services\Reviewers;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderDirectory;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\GatewayRegistry;
use App\Modules\Payments\Models\Payment;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\UserDirectory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The payments screen («پرداخت‌ها»): a searchable page of payments with what a review needs, who decided each (Reviewers:
 * the owner's login reads «پشتیبانی» to anyone but the owner; one of the shop's admins with their name too) and the
 * operations allowed now (`actions`, PaymentActions::allowed()).
 */
final class PaymentDirectory
{
    /** What a row shows, loaded with it. */
    public const RELATIONS = ['order.user', 'order.plan', 'order.server', 'order.subscription.server', 'method', 'card'];

    /** The tab that is not a status: online payments held for support and still waiting (Payment::whereHeld()). */
    public const HELD = 'held';

    public function __construct(
        private readonly PaymentActions $actions,
        private readonly GatewayRegistry $gateways,
        private readonly Reviewers $reviewers,
    ) {}

    /**
     * A page filtered by status (or the HELD queue), customer and days, searched by number or customer, newest first or by
     * amount; with the review and held queues' counts and the list's paid count and total.
     */
    public function search(PageRequest $list): Page
    {
        $query = $list->text('status') === self::HELD ? Payment::whereHeld(Payment::query()) : Payment::query();
        $status = $list->enum('status', PaymentStatus::class);
        if ($status !== null) {
            $query->where('status', $status->value);
        }
        $user = $list->id('user');
        if ($user !== null) {
            $query->whereRelation('order', 'user_id', $user);
        }
        $list->dates()->apply($query, 'created_at');
        $list->search($query, self::applySearch(...));
        [$paid, $amount] = Page::tally((clone $query)->where('status', PaymentStatus::Paid->value), 'amount');

        return Page::fetchTogether($query->with(self::RELATIONS), $list, $this->presentAll(...), $list->sort(['created' => 'id', 'amount' => 'amount'], 'created'))
            ->with([
                'awaiting_review' => Payment::query()->where('status', PaymentStatus::AwaitingReview->value)->count(),
                'held' => Payment::whereHeld(Payment::query())->count(),
                'paid' => $paid,
                'paid_amount' => $amount,
            ]);
    }

    /**
     * One row of the screen. `description` is the note of its latest verdict; `refund_note` the same, on a refund. An
     * online payment's page closes at `link_expires_at`, and `checked_at` is when its gateway was last asked.
     *
     * @return array<string, mixed>
     */
    public function present(Payment $payment): array
    {
        return $this->presentAll(new Collection([$payment]))[0];
    }

    /**
     * Rows of the screen, the names of the shop's admins who decided them read once for them all (Reviewers::names()).
     *
     * @param Collection<int, Payment> $payments
     * @return list<array<string, mixed>>
     */
    private function presentAll(Collection $payments): array
    {
        $names = $this->reviewers->names($payments->pluck('reviewer'));

        return array_values($payments->map(fn(Payment $payment): array => $this->row($payment, $names[$payment->reviewer ?? ''] ?? null))->all());
    }

    /**
     * present(), with the name of the admin who decided it (`$reviewerName`).
     *
     * @return array<string, mixed>
     */
    private function row(Payment $payment, ?string $reviewerName): array
    {
        $order = $payment->order;
        $method = $payment->method;

        return [
            'id' => $payment->id,
            'status' => $payment->status->value,
            'amount' => $payment->amount,
            'gateway' => $method->driver,
            'gateway_label' => $this->gateways->find($method->driver)?->describe()->label,
            'kind' => $this->gateways->kind($method->driver)?->value,
            'method' => $method->label,
            // A card transfer's card — the one it was shown, among its method's — or, shown none, support's private chat;
            // else its driver's line.
            'summary' => $payment->card?->summary() ?? ($this->gateways->isManual($method) ? Payment::TO_SUPPORT : $this->gateways->summary($method)),
            'reference' => $payment->reference,
            'description' => $payment->note,
            'user' => UserDirectory::presentRef($order->user),
            'order' => [
                'id' => $order->id,
                'type' => $order->type->value,
                'status' => $order->status->value,
                // Its plan's name — or a service the customer built, in words (Order::packageName()).
                'plan' => $order->packageName(),
                // A renewal, and extras, name no server of their own: they are where their service is (as on the
                // orders screen, OrderDirectory::present()).
                'server' => ($order->server ?? $order->subscription?->server)?->name,
                'subscription_id' => $order->subscription_id,
                // What it brings in traffic (an agent's, a service's extra), in days (a service's extra time) or in
                // devices.
                'traffic_bytes' => $order->traffic_bytes,
                'days' => $order->days,
                'devices' => $order->devices,
                // Why its delivery failed, as whoever reads the screen may read it.
                'notes' => $order->status === OrderStatus::Failed ? OrderDirectory::notes($order) : null,
                // What its discounts took off what is paid.
            ] + OrderDirectory::discounts($order),
            // Sent in the bot or uploaded from the website: GET /payments/{id}/receipt serves either (Receipts::fetch()).
            'receipt' => $payment->receipt_at === null ? null : [
                'name' => $payment->receipt_name,
                'note' => $payment->receipt_note,
                'sent_at' => $payment->receipt_at->toIso8601String(),
            ],
            'auto_approved' => $payment->wasAutoApproved(),
            'reviewer' => $this->reviewers->present($payment->reviewer),
            'reviewer_name' => $reviewerName,
            'refund_note' => $payment->status === PaymentStatus::Refunded ? $payment->note : null,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'link_expires_at' => $payment->link_expires_at?->toIso8601String(),
            'checked_at' => $payment->checked_at?->toIso8601String(),
            'to_wallet' => $payment->wentToWallet(),
            'held' => OnlinePayments::isHeld($payment),
            'created_at' => $payment->created_at?->toIso8601String(),
            'actions' => $this->actions->allowed($payment),
        ];
    }

    /**
     * A search by a bare number (the payment's or its order's) or by the customer.
     *
     * @param Builder<Payment> $query
     */
    private static function applySearch(Builder $query, string $term, ?int $number): void
    {
        $orders = Order::query()->select('id')->whereIn('user_id', User::idsMatching($term));

        $query->where(static function (Builder $q) use ($orders, $number): void {
            $q->whereIn('order_id', $orders);
            if ($number !== null) {
                $q->orWhere('id', $number)->orWhere('order_id', $number);
            }
        });
    }
}
