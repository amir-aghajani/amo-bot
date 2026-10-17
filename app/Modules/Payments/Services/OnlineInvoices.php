<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Core\Http\Urls;
use App\Core\Security\Encrypter;
use App\Modules\Payments\Contracts\OnlineGateway;
use App\Modules\Payments\DTO\OnlineRequest;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\GatewayUnavailableException;
use App\Modules\Payments\Models\Payment;
use App\Support\LocalTime;
use App\Support\Money;
use Psr\Log\LoggerInterface;

/**
 * Opens a payment on its online gateway (Contracts\OnlineGateway::open()) — outside any transaction, since it calls the
 * gateway — and keeps what the gateway gave on the payment: its page, until when, its id and its own details. One page
 * per payment: of two openings at once (two taps, a website request made again while the first waits on the gateway),
 * the first saved is kept and both show it. The gateway is told the payment's id there (orderId(): the same every time
 * for one payment) and where to call back (callbackUrl(): a secret of the payment's own, so nobody else's call is taken,
 * and a call only ever makes the shop ask the gateway — OnlinePayments).
 */
final class OnlineInvoices
{
    /** The HKDF label of the callback secrets and of the ids at the gateway. */
    private const PURPOSE = 'amobot-online-payment';

    /** Hex characters of a callback's secret. */
    private const SECRET_LENGTH = 40;

    /** Minutes a page whose gateway says nothing of its end is taken to take money. */
    private const PAGE_MINUTES = 60;

    public function __construct(
        private readonly Urls $urls,
        private readonly Encrypter $encrypter,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Opens `$payment` on `$gateway` and keeps its invoice on it — while it has no page yet: one opened by another call in
     * the meantime is kept instead, and the payment, read afresh, answers with that one; this call's invoice at the
     * gateway is left to lapse unpaid.
     *
     * @throws GatewayUnavailableException logged with what the gateway said; nothing kept
     */
    public function open(Payment $payment, OnlineGateway $gateway): Payment
    {
        $customer = $payment->order->user;
        $request = new OnlineRequest(
            orderId: $this->orderId($payment),
            amount: (int) Money::digits($payment->amount),
            callbackUrl: $this->callbackUrl($payment),
            // A left-to-right mark before the number: a gateway's right-to-left page would show «12# سفارش».
            description: sprintf("سفارش \u{200E}#%d", $payment->order_id),
            telegramId: $customer->telegram_id === null ? null : (string) $customer->telegram_id,
            username: $customer->username,
        );

        try {
            $invoice = $gateway->open($payment, $request);
        } catch (GatewayUnavailableException $e) {
            $this->logger->warning('Payment {id} could not be opened on {gateway}: {detail}', ['id' => $payment->id, 'gateway' => $payment->method->driver, 'detail' => $e->detail]);

            throw $e;
        }

        // A page whose gateway gives no end is taken for an hour's: the shop needs one to know when to stop asking. One past
        // the last moment the shop keeps (LocalTime::last()) is kept to it: no page of the shop takes money past it anyway.
        $until = $invoice->expiresAt ?? now()->addMinutes(self::PAGE_MINUTES);
        $page = [
            'link' => $invoice->url,
            'link_expires_at' => LocalTime::tooLate($until) ? LocalTime::last() : $until,
            'reference' => $invoice->reference,
            'invoice' => $invoice->details,
        ];
        // Filled first so the casts give the storage form; written in one conditional update, only while no page is kept.
        // Pending again: a second opener the gateway refused at the same moment may have failed it already (no page yet).
        $payment->forceFill($page + ['status' => PaymentStatus::Pending, 'note' => null]);
        $payment->updateTimestamps();
        $kept = $payment->newQuery()->whereKey($payment->id)->whereNull('link')
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Failed->value])
            ->toBase()->update(['status' => PaymentStatus::Pending->value, 'note' => null] + $payment->getDirty()) === 1;
        if ($kept) {
            $payment->syncOriginal();

            return $payment;
        }

        $this->logger->info('Payment {id} was opened on {gateway} twice at once: the page kept first stays, invoice {reference} is left to lapse', ['id' => $payment->id, 'gateway' => $payment->method->driver, 'reference' => $invoice->reference ?? '-']);

        return $payment->refresh();
    }

    /** The address the gateway calls back about `$payment`. */
    public function callbackUrl(Payment $payment): string
    {
        return $this->urls->paymentCallback($payment->id, $this->secret($payment->id));
    }

    /** Whether `$secret` is the callback secret of payment `$id`. */
    public function isCallbackSecret(int $id, string $secret): bool
    {
        return hash_equals($this->secret($id), $secret);
    }

    /**
     * The payment's id at the gateway: its own id and a mark of this installation (two shops on one gateway account never
     * share one) — the same for one payment every time.
     */
    public function orderId(Payment $payment): string
    {
        return sprintf('amo-%d-%s', $payment->id, substr($this->encrypter->mac('order:' . $payment->id, self::PURPOSE), 0, 10));
    }

    private function secret(int $id): string
    {
        return substr($this->encrypter->mac('callback:' . $id, self::PURPOSE), 0, self::SECRET_LENGTH);
    }
}
