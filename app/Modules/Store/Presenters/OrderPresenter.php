<?php

declare(strict_types=1);

namespace App\Modules\Store\Presenters;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderDirectory;
use App\Modules\Payments\GatewayRegistry;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;

/**
 * An order as its customer's website shows it, with its payments. Support's own words on an order (`notes`,
 * `diagnosis`) are never shown; a payment's verdict note (a rejection's reason) is the customer's to read, as in the bot.
 * Relations come loaded (Store\Services\CustomerOrders).
 */
final class OrderPresenter
{
    public function __construct(private readonly GatewayRegistry $gateways) {}

    /**
     * The order: its plan, server and service each as `{id, name}` (a service by its panel name, as everywhere in the
     * API), and what it brings in traffic, days or devices.
     *
     * @return array<string, mixed>
     */
    public function present(Order $order): array
    {
        $plan = $order->plan;
        $service = $order->subscription;
        // A renewal, and extras, name no server of their own: they are where their service is.
        $server = $order->server ?? $service?->server;

        return [
            'id' => $order->id,
            'type' => $order->type->value,
            'status' => $order->status->value,
            'amount' => $order->amount,
            // The price a purchase or a renewal was made at: the plan's own, its first-purchase price or its agent price.
            'pricing' => $order->pricing->value,
        ] + OrderDirectory::discounts($order) + [
            'created_at' => $order->created_at->toIso8601String(),
            'fulfilled_at' => $order->fulfilled_at?->toIso8601String(),
            'plan' => $plan === null ? null : ['id' => $plan->id, 'name' => $plan->name],
            // What a purchase or a renewal buys, in words: its plan's name, or a built service's GB and days.
            'package_name' => $order->packageName(),
            'server' => $server === null ? null : ['id' => $server->id, 'name' => $server->name],
            'subscription' => $service === null ? null : ['id' => $service->id, 'name' => $service->remote_name],
            'traffic_bytes' => $order->traffic_bytes,
            'days' => $order->days,
            'devices' => $order->devices,
            'payments' => array_values($order->payments->map($this->payment(...))->all()),
        ];
    }

    /**
     * One attempt to pay an order: its way to pay, how it stands, whether a receipt was sent, and its verdict's note.
     *
     * @return array{id: int, method: array{id: int, label: string, kind: string|null, min_amount: string|null, max_amount: string|null}, status: string, amount: string, created_at: string, paid_at: string|null, receipt: array{sent_at: string}|null, note: string|null}
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
     * A way to pay: its label, how its driver settles — instant (the wallet), manual (a card transfer), null for a
     * driver no longer installed — and the amounts it is offered for (null: no bound).
     *
     * @return array{id: int, label: string, kind: string|null, min_amount: string|null, max_amount: string|null}
     */
    public function method(PaymentMethod $method): array
    {
        return [
            'id' => $method->id,
            'label' => $method->label,
            'kind' => $this->gateways->kind($method->driver)?->value,
            'min_amount' => $method->min_amount,
            'max_amount' => $method->max_amount,
        ];
    }
}
