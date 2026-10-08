<?php

declare(strict_types=1);

namespace App\Modules\Referrals\Services;

use App\Modules\Bots\Services\Bots;
use App\Modules\Payments\Models\Payment;
use App\Modules\Referrals\Models\ReferralCommission;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\WalletService;
use App\Support\Money;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The referral program («زیرمجموعه‌گیری»): a customer's invite code and link, whose link brought a newcomer, and the
 * commission a referred customer's payment earns the one who brought them — credited to that one's wallet, once per
 * payment. Only money that came in earns it: a card-to-card or gateway payment of a purchase, a renewal or a wallet
 * top-up; a purchase paid from the wallet spends money that was counted when the wallet was charged. A refund leaves
 * the commission standing: it was earned for bringing a customer who paid — a purchase's money stays in the shop as
 * the customer's balance, and giving a top-up back is the shop's own call.
 */
final class ReferralService
{
    /** What a referral link carries after /start: `ref_<code>`. */
    public const PAYLOAD = 'ref_';

    /** Invite codes: lower case, without the characters that look alike (0/o, 1/l/i). */
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';
    private const CODE_LENGTH = 8;

    public function __construct(
        private readonly ReferralSettings $settings,
        private readonly WalletService $wallet,
        private readonly ConnectionInterface $db,
        private readonly Bots $bots,
    ) {}

    /** The customer's invite code, made the first time it is asked for. */
    public function codeFor(User $user): string
    {
        while ($user->referral_code === null) {
            $code = self::randomCode();
            try {
                // Two taps at once: whichever code lands first is the customer's; another customer's code is refused by the index.
                User::query()->whereKey($user->id)->whereNull('referral_code')->update(['referral_code' => $code]);
            } catch (UniqueConstraintViolationException) {
                continue;
            }
            $user->refresh();
        }

        return $user->referral_code;
    }

    /** The link that brings a friend — t.me/<bot>?start=ref_<code> — or null while the bot's @username is not known yet. */
    public function linkFor(User $user): ?string
    {
        $bot = $this->bots->username();

        return $bot === '' ? null : "https://t.me/{$bot}?start=" . self::PAYLOAD . $this->codeFor($user);
    }

    /** The invite code a referral link's /start carries (`ref_<code>`, what follows the command); null for anything else. */
    public static function codeOf(?string $payload): ?string
    {
        return $payload !== null && str_starts_with($payload, self::PAYLOAD) ? substr($payload, strlen(self::PAYLOAD)) : null;
    }

    /**
     * A customer the shop has just registered — not in the database a moment ago — who came with someone's invite code
     * (a referral link's /start — codeOf() —, or the website's sign-in) becomes that someone's referral: while the
     * program runs, and never a banned customer's. The code is read whatever its case. A customer the shop already knew
     * never becomes anyone's referral; the caller (Users\Services\Customers) asks only for the one it has just created.
     * Returns the referrer.
     */
    public function attribute(User $newcomer, ?string $code): ?User
    {
        $code = strtolower(trim($code ?? ''));
        if ($code === '' || $newcomer->referred_by !== null || !$this->settings->enabled()) {
            return null;
        }

        $referrer = User::query()->where('referral_code', $code)->first();
        if ($referrer === null || $referrer->id === $newcomer->id || $referrer->isBanned()) {
            return null;
        }

        $newcomer->forceFill(['referred_by' => $referrer->id])->save();

        return $referrer;
    }

    /**
     * The commission a settled payment earns its customer's referrer: for money that came in (Payment::moneyIn()), while
     * the program runs, never to a banned referrer, only for the customer's first such payment when the admin said so —
     * and once, the payment's row being unique: the row, which keeps whom it was credited to, and the wallet credit go in
     * one transaction. Null when it earns nothing.
     */
    public function reward(Payment $payment): ?ReferralCommission
    {
        if (!$this->settings->enabled() || !$payment->isPaid() || $payment->isFromWallet()) {
            return null;
        }

        $customer = $payment->order->user;
        $referrer = $customer->referrer;
        if ($referrer === null || $referrer->isBanned()) {
            return null;
        }
        $rate = $this->settings->rate();
        $commission = Money::percentOf($payment->amount, $rate);
        if (!Money::isPositive($commission)) {
            return null;
        }

        try {
            return $this->db->transaction(function () use ($payment, $customer, $referrer, $rate, $commission): ?ReferralCommission {
                // Under a lock on the customer: of two of their payments settled in the same moment, the first-only rule
                // sees the commission the other one just earned.
                User::query()->whereKey($customer->id)->lockForUpdate()->value('id');
                if ($this->settings->firstOnly() && self::notFirst($customer, $payment)) {
                    return null;
                }

                $row = ReferralCommission::query()->create(['payment_id' => $payment->id, 'referrer_id' => $referrer->id, 'rate' => $rate, 'commission' => $commission]);
                $this->wallet->credit($referrer, $commission, WalletService::describeReferral($payment));

                return $row;
            });
        } catch (UniqueConstraintViolationException) {
            return null; // the payment's commission is there already
        }
    }

    /**
     * The customer's own numbers — the bot's screen, the website's, their page in the panels: how many their link
     * brought, and what was credited to them for those (the commissions kept as theirs).
     *
     * @return array{referrals: int, earned: string}
     */
    public function statsFor(User $user): array
    {
        $earned = ReferralCommission::query()->where('referrer_id', $user->id)->sum('commission');

        return [
            'referrals' => User::query()->where('referred_by', $user->id)->count(),
            'earned' => Money::normalize(is_numeric($earned) ? (string) $earned : '0'),
        ];
    }

    /**
     * Whether the payment is not the customer's first money in (the first-payment rule): money of theirs came in before
     * it — paid earlier, or in the same second with a smaller number —, or one of their payments earned a commission
     * already.
     */
    private static function notFirst(User $customer, Payment $payment): bool
    {
        $paidAt = $payment->paid_at ?? throw new \LogicException("Payment #{$payment->id} is paid without a time.");
        $earlier = Payment::moneyIn()
            ->whereRelation('order', 'user_id', $customer->id)
            ->whereKeyNot($payment->id)
            ->where(static fn(Builder $before) => $before
                ->where('paid_at', '<', $paidAt)
                ->orWhere(static fn(Builder $tie) => $tie->where('paid_at', $paidAt)->where('id', '<', $payment->id)))
            ->exists();

        return $earlier || ReferralCommission::withPayer()->where(ReferralCommission::PAYER . '.id', $customer->id)->exists();
    }

    private static function randomCode(): string
    {
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }
}
