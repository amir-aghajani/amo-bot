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

/**
 * The payments screen («پرداخت‌ها», both panels): a searchable, filterable page of every payment with what the review
 * needs — the customer, the order, the method, the receipt — and the operations its modal may run now (`actions`,
 * PaymentActions' rules).
 */
final class PaymentDirectory
{
    /** What a row shows, loaded with it. */
    public const RELATIONS = ['order.user', 'order.plan', 'order.server', 'method'];

    public function __construct(
        private readonly PaymentActions $actions,
        private readonly GatewayRegistry $gateways,
        private readonly Reviewers $reviewers,
    ) {}

    /**
     * One status (a tab) or all, one customer's (`user`, their page links here), made on the days asked (`from`, `to`),
     * searched by number or customer — newest first, or by amount (`sort`) —; with the review queue's count and what the
     * list took in: how many of its payments are paid and their amount.
     */
    public function search(PageRequest $list): Page
    {
        $query = Payment::query();
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

        return Page::fetch($query->with(self::RELATIONS), $list, $this->present(...), $list->sort(['created' => 'id', 'amount' => 'amount'], 'created'))
            ->with(['awaiting_review' => Payment::query()->where('status', PaymentStatus::AwaitingReview->value)->count(), 'paid' => $paid, 'paid_amount' => $amount]);
    }

    /**
     * One row of the screen. `description` is the word on its latest verdict (the screen shows it for a failed or a
     * cancelled one), `refund_note` that word on a refund.
     *
     * @return array<string, mixed>
     */
    public function present(Payment $payment): array
    {
        $order = $payment->order;
        $method = $payment->method;

        return [
            'id' => $payment->id,
            'status' => $payment->status->value,
            'amount' => $payment->amount,
            'gateway' => $method->driver,
            'kind' => $this->gateways->kind($method->driver)?->value,
            'method' => $method->label,
            'summary' => $this->gateways->summary($method),
            'reference' => $payment->reference,
            'description' => $payment->note,
            'user' => UserDirectory::presentRef($order->user),
            'order' => [
                'id' => $order->id,
                'type' => $order->type->value,
                'status' => $order->status->value,
                'plan' => $order->plan?->name,
                'server' => $order->server?->name,
                'subscription_id' => $order->subscription_id,
                // Why its delivery failed, as whoever reads the screen may read it.
                'notes' => $order->status === OrderStatus::Failed ? OrderDirectory::notes($order) : null,
            ],
            // Sent in the bot or uploaded from the website: GET /payments/{id}/receipt serves either (Receipts::fetch()).
            'receipt' => $payment->receipt_at === null ? null : [
                'name' => $payment->receipt_name,
                'note' => $payment->receipt_note,
                'sent_at' => $payment->receipt_at->toIso8601String(),
            ],
            'auto_approved' => $payment->wasAutoApproved(),
            'reviewer' => $this->reviewers->present($payment->reviewer),
            'refund_note' => $payment->status === PaymentStatus::Refunded ? $payment->note : null,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'created_at' => $payment->created_at->toIso8601String(),
            'actions' => $this->actions->allowed($payment),
        ];
    }

    /**
     * By a bare number — the payment's or its order's —, or the customer — name, handle, Telegram id — the way every
     * list searches its customers. («#12» is the payment numbered 12 alone: PageRequest::search().)
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
