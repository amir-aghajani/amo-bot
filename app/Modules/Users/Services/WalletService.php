<?php

declare(strict_types=1);

namespace App\Modules\Users\Services;

use App\Core\Database\Ledger;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\Payment;
use App\Modules\Users\Enums\WalletTransactionType;
use App\Modules\Users\Exceptions\InsufficientBalanceException;
use App\Modules\Users\Models\User;
use App\Modules\Users\Models\WalletTransaction;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;

/**
 * The one writer of the wallet ledger (through Core\Database\Ledger, under the customer's row lock, so two debits at once
 * cannot spend the same balance). Each line carries the balance after it. A debit may go below zero only as far as the
 * credit the caller allows (an agent's). Lines written by hand keep their reviewer. Most lines' wording lives here too.
 */
final class WalletService
{
    public const LINE_TOP_UP = 'شارژ کیف پول (سفارش #%d)';
    public const LINE_PAYMENT = 'پرداخت سفارش #%d (%s)';
    public const LINE_REFUND = 'بازگشت وجه پرداخت #%d';
    /** After a refund's line, when the cashback its order earned was taken out of it (Rewards' Cashback). */
    public const LESS_CASHBACK = ' (منهای کش‌بک)';
    public const LINE_TOP_UP_REFUND = 'برگشت شارژ کیف پول (پرداخت #%d)';
    public const LINE_REFERRAL = 'پورسانت زیرمجموعه (پرداخت #%d)';
    /** A manual credit or debit the admin wrote no note for. */
    public const LINE_CREDIT_BY_SUPPORT = 'افزایش موجودی توسط پشتیبانی';
    public const LINE_DEBIT_BY_SUPPORT = 'کاهش موجودی توسط پشتیبانی';

    public function __construct(private readonly Ledger $ledger) {}

    /** @param string|null $reviewer Who writes it by hand (support's adjustment); null for lines the shop writes itself */
    public function credit(User $user, string|int $amount, string $description, ?string $reviewer = null): WalletTransaction
    {
        return $this->apply(WalletTransactionType::Credit, $user, $amount, $description, 0, $reviewer);
    }

    /**
     * @param string|int $credit How far below zero the balance may go (an agent's credit; 0 for anyone else)
     * @param string|null $reviewer Who writes it by hand (support's adjustment); null for lines the shop writes itself
     * @throws InsufficientBalanceException
     */
    public function debit(User $user, string|int $amount, string $description, string|int $credit = 0, ?string $reviewer = null): WalletTransaction
    {
        return $this->apply(WalletTransactionType::Debit, $user, $amount, $description, $credit, $reviewer);
    }

    /**
     * Merges `$merged`'s ledger into the survivor's (Accounts\Services\AccountMerger): every balance is recounted in line
     * order, so the last is the two balances together. Returns how many lines moved.
     */
    public function takeOver(User $survivor, User $merged): int
    {
        return $this->ledger->merge($survivor, $merged, 'wallet_transactions', 'user_id', static fn(string $balance, \stdClass $line): string => $line->type === WalletTransactionType::Credit->value
            ? Money::add($balance, (string) $line->amount)
            : Money::subtract($balance, (string) $line->amount));
    }

    /** @return Collection<int, WalletTransaction> The customer's latest `$limit` lines, newest first. */
    public function lines(User $user, int $limit): Collection
    {
        return WalletTransaction::query()->where('user_id', $user->id)->latest('id')->limit($limit)->get();
    }

    /** The line of a top-up order landing: «شارژ کیف پول (سفارش #12)». */
    public static function describeTopUp(Order $order): string
    {
        return sprintf(self::LINE_TOP_UP, $order->id);
    }

    /** The line of paying an order from the wallet: «پرداخت سفارش #12 (خرید)». */
    public static function describePayment(Payment $payment): string
    {
        return sprintf(self::LINE_PAYMENT, $payment->order->id, $payment->order->type->label());
    }

    /** The line of a referral commission: «پورسانت زیرمجموعه (پرداخت #12)» — the payment of the customer it came from. */
    public static function describeReferral(Payment $payment): string
    {
        return sprintf(self::LINE_REFERRAL, $payment->id);
    }

    /**
     * The line of a refund: «بازگشت وجه پرداخت #12 — <the admin's note>» — «… (منهای کش‌بک) …» when the cashback its order
     * earned was taken out of it.
     */
    public static function describeRefund(Payment $payment, ?string $note, bool $lessCashback = false): string
    {
        return sprintf(self::LINE_REFUND, $payment->id) . ($lessCashback ? self::LESS_CASHBACK : '') . self::noted($note);
    }

    /** The line of a top-up taken back by its refund: «برگشت شارژ کیف پول (پرداخت #12) — <the admin's note>». */
    public static function describeTopUpRefund(Payment $payment, ?string $note): string
    {
        return sprintf(self::LINE_TOP_UP_REFUND, $payment->id) . self::noted($note);
    }

    private static function noted(?string $note): string
    {
        return $note !== null ? " — {$note}" : '';
    }

    private function apply(WalletTransactionType $type, User $user, string|int $amount, string $description, string|int $credit, ?string $reviewer): WalletTransaction
    {
        $amount = Money::normalize($amount);
        if (!Money::isPositive($amount)) {
            throw new \InvalidArgumentException('A wallet line moves a positive amount.');
        }
        $floor = Money::subtract(0, $credit);

        return $this->ledger->append($user, 'wallet_transactions', 'user_id', static function (string $last) use ($type, $user, $amount, $description, $floor, $reviewer): WalletTransaction {
            $current = Money::normalize($last);
            $balance = $type === WalletTransactionType::Credit ? Money::add($current, $amount) : Money::subtract($current, $amount);

            // A credit only adds — it may land below zero still, a debt being paid off.
            if ($type === WalletTransactionType::Debit && Money::compare($balance, $floor) < 0) {
                throw new InsufficientBalanceException($current);
            }

            // The customer's shop's line, whichever shop the work is done in.
            return WalletTransaction::query()->forceCreate([
                'bot_id' => $user->bot_id,
                'user_id' => $user->id,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $balance,
                'description' => $description,
                'reviewer' => $reviewer,
            ]);
        });
    }
}
