<?php

declare(strict_types=1);

namespace App\Modules\Store\Presenters;

use App\Modules\Orders\Models\Order;
use App\Modules\Payments\GatewayRegistry;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;

/**
 * An order as its customer's website shows it — what it was for, how far it got, its payments — and a payment of it.
 * What the shop's own screens say of it (`orders.notes`, `diagnosis`: why a delivery failed) is never shown; the
 * word on a payment's verdict (`payments.note`: a rejection's reason, a cancellation's note) is the customer's to read,
 * as the bot tells it them. Its relations are loaded with it (Store\Services\CustomerOrders::RELATIONS).
 */
final class OrderPresenter
{
    public function __construct(private readonly GatewayRegistry $gateways) {}

    /**
     * The order, what it named each a reference of its id and its name — the plan, the server, the service as the panel
     * names it (what a ticket names too) —: one way through the whole API.
     *
     * @return array<string, mixed>
     */
    public function present(Order $order): array
    {
        $plan = $order->plan;
        $service = $order->subscription;
        // A renewal names no server of its own: it is where its service is.
        $server = $order->server ?? $service?->server;

        return [
            'id' => $order->id,
            'type' => $order->type->value,
            'status' => $order->status->value,
            'amount' => $order->amount,
            'created_at' => $order->created_at->toIso8601String(),
            'fulfilled_at' => $order->fulfilled_at?->toIso8601String(),
            'plan' => $plan === null ? null : ['id' => $plan->id, 'name' => $plan->name],
            'server' => $server === null ? null : ['id' => $server->id, 'name' => $server->name],
            'subscription' => $service === null ? null : ['id' => $service->id, 'name' => $service->remote_name],
            'payments' => array_values($order->payments->map($this->payment(...))->all()),
        ];
    }

    /**
     * One attempt to pay an order: the way it was paid — the method's label and how its driver settles (instant, the
     * wallet; manual, a card transfer and its receipt; null for a driver no longer installed) —, how it stands, whether a
     * receipt was sent, and the word on its latest verdict.
     *
     * @return array{id: int, method: array{id: int, label: string, kind: string|null}, status: string, amount: string, created_at: string, paid_at: string|null, receipt: array{sent_at: string}|null, note: string|null}
     */
    public function payment(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'method' => $this->method($payment->method),
            'status' => $payment->status->value,
            'amount' => $payment->amount,
            'created_at' => $payment->created_at->toIso8601String(),
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'receipt' => $payment->receipt_at === null ? null : ['sent_at' => $payment->receipt_at->toIso8601String()],
            'note' => $payment->note,
        ];
    }

    /**
     * A way to pay, as the checkout names it: its label, and how its driver settles — instant (the wallet), manual (a
     * card transfer and its receipt), null for a driver no longer installed.
     *
     * @return array{id: int, label: string, kind: string|null}
     */
    public function method(PaymentMethod $method): array
    {
        return ['id' => $method->id, 'label' => $method->label, 'kind' => $this->gateways->kind($method->driver)?->value];
    }
}
