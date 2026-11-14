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
 * «تایید خودکار واریز»: each bank SMS a phone app forwards (Contracts\DepositSource: ForApp) is kept once, by the app's id
 * for it, and its sum looked for among the current shop's open card payments (PaymentCards gives each a sum of its own).
 * Exactly one, and it is approved as support would (REVIEWER); none or several, and the report group is told: money never
 * goes to the wrong order on a guess.
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

    private const APPROVED_ALREADY = 'این پرداخت پیش‌تر تایید شده بود؛ پیامک واریزش را تایید کرد.';

    public function __construct(
        private readonly PaymentActions $actions,
        private readonly ShopReports $reports,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * The SMS `$in`, from `$source`, taken once: what came of it (an SMS sent again, what came of it then — or, left
     * Received by a process that died midway, decided now).
     */
    public function take(DepositSource $source, IncomingDeposit $in): Deposit
    {
        $deposit = self::kept($source, $in->externalId);
        if ($deposit !== null && !Transitions::reclaim($deposit, 'status', DepositStatus::Received, self::TIME_LIMIT)) {
            return $deposit;
        }
        if ($deposit === null) {
            try {
                $deposit = Deposit::query()->create([
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
                // The app sent it twice at once: the other request has it.
                return self::kept($source, $in->externalId) ?? throw new \LogicException('A deposit vanished as it was taken.');
            }
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
     * What came of the SMS, the payment it approved or confirmed, and the payments asking its sum when several do. Only
     * a deposit ForApp recognised as a bank's is matched; one it could not tell is support's.
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
        if ($in->isDeposit !== true || $in->bank === null) {
            return [DepositStatus::Unmatched, 'فوراپ این پیامک را واریز یک بانک تشخیص نداد؛ چیزی خودکار تایید نشد.', null, []];
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
            // Decided otherwise in the same moment: approved by support confirms it; anything else is support's.
            $payment->refresh();
            if ($payment->status === PaymentStatus::Paid && self::confirmable(Payment::query()->whereKey($payment->id))->exists()) {
                return [DepositStatus::Matched, self::APPROVED_ALREADY, $payment, []];
            }

            return [DepositStatus::Unmatched, sprintf("پرداخت \u{200E}#%d همان لحظه به شکل دیگری بسته شد.", $payment->id), null, []];
        }

        return [DepositStatus::Matched, null, $payment->refresh(), []];
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

    private static function kept(DepositSource $source, string $externalId): ?Deposit
    {
        return Deposit::query()->where('source', $source->key())->where('external_id', $externalId)->first();
    }
}
