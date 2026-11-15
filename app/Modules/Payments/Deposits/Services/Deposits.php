<?php

declare(strict_types=1);

namespace App\Modules\Payments\Deposits\Services;

use App\Core\Database\Page;
use App\Core\Database\PageRequest;
use App\Core\Database\Transitions;
use App\Core\Exceptions\ValidationException;
use App\Modules\Auth\Actor;
use App\Modules\Auth\Services\Reviewers;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\OrderNotPayableException;
use App\Modules\Payments\Deposits\Contracts\DepositSource;
use App\Modules\Payments\Deposits\DTO\IncomingDeposit;
use App\Modules\Payments\Deposits\Enums\AttachOutcome;
use App\Modules\Payments\Drivers\Manual\ManualGateway;
use App\Modules\Payments\Enums\DepositStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Deposit;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentActions;
use App\Modules\Telegram\Reports\ShopReports;
use App\Modules\Users\Services\UserDirectory;
use App\Support\Money;
use App\Support\Persian;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;

/**
 * «تایید خودکار واریز»: each bank deposit its source sends (Contracts\DepositSource: a phone app's forwarded SMS, ForApp;
 * Rikhtam's word) is kept once, by the source's id for it, and its sum looked for among the current shop's open card
 * payments (PaymentCards gives each a sum of its own). Exactly one, and it is approved as support would (REVIEWER); none
 * or several, and the report group is told: money never goes to the wrong order on a guess. One transfer both sources tell
 * (ForApp and Rikhtam side by side) approves once: the second word confirms the payment the first approved — never
 * another that asks the same sum since. A deposit its source's owner attaches to a payment by hand (attach()) approves
 * the payment it names instead, when it covers its order.
 */
final class Deposits
{
    /** The name an approval by a bank SMS keeps (payments.reviewer): shown as it is to anyone (Reviewers::shared()). */
    public const REVIEWER = Reviewers::BANK_SMS;

    /** How far back an SMS is still taken: one older is no transfer anybody waits on now. */
    public const LISTENS_BACK_HOURS = 72;

    /**
     * Seconds taking one SMS may run (the webhook's time limit): one still Received after that was left by a process that
     * died midway.
     */
    public const TIME_LIMIT = 120;

    /** A payment made up to this long after the SMS's time still counts: the phone's clock may run behind. */
    private const SKEW_MINUTES = 10;

    /** What a kept deposit may be when its owner attaches it to a payment (attach()): anything that approved none. */
    private const ATTACHABLE = [DepositStatus::Unmatched, DepositStatus::Ambiguous, DepositStatus::Ignored, DepositStatus::Test];

    private const APPROVED_ALREADY = 'این پرداخت پیش‌تر تایید شده بود؛ پیامک واریزش را تایید کرد.';

    private const NOT_A_DEPOSIT = 'معلوم نبود این پیامک واریز است یا برداشت؛ چیزی خودکار تایید نشد.';

    /** The note on the second word of one transfer, from the other source (ForApp and Rikhtam side by side). */
    private const TOLD_BEFORE = 'همین واریز را منبع دیگری هم خبر داده بود و پرداخت با آن تایید شده بود؛ دوباره تایید نشد.';

    public function __construct(
        private readonly PaymentActions $actions,
        private readonly ShopReports $reports,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * The deposit `$in`, from `$source`, taken once: what came of it (one sent again, what came of it then — or, left
     * Received by a process that died midway, decided now).
     */
    public function take(DepositSource $source, IncomingDeposit $in): Deposit
    {
        $deposit = self::kept($source, $in->externalId);
        if ($deposit !== null && !Transitions::reclaim($deposit, 'status', DepositStatus::Received, self::TIME_LIMIT)) {
            return $deposit;
        }
        $deposit ??= self::keep($source, $in);
        if ($deposit === null) {
            // The app sent it twice at once: the other request has it.
            return self::kept($source, $in->externalId) ?? throw new \LogicException('A deposit vanished as it was taken.');
        }

        [$status, $note, $payment, $named] = $this->decide($deposit, $in);
        $deposit->forceFill(['status' => $status, 'note' => $note, 'payment_id' => $payment?->id])->save();
        $this->logger->info('Deposit {id} from {source} ({amount} rial): {status}', ['id' => $deposit->id, 'source' => $source->key(), 'amount' => $in->amountRial ?? '?', 'status' => $status->value]);
        match ($status) {
            DepositStatus::Unmatched => $this->reports->depositUnmatched($deposit),
            DepositStatus::Ambiguous => $this->reports->depositAmbiguous($deposit, $named),
            default => null,
        };

        return $deposit;
    }

    /**
     * The deposit `$in`, from `$source`, attached by hand to the payment `$paymentId` (its source's own "attach to an
     * invoice"): kept once by its id as take() keeps one, then — not looked for by its sum — the payment it names
     * approved as support would (REVIEWER), when it is one of the shop's card payments, open, its order open, and the
     * deposit covers its order's own price (a customer who rounded the payment's sum of its own down is paid all the
     * same) — or, one support approved before that no deposit names yet, confirmed as take() confirms one found by its
     * sum. Its age is not asked: its owner chose it. A deposit approves one payment and a payment is paid once; anything
     * else it came to leaves it kept, unmatched, its note saying why. What came of it, and the deposit as it stands.
     *
     * @return array{AttachOutcome, Deposit}
     */
    public function attach(DepositSource $source, IncomingDeposit $in, int $paymentId): array
    {
        $deposit = self::kept($source, $in->externalId);
        if ($deposit !== null) {
            $claimed = $deposit->status === DepositStatus::Received
                ? Transitions::reclaim($deposit, 'status', DepositStatus::Received, self::TIME_LIMIT)
                : Transitions::move($deposit, 'status', self::ATTACHABLE, DepositStatus::Received);
            if (!$claimed) {
                // Matched already — sent again, or attached to another payment —, or being decided this moment.
                $outcome = $deposit->status !== DepositStatus::Matched ? AttachOutcome::Busy
                    : ($deposit->payment_id === $paymentId ? AttachOutcome::Confirmed : AttachOutcome::Paid);

                return [$outcome, $deposit];
            }
        } else {
            $deposit = self::keep($source, $in);
            if ($deposit === null) {
                // Sent twice at once: the other request is deciding it.
                return [AttachOutcome::Busy, self::kept($source, $in->externalId) ?? throw new \LogicException('A deposit vanished as it was taken.')];
            }
        }

        [$outcome, $note, $payment] = $this->attached($deposit, $paymentId);
        $status = $outcome->paid() ? DepositStatus::Matched : DepositStatus::Unmatched;
        $deposit->forceFill(['status' => $status, 'note' => $note, 'payment_id' => $payment?->id])->save();
        $this->logger->info('Deposit {id} from {source} attached by hand to payment {payment}: {outcome}', ['id' => $deposit->id, 'source' => $source->key(), 'payment' => $paymentId, 'outcome' => $outcome->value]);

        return [$outcome, $deposit];
    }

    /**
     * The current shop's deposits, newest first: by status (`?status=`), or searched by sum (digits, in Toman or Rial) or
     * by the SMS's words.
     */
    public function search(PageRequest $list): Page
    {
        $query = Deposit::query();
        $status = $list->enum('status', DepositStatus::class);
        if ($status !== null) {
            $query->where('status', $status->value);
        }
        $list->search($query, static function (Builder $query, string $term): void {
            $digits = Persian::latinDigits(str_replace([',', '٬'], '', $term));
            $query->where(static fn(Builder $match) => ctype_digit($digits)
                ? $match->whereIn('amount_rial', [(int) $digits, (int) $digits * 10])
                : $match->where('body', 'like', '%' . addcslashes($term, '%_\\') . '%'));
        });

        return Page::fetch($query->with(['payment.order.user'])->orderByDesc('id'), $list, self::present(...));
    }

    /** @return array<string, mixed> One deposit, its payment loaded with it. */
    public static function present(Deposit $deposit): array
    {
        $payment = $deposit->payment;

        return [
            'id' => $deposit->id,
            'source' => $deposit->source,
            'status' => $deposit->status->value,
            'amount_rial' => $deposit->amount_rial,
            'amount' => $deposit->toman(),
            'bank' => $deposit->bank,
            'sender' => $deposit->sender,
            'body' => $deposit->body,
            'note' => $deposit->note,
            'payment' => $payment === null ? null : [
                'id' => $payment->id,
                'order_id' => $payment->order_id,
                'customer' => UserDirectory::presentRef($payment->order->user),
            ],
            'received_at' => $deposit->received_at?->toIso8601String(),
            'created_at' => $deposit->created_at?->toIso8601String(),
        ];
    }

    /**
     * What came of the deposit, the payment it approved or confirmed, and the payments asking its sum when several do. Only
     * a deposit its source vouches for is matched (IncomingDeposit::$doubt); one it could not tell is support's. One the
     * other source told first (toldBefore()) confirms what that approved.
     *
     * @return array{DepositStatus, string|null, Payment|null, list<int>}
     */
    private function decide(Deposit $deposit, IncomingDeposit $in): array
    {
        if ($in->test) {
            return [DepositStatus::Test, null, null, []];
        }
        if ($in->isDeposit === false) {
            return [DepositStatus::Ignored, 'برداشت بود، نه واریز.', null, []];
        }
        if ($in->amountRial === null || $in->amountRial === 0) {
            return [DepositStatus::Ignored, 'مبلغی در پیامک خوانده نشد.', null, []];
        }
        if ($in->receivedAt->lt(now()->subHours(self::LISTENS_BACK_HOURS))) {
            return [DepositStatus::Ignored, 'پیامک قدیمی‌تر از سه روز بود.', null, []];
        }
        if ($in->doubt !== null || $in->isDeposit !== true) {
            return [DepositStatus::Unmatched, $in->doubt ?? self::NOT_A_DEPOSIT, null, []];
        }
        // The same transfer the other source told first (ForApp and Rikhtam side by side): its payment, before any payment
        // that asks its sum now — one made since may ask it again, its first payer's deposit having come.
        $told = self::toldBefore($deposit);
        if ($told !== null) {
            return [DepositStatus::Matched, self::TOLD_BEFORE, $told, []];
        }

        $candidates = $this->candidates($deposit);
        if (count($candidates) > 1) {
            $ids = array_map(static fn(Payment $payment): int => $payment->id, $candidates);

            return [DepositStatus::Ambiguous, 'چند پرداخت باز همین مبلغ را می‌خواهند؛ هیچ‌کدام خودکار تایید نشد.', null, $ids];
        }
        if ($candidates === []) {
            $approved = $this->approvedAlready($deposit);

            return $approved === null
                ? [DepositStatus::Unmatched, 'هیچ پرداخت کارت به کارت بازی این مبلغ را نمی‌خواهد.', null, []]
                : [DepositStatus::Matched, self::APPROVED_ALREADY, $approved, []];
        }

        $payment = $candidates[0];
        try {
            $this->actions->approve($payment, Actor::machine(self::REVIEWER));
        } catch (ValidationException|OrderNotPayableException) {
            // Decided otherwise in the same moment: approved by support confirms it, and so does the same transfer the other
            // source brought at once; anything else is support's.
            $payment->refresh();
            if (self::onlyConfirms($payment)) {
                return [DepositStatus::Matched, self::APPROVED_ALREADY, $payment, []];
            }
            if ($payment->status === PaymentStatus::Paid && self::toldAtOnce($deposit, $payment)) {
                return [DepositStatus::Matched, self::TOLD_BEFORE, $payment, []];
            }

            return [DepositStatus::Unmatched, sprintf("پرداخت \u{200E}#%d همان لحظه به شکل دیگری بسته شد.", $payment->id), null, []];
        }

        return [DepositStatus::Matched, null, $payment->refresh(), []];
    }

    /**
     * What attaching the deposit to the payment `$paymentId` came to, the note the deposit keeps, and the payment it
     * approved.
     *
     * @return array{AttachOutcome, string, Payment|null}
     */
    private function attached(Deposit $deposit, int $paymentId): array
    {
        $payment = $paymentId > 0 ? Payment::query()->with(['order', 'method'])->find($paymentId) : null;
        if ($payment === null || $payment->method->driver !== ManualGateway::key()) {
            return [AttachOutcome::NoPayment, sprintf("پرداخت کارت به کارت \u{200E}#%d در این فروشگاه نیست؛ واریز به آن پیوست نشد.", $paymentId), null];
        }
        $toman = $deposit->toman();
        $price = $payment->order->amount;
        $covers = $toman !== null && Money::compare($toman, $price) >= 0;
        if ($covers && self::onlyConfirms($payment)) {
            return [AttachOutcome::Confirms, self::APPROVED_ALREADY, $payment];
        }
        $unpayable = self::unpayable($payment);
        if ($unpayable !== null) {
            return [...$unpayable, null];
        }
        if (!$covers) {
            $sum = $toman === null ? 'بی‌مبلغ' : Money::format($toman);

            return [AttachOutcome::TooLittle, sprintf("واریز %s کمتر از مبلغ سفارش پرداخت \u{200E}#%d (%s) است؛ به آن پیوست نشد.", $sum, $payment->id, Money::format($price)), null];
        }

        try {
            $this->actions->approve($payment, Actor::machine(self::REVIEWER));
        } catch (ValidationException|OrderNotPayableException) {
            // Decided otherwise in the same moment: paid, or closed.
            return [...(self::unpayable($payment->refresh()) ?? [AttachOutcome::Paid, PaymentActions::DECIDED_MEANWHILE]), null];
        }

        return [AttachOutcome::Approved, 'دستی به این پرداخت پیوست شد و آن را تایید کرد.', $payment->refresh()];
    }

    /**
     * Why the card payment can no longer be paid by a deposit attached to it, and the note the deposit keeps; null while
     * it can.
     *
     * @return array{AttachOutcome, string}|null
     */
    private static function unpayable(Payment $payment): ?array
    {
        $order = $payment->order;

        return match (true) {
            $payment->status->isSettled() => [AttachOutcome::Paid, sprintf("پرداخت \u{200E}#%d پیش‌تر پرداخت شده بود؛ واریز به آن پیوست نشد.", $payment->id)],
            $payment->status === PaymentStatus::Cancelled, $order->status === OrderStatus::Cancelled
                => [AttachOutcome::Closed, sprintf("پرداخت \u{200E}#%d یا سفارشش لغو شده یا مهلتش گذشته بود؛ واریز به آن پیوست نشد.", $payment->id)],
            $order->status !== OrderStatus::Pending => [AttachOutcome::Paid, sprintf("سفارش پرداخت \u{200E}#%d با پرداخت دیگری پرداخت شده بود؛ واریز به آن پیوست نشد.", $payment->id)],
            default => null,
        };
    }

    /**
     * The open card payments the deposit could pay: waiting for the transfer or their receipt's review, or refused (support
     * may still approve one), their order open.
     *
     * @return list<Payment>
     */
    private function candidates(Deposit $deposit): array
    {
        $payments = self::ofSum($deposit);

        return $payments === null ? [] : $payments
            ->whereIn('status', array_map(static fn(PaymentStatus $status): string => $status->value, PaymentStatus::open()))
            ->whereRelation('order', 'status', OrderStatus::Pending->value)
            ->with(['order', 'method'])
            ->orderBy('id')
            ->get()->values()->all();
    }

    /**
     * The one card payment of the deposit's sum paid within LISTENS_BACK_HOURS that the SMS only confirms (confirmable());
     * null for none, or more than one.
     */
    private function approvedAlready(Deposit $deposit): ?Payment
    {
        $paid = self::ofSum($deposit);
        if ($paid === null) {
            return null;
        }
        $paid = self::confirmable($paid->where('status', PaymentStatus::Paid->value)->where('paid_at', '>=', now()->subHours(self::LISTENS_BACK_HOURS)))
            ->limit(2)->get();

        return $paid->count() === 1 ? $paid->first() : null;
    }

    /**
     * The shop's card payments of the deposit's sum, in Toman, made before the SMS came (SKEW_MINUTES allowed); null when
     * the sum is no whole Toman.
     *
     * @return Builder<Payment>|null
     */
    private static function ofSum(Deposit $deposit): ?Builder
    {
        $toman = $deposit->toman();
        if ($toman === null || $deposit->amount_rial % 10 !== 0) {
            return null;
        }

        return Payment::query()
            ->where('amount', Money::normalize($toman))
            ->whereRelation('method', 'driver', ManualGateway::key())
            ->where('created_at', '<=', ($deposit->received_at ?? now())->copy()->addMinutes(self::SKEW_MINUTES));
    }

    /**
     * `$payments` an SMS only confirms: approved by support or the review window — one approved by another SMS is that
     * SMS's —, and no SMS names it yet.
     *
     * @param Builder<Payment> $payments
     * @return Builder<Payment>
     */
    private static function confirmable(Builder $payments): Builder
    {
        return $payments
            ->where(static fn(Builder $by) => $by->whereNull('reviewer')->orWhere('reviewer', '!=', self::REVIEWER))
            ->whereNotIn('id', Deposit::query()->select('payment_id')->whereNotNull('payment_id'));
    }

    /** Whether `$payment` is paid already, and a deposit only confirms it (confirmable()). */
    private static function onlyConfirms(Payment $payment): bool
    {
        return $payment->status === PaymentStatus::Paid && self::confirmable(Payment::query()->whereKey($payment->id))->exists();
    }

    /**
     * The payment the other source's word on the same transfer approved or confirmed (twoSourced(), matched to it) while no
     * deposit of this one's source names it yet — the one closest in time first; null for none.
     */
    private static function toldBefore(Deposit $deposit): ?Payment
    {
        $at = $deposit->received_at ?? now();
        $told = self::twoSourced($deposit)
            ->where('status', DepositStatus::Matched->value)
            ->whereNotNull('payment_id')
            ->whereNotIn('payment_id', Deposit::query()->select('payment_id')->where('source', $deposit->source)->whereNotNull('payment_id'))
            ->with('payment')
            ->get()
            ->sortBy(static fn(Deposit $other): float => abs($at->diffInSeconds($other->received_at ?? $at)))
            ->first();

        return $told?->payment;
    }

    /**
     * Whether `$payment`, approved in the same moment this deposit was trying to, was approved by the other source's word
     * on the same transfer (twoSourced()) — matched to it, or being decided this moment — while no deposit of this one's
     * source names it.
     */
    private static function toldAtOnce(Deposit $deposit, Payment $payment): bool
    {
        if (Deposit::query()->where('payment_id', $payment->id)->where('source', $deposit->source)->exists()) {
            return false;
        }

        return self::twoSourced($deposit)
            ->where(static fn(Builder $by) => $by
                ->where('payment_id', $payment->id)
                ->orWhere(static fn(Builder $deciding) => $deciding->where('status', DepositStatus::Received->value)->whereNull('payment_id')))
            ->exists();
    }

    /**
     * The other sources' deposits that may be the same transfer as this one — both apps read the same bank SMS on the
     * shop's phone: the same sum in Rial, the SMS's time within SKEW_MINUTES of this one's.
     *
     * @return Builder<Deposit>
     */
    private static function twoSourced(Deposit $deposit): Builder
    {
        $at = $deposit->received_at ?? now();

        return Deposit::query()
            ->where('source', '!=', $deposit->source)
            ->where('amount_rial', $deposit->amount_rial)
            ->whereBetween('received_at', [$at->copy()->subMinutes(self::SKEW_MINUTES), $at->copy()->addMinutes(self::SKEW_MINUTES)]);
    }

    private static function kept(DepositSource $source, string $externalId): ?Deposit
    {
        return Deposit::query()->where('source', $source->key())->where('external_id', $externalId)->first();
    }

    /** The deposit `$in` kept, Received, to be decided; null when another request kept it in the same moment. */
    private static function keep(DepositSource $source, IncomingDeposit $in): ?Deposit
    {
        try {
            return Deposit::query()->create([
                'source' => $source->key(),
                'external_id' => $in->externalId,
                'status' => DepositStatus::Received,
                'amount_rial' => $in->amountRial,
                'bank' => $in->bank,
                'sender' => $in->sender,
                'body' => mb_substr($in->body, 0, Deposit::BODY_MAX),
                'received_at' => $in->receivedAt,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }
}
