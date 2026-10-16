<?php

declare(strict_types=1);

namespace App\Modules\Store\Presenters;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderDirectory;
use App\Modules\Payments\DTO\PayLink;
use App\Modules\Payments\Enums\GatewayKind;
use App\Modules\Payments\Exceptions\PaymentHeldException;
use App\Modules\Payments\GatewayRegistry;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\OnlinePayments;
use App\Modules\Payments\Services\PaymentService;
use Illuminate\Support\Carbon;

/**
 * An order as its customer's website shows it, with its payments. Support's own words on an order (`notes`,
 * `diagnosis`) are never shown; a payment's verdict note (a rejection's reason) is the customer's to read, as in the bot;
 * an online payment's page is shown while it takes money — unless the payment is held for support. Relations come loaded
 * (Store\Services\CustomerOrders).
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
     * One attempt to pay an order: its way to pay, how it stands, whether a receipt was sent, its verdict's note, and an
     * online payment's page while it waits on it (openLink()).
     *
     * @return array{id: int, method: array{id: int, label: string, kind: string|null, min_amount: string|null, max_amount: string|null}, status: string, amount: string, created_at: string, paid_at: string|null, receipt: array{sent_at: string}|null, note: string|null, link: array{payment_id: int, url: string, expires_at: string|null, amount: string, fee: string|null, card: array{number: string, holder: string|null, bank: string|null}|null}|null}
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
            // A hold's note is support's: the customer reads that it is with support, not to be paid again. Support's own
            // word (a cancellation's note) replaces it once they settle it.
            'note' => in_array($payment->note, [PaymentService::NOTE_HELD, PaymentService::NOTE_UNSETTLED], true) ? sprintf(PaymentHeldException::MESSAGE, $payment->order_id) : $payment->note,
            'link' => $this->openLink($payment),
        ];
    }

    /**
     * An online gateway's page to pay on (Payments\DTO\PayLink): the payment it is for, its address, until when it takes
     * money (the gateway's end, or the time the shop keeps its page when the gateway gives none), the exact sum it asks — a gateway
     * may add a few Toman to the payment's, and its fee when the customer pays it — and the card that sum goes to, when
     * the gateway gave one to show (its holder and bank when it named them); and of that sum the gateway's fee.
     *
     * @return array{payment_id: int, url: string, expires_at: string|null, amount: string, fee: string|null, card: array{number: string, holder: string|null, bank: string|null}|null}
     */
    public function link(Payment $payment, PayLink $link): array
    {
        $card = $link->card->card ?? '';

        return [
            'payment_id' => $payment->id,
            'url' => $link->url,
            'expires_at' => $link->expiresAt === null ? null : Carbon::instance($link->expiresAt)->utc()->toIso8601String(),
            'amount' => $link->amount,
            'fee' => $link->fee,
            'card' => $card === '' ? null : ['number' => $card, 'holder' => self::words($link->card?->holder), 'bank' => self::words($link->card?->bank)],
        ];
    }

    /**
     * The page of an online payment still waiting on it — its page open on its gateway, still taking money
     * (Payment::hasOpenPage()) —, as its gateway reads it off the payment (initiate() calls nothing); null for any other,
     * and for one held for support: its order is paid no more meanwhile.
     *
     * @return array{payment_id: int, url: string, expires_at: string|null, amount: string, fee: string|null, card: array{number: string, holder: string|null, bank: string|null}|null}|null
     */
    private function openLink(Payment $payment): ?array
    {
        if (!$payment->hasOpenPage() || OnlinePayments::isHeld($payment) || $this->gateways->kind($payment->method->driver) !== GatewayKind::Online) {
            return null;
        }
        $link = $this->gateways->forPayment($payment)->initiate($payment)->link;

        return $link === null ? null : $this->link($payment, $link);
    }

    /** A card's holder or bank as the gateway gave it; null for none. */
    private static function words(?string $words): ?string
    {
        $words = trim((string) $words);

        return $words === '' ? null : $words;
    }

    /**
     * A way to pay: its label, how its driver settles — instant (the wallet), manual (a card transfer), online (the
     * gateway's own page), null for a driver no longer installed — and the amounts it is offered for (null: no bound).
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
