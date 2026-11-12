<?php

declare(strict_types=1);

namespace App\Modules\Payments\Tasks;

use App\Core\Scheduling\Budget;
use App\Core\Scheduling\Task;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Enums\GatewayKind;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\GatewayUnavailableException;
use App\Modules\Payments\GatewayRegistry;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\OnlinePayments;
use App\Modules\Settings\Services\ScheduleSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Psr\Log\LoggerInterface;

/**
 * The online gateways' payments asked about now and then (OnlinePayments::check()), should a gateway's callback never
 * come — the shop was out of reach, the call was lost:
 * - one whose page takes money: every few minutes (ScheduleSettings::checkMinutes());
 * - once its page closed, for a while yet (lateHours()) and less often (lateCheckMinutes()) — a gateway may still take
 *   money late (CubePay's late receipts);
 * - each whether it is still unpaid, failed with its page, or cancelled with its order: money taken on it is settled, or
 *   goes to the wallet, all the same;
 * - an unpaid one past that: once more, then closed for good — unless its gateway holds money on it it has not settled
 *   (OnlinePayments::hasMoneyIn(): a part paid, a receipt in the gateway's review), asked on as often until the review hours
 *   (reviewHours()) after its page closed, then held for support — at once, should the gateway close it meanwhile (OnlinePayments).
 * Never one held for support (OnlinePayments::isHeld()): that is theirs. A few per run (BATCH), the longest unasked
 * first, within the run's share of time (Budget). Every minute by default, in every shop. Each time is the owner's
 * («زمان‌بندی کارها», ScheduleSettings).
 */
final class OnlinePaymentsTask implements Task
{
    /** Payments asked about per run: each is a call to its gateway. */
    private const BATCH = 10;

    public function __construct(
        private readonly GatewayRegistry $gateways,
        private readonly OnlinePayments $payments,
        private readonly Budget $budget,
        private readonly LoggerInterface $logger,
        private readonly ScheduleSettings $schedule,
    ) {}

    public function run(): void
    {
        $methods = PaymentMethod::query()->pluck('driver', 'id')
            ->filter(fn(string $driver): bool => $this->gateways->kind($driver) === GatewayKind::Online)
            ->keys()
            ->all();
        if ($methods === []) {
            return;
        }

        $until = $this->budget->deadline();
        foreach ($this->due($methods) as $payment) {
            if (microtime(true) >= $until) {
                return;
            }
            $this->ask($payment);
        }
    }

    /**
     * The payments of those methods to ask about now, the never asked first, then the longest unasked — none held for
     * support, which would fill every run.
     *
     * @param list<int> $methods
     * @return iterable<Payment>
     */
    private function due(array $methods): iterable
    {
        $now = now();
        $check = $this->schedule->checkMinutes();
        $lateHours = $this->schedule->lateHours();
        $lateCheck = $this->schedule->lateCheckMinutes();
        $askedBefore = static fn(int $minutes): \Closure => static fn(Builder $asked) => $asked
            ->whereNull('checked_at')
            ->orWhere('checked_at', '<=', $now->copy()->subMinutes($minutes));

        $unpaid = [PaymentStatus::Pending->value, PaymentStatus::Failed->value, PaymentStatus::Cancelled->value];

        return Payment::query()
            ->whereIn('payment_method_id', $methods)
            ->whereNotNull('link')
            ->whereIn('status', $unpaid)
            ->whereNull('invoice->held')
            ->where(static fn(Builder $due) => $due
                ->where(static fn(Builder $open) => $open
                    ->where('link_expires_at', '>', $now)
                    ->where($askedBefore($check)))
                ->orWhere(static fn(Builder $late) => $late
                    ->where('link_expires_at', '<=', $now)
                    ->where('link_expires_at', '>', $now->copy()->subHours($lateHours))
                    ->where($askedBefore($lateCheck)))
                // Past its late hours: a pending one, to close it — or any with money in at its gateway, until it is held.
                ->orWhere(static fn(Builder $over) => $over
                    ->where('link_expires_at', '<=', $now->copy()->subHours($lateHours))
                    ->where(static fn(Builder $left) => $left
                        ->where('status', PaymentStatus::Pending->value)
                        ->orWhereNotNull('invoice->money_in'))
                    ->where($askedBefore($lateCheck))))
            // What a settlement reads: the method, and the order with what its delivery reads.
            ->with(['method', 'order' => static fn(Relation $order) => $order->with(OrderService::DELIVERY_RELATIONS)])
            ->oldest('checked_at')
            ->limit(self::BATCH)
            ->get();
    }

    /**
     * Asks about one payment. One still unpaid long after its page closed is closed, whatever the gateway says — unless
     * its gateway holds money on it unsettled: that one is held for support once the shop's review hours have passed
     * (ScheduleSettings::reviewHours(), «زمان‌بندی کارها»).
     */
    private function ask(Payment $payment): void
    {
        $closed = $payment->link_expires_at;
        $over = $closed->copy()->addHours($this->schedule->lateHours())->isPast();
        try {
            $this->payments->check($payment);
        } catch (GatewayUnavailableException $e) {
            $this->logger->warning('Online payment {id} could not be asked about: {detail}', ['id' => $payment->id, 'detail' => $e->detail]);
        } catch (\Throwable $e) {
            $this->logger->error('Asking about online payment {id} failed: {message}', ['id' => $payment->id, 'message' => $e->getMessage(), 'exception' => $e]);
        }
        if (!$over || OnlinePayments::isHeld($payment->refresh()) || !in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Failed, PaymentStatus::Cancelled], true)) {
            return;
        }
        if (OnlinePayments::hasMoneyIn($payment)) {
            $review = $this->schedule->reviewHours();
            if ($closed->copy()->addHours($review)->isPast()) {
                $this->payments->holdUnsettled($payment, $review);
            }

            return;
        }
        if ($payment->status === PaymentStatus::Pending) {
            $this->payments->close($payment);
        }
    }
}
