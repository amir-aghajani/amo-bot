<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Core\Support\FileLock;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Contracts\OnlineGateway;
use App\Modules\Payments\Enums\GatewayKind;
use App\Modules\Payments\Enums\OnlineSettlement;
use App\Modules\Payments\Enums\OnlineState;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\GatewayUnavailableException;
use App\Modules\Payments\Exceptions\PaymentHeldException;
use App\Modules\Payments\GatewayRegistry;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Telegram\Reports\ShopReports;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * What came of the payments made on online gateways, as each gateway says it (Contracts\OnlineGateway::check()) — never
 * as a call to the callback address says: that only makes the shop ask. Asked when the gateway calls back, when the
 * customer taps «بررسی پرداخت» (or the website asks), when support asks, and now and then until a page has been closed a
 * while (Tasks\OnlinePaymentsTask). Paid: settled — its order delivered, or its money to the wallet once the order had
 * closed (PaymentService::settleOnline()) — and the customer told, by whichever call settled it; closed: failed, for the
 * customer to pay again — and settled all the same, should the gateway take money on it later. Waiting with money in at
 * the gateway: marked so (hasMoneyIn()), never given up on by the shop alone. The asks about one payment take turns
 * (a lock file per payment under `$turns`, shared hosting having no lock server): the gateway's answer and what the shop
 * does with it are one step, read afresh — two at once could otherwise settle what the other holds.
 */
final class OnlinePayments
{
    /** The lock files asks share, by payment id: few files, a rare wait between two payments. */
    private const TURNS = 32;

    /** @var array<string, int> The turns this process holds, by lock file: one taken again inside itself is not waited for. */
    private static array $mine = [];

    public function __construct(
        private readonly GatewayRegistry $gateways,
        private readonly PaymentService $payments,
        private readonly OnlineInvoices $invoices,
        private readonly CustomerNotifier $notifier,
        private readonly ShopReports $reports,
        private readonly ConnectionInterface $db,
        private readonly LoggerInterface $logger,
        private readonly string $turns,
    ) {}

    /** Whether the payment is held for support (hold()): the shop no longer asks its gateway about it. */
    public static function isHeld(Payment $payment): bool
    {
        return is_string($payment->invoice['held'] ?? null);
    }

    /**
     * Whether the payment's order waits for support: held, and still waiting — pending, or failed with its page (a late
     * answer can hold one) —: it does not expire and is paid no other way. One support then cancelled («لغو پرداخت») holds
     * nothing.
     */
    public static function awaitsSupport(Payment $payment): bool
    {
        return self::isHeld($payment) && in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Failed], true);
    }

    /**
     * Whether its gateway said money came in on it that it has not settled yet — a part of the sum, a receipt in the
     * gateway's own review (OnlineVerdict::$moneyIn): asked on long after its page closed (Tasks\OnlinePaymentsTask).
     */
    public static function hasMoneyIn(Payment $payment): bool
    {
        return ($payment->invoice['money_in'] ?? false) === true;
    }

    /**
     * Asks `$payment`'s gateway what came of it and acts on the answer, the customer told what a settlement came to; what
     * it came to. A paid or refunded one is paid without asking; one never opened on the gateway (it would not open it)
     * is closed; one held for support is not asked. `$by`: who of support asked (the report of a hold it causes names them).
     *
     * @throws GatewayUnavailableException the gateway could not say, nothing changed — or `held`: the payment is support's
     */
    public function check(Payment $payment, ?string $by = null): OnlineState
    {
        [$state, $settlement] = $this->ask($payment, $by);
        match ($settlement) {
            OnlineSettlement::Settled => $this->notifier->paymentSettled($payment),
            OnlineSettlement::Credited => $this->notifier->paymentCredited($payment),
            OnlineSettlement::Decided, null => null,
        };

        return $state;
    }

    /**
     * Gives up on an unpaid payment whose page closed long ago (Tasks\OnlinePaymentsTask): failed, the customer free to
     * pay again. Should the gateway take money on it after all, its callback still settles it (check()).
     */
    public function close(Payment $payment): bool
    {
        return $this->payments->closeOnline($payment);
    }

    /**
     * Leaves to support a payment its gateway kept money on, unsettled, `$hours` after its page closed (Tasks\
     * OnlinePaymentsTask): the shop's own asking stops there.
     */
    public function holdUnsettled(Payment $payment, int $hours): void
    {
        $this->inTurn($payment, function () use ($payment, $hours): void {
            if (!in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::Refunded], true)) {
                $this->hold($payment, sprintf('the gateway kept money on payment #%d unsettled for %d hours after its page closed', $payment->id, $hours), null, PaymentService::NOTE_UNSETTLED);
            }
        });
    }

    /**
     * Whether the order was paid on one of its pages of `$method` the checkout would not show again — its page closing
     * within PaymentService::LINK_SPARE_MINUTES, or closed —: each is asked about before another is opened (a bank's text
     * may reach the gateway minutes after the transfer); what settling the one the gateway says was paid came to, null
     * for none. Settled: the checkout answers with the order as it stands — the customer's word on it —, so no notice is
     * sent; credited — its order closed in that same moment —: the customer is told the money went to the wallet; decided
     * by another call a moment earlier: that call told them. A gateway that cannot say is asked again later (Tasks\
     * OnlinePaymentsTask); the checkout goes on meanwhile. One whose answer holds it for support stops the checkout.
     *
     * @throws PaymentHeldException a page's answer held it: its order waits for support
     */
    public function paidBefore(Order $order, PaymentMethod $method): ?OnlineSettlement
    {
        if ($this->gateways->kind($method->driver) !== GatewayKind::Online) {
            return null;
        }
        $closing = $order->payments()
            ->where('payment_method_id', $method->id)
            ->where('status', PaymentStatus::Pending->value)
            ->whereNotNull('link')
            ->where('link_expires_at', '<=', now()->addMinutes(PaymentService::LINK_SPARE_MINUTES))
            ->with('method')
            ->get();
        foreach ($closing as $payment) {
            try {
                [, $settlement] = $this->ask($payment, null);
            } catch (GatewayUnavailableException $e) {
                if ($e->held) {
                    throw new PaymentHeldException($payment);
                }
                $this->logger->warning('Online payment {id} could not be asked about before another page was opened: {detail}', ['id' => $payment->id, 'detail' => $e->detail]);

                continue;
            }
            match ($settlement) {
                // The checkout shows the customer the order; the referrer of a commission it earned hears it here.
                OnlineSettlement::Settled => $this->notifier->commissionSettled($payment),
                OnlineSettlement::Credited => $this->notifier->paymentCredited($payment),
                OnlineSettlement::Decided, null => null,
            };
            if ($settlement !== null) {
                return $settlement;
            }
        }

        return null;
    }

    /**
     * check() but telling nobody: the gateway's word, and what settling it came to when it was paid (Decided for one paid
     * before it was asked).
     *
     * @return array{OnlineState, OnlineSettlement|null}
     * @throws GatewayUnavailableException
     */
    private function ask(Payment $payment, ?string $by): array
    {
        return $this->inTurn($payment, fn(): array => $this->askNow($payment, $by));
    }

    /**
     * ask(), its turn taken and the payment read afresh: paid or refunded is paid without asking; never opened on its
     * gateway, or held and since cancelled by support, is closed; held and still waiting is support's.
     *
     * @return array{OnlineState, OnlineSettlement|null}
     * @throws GatewayUnavailableException
     */
    private function askNow(Payment $payment, ?string $by): array
    {
        if (in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::Refunded], true)) {
            return [OnlineState::Paid, OnlineSettlement::Decided];
        }
        if ($payment->link === null || (self::isHeld($payment) && !self::awaitsSupport($payment))) {
            return [OnlineState::Closed, null];
        }
        if (self::isHeld($payment)) {
            throw new GatewayUnavailableException('Held for support: ' . $payment->invoice['held'], held: true);
        }
        $gateway = $this->gateways->forPayment($payment);
        if (!$gateway instanceof OnlineGateway) {
            throw new \LogicException("Payment #{$payment->id} was not made on an online gateway.");
        }

        try {
            $verdict = $gateway->check($payment);
        } catch (GatewayUnavailableException $e) {
            if ($e->held) {
                $this->hold($payment, $e->detail, $by);
            }

            throw $e;
        } finally {
            // Without its updated_at: asking is no news of the payment, and an idle order's expiry reads that.
            $payment->newQuery()->whereKey($payment->id)->toBase()->update(['checked_at' => $payment->freshTimestamp()]);
        }

        if ($verdict->state === OnlineState::Paid) {
            return [OnlineState::Paid, $this->payments->settleOnline($payment, $verdict->reference)];
        }
        if ($verdict->state === OnlineState::Closed) {
            $this->payments->closeOnline($payment);
        } elseif ($verdict->moneyIn && !self::hasMoneyIn($payment) && self::mark($payment, ['money_in' => true])) {
            $this->logger->info('Online payment {id} has money in at its gateway, not settled yet', ['id' => $payment->id]);
        }

        return [$verdict->state, null];
    }

    /**
     * Leaves a payment to support — its gateway answered what does not fit it (another order, a smaller amount, an
     * underpayment its customer accepted), or kept money on it unsettled too long: never settled by the shop's own asking
     * from then on — the gateway would only say "verified before" —, its note (`$note`) saying so on the payments screen,
     * the log what the gateway said, and the report group told once (ShopReports::heldPayment()), in one transaction.
     */
    private function hold(Payment $payment, string $detail, ?string $by, string $note = PaymentService::NOTE_HELD): void
    {
        $held = $this->db->transaction(function () use ($payment, $detail, $by, $note): bool {
            if (!self::mark($payment, ['held' => $detail], ['note' => $note])) {
                return false;
            }
            $this->reports->heldPayment($payment, $payment->reference ?? $this->invoices->orderId($payment), $detail, $by);

            return true;
        });
        if ($held) {
            $this->logger->error('Online payment {id} is held for support: {detail}', ['id' => $payment->id, 'detail' => $detail]);
        }
    }

    /**
     * Runs `$step` in the payment's turn — the lock asks about it share, waited for; already this process's, taken again
     * at once —, the payment read afresh first: what an ask that held the turn did (settled, held, closed) is what this one
     * sees.
     *
     * @template T
     * @param \Closure(): T $step
     * @return T
     */
    private function inTurn(Payment $payment, \Closure $step): mixed
    {
        $path = $this->turns . '/' . ($payment->id % self::TURNS) . '.lock';
        $turn = isset(self::$mine[$path]) ? null : FileLock::wait($path);
        self::$mine[$path] = (self::$mine[$path] ?? 0) + 1;
        try {
            $fresh = $payment->newQuery()->whereKey($payment->id)->first();
            if ($fresh !== null) {
                // Its attributes only: the relations the caller loaded stay.
                $payment->setRawAttributes($fresh->getAttributes(), true);
            }

            return $step();
        } finally {
            if (--self::$mine[$path] === 0) {
                unset(self::$mine[$path]);
            }
            $turn?->release();
        }
    }

    /**
     * Writes the shop's `$marks` into the payment's invoice (and `$more` beside it) in one conditional update, while it
     * holds none of them nor a hold and is still unpaid — of two at once one writes, a hold is never written over, a
     * settled payment never marked. True when this call did; the payment is read afresh otherwise. Marking is news of
     * it: its updated_at moves.
     *
     * @param array<string, mixed> $marks
     * @param array<string, mixed> $more
     */
    private static function mark(Payment $payment, array $marks, array $more = []): bool
    {
        $payment->forceFill(['invoice' => $marks + ($payment->invoice ?? [])] + $more);
        $payment->updateTimestamps();
        $query = $payment->newQuery()->whereKey($payment->id)
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Failed->value, PaymentStatus::Cancelled->value]);
        foreach (array_unique(['held', ...array_keys($marks)]) as $absent) {
            $query->whereNull('invoice->' . $absent);
        }
        $written = $query->toBase()->update($payment->getDirty()) === 1;
        if ($written) {
            $payment->syncOriginal();
        } else {
            $payment->refresh();
        }

        return $written;
    }
}
