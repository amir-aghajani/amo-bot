<?php

declare(strict_types=1);

namespace App\Modules\Referrals\Services;

use App\Core\Database\Ledger;
use App\Core\Security\ReadableCode;
use App\Modules\Bots\Services\Bots;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Referrals\Models\ReferralCommission;
use App\Modules\Referrals\Models\ReferralReward;
use App\Modules\Users\Exceptions\InsufficientBalanceException;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\WalletService;
use App\Support\Money;
use App\Support\Persian;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Psr\Container\ContainerInterface;

/**
 * The referral program («زیرمجموعه‌گیری»): invite codes and links, attributing a newcomer to a referrer, and the
 * commission a referred customer's payment earns (once per payment). Only money that came in earns it — a wallet payment
 * spends money already counted when the wallet was charged —, and only money that leaves the shop again takes it back:
 * a top-up's refund (refundTakesBack()). Start gifts, plan rewards and alerts are ReferralGifts'.
 */
final class ReferralService
{
    /** What a referral link carries after /start: `ref_<code>`. */
    public const PAYLOAD = 'ref_';

    /** The referrer's wallet line of a commission its top-up's refund took back, by the payment's number. */
    public const LINE_TAKEN_BACK = 'برگشت پورسانت زیرمجموعه (پرداخت #%d)';

    /** An invite code's length: ReadableCode's characters, in lower case (no look-alikes: 0/o, 1/l/i). */
    private const CODE_LENGTH = 8;

    public function __construct(
        private readonly ReferralSettings $settings,
        private readonly WalletService $wallet,
        private readonly ConnectionInterface $db,
        private readonly Bots $bots,
        /**
         * ReferralGifts is resolved when needed: it gives through Rewards → PaymentService, whose settlement calls this
         * service, so injecting it would be a constructor loop.
         */
        private readonly ContainerInterface $container,
    ) {}

    /** The customer's invite code, made the first time it is asked for. */
    public function codeFor(User $user): string
    {
        while ($user->referral_code === null) {
            $code = strtolower(ReadableCode::make(self::CODE_LENGTH));
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
     * Makes a just-registered customer the referral of the invite code's owner (a link's /start, or the website's
     * sign-in), while the program runs and the owner is not banned; returns the referrer. Only newcomers become referrals
     * — Users\Services\Customers calls this only for a customer it has just created.
     */
    public function attribute(User $newcomer, ?string $code): ?User
    {
        // As a code is typed by hand on a sign-up form: any case, Persian digits.
        $code = strtolower(Persian::latinDigits(trim($code ?? '')));
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

    /** A newcomer joined through the referrer's link (ReferralGifts::joined()). */
    public function joined(User $newcomer, User $referrer): void
    {
        $this->container->get(ReferralGifts::class)->joined($newcomer, $referrer);
    }

    /** A payment settled, after its commit (ReferralGifts::purchased()). */
    public function purchased(Payment $payment): void
    {
        $this->container->get(ReferralGifts::class)->purchased($payment);
    }

    /**
     * Credits the commission a settled payment earns the customer's referrer (not banned), at the rate for its kind;
     * null when it earns nothing. Once per payment (the row's `payment_id` is unique): the row and the wallet credit are
     * one transaction, under a lock on the payment — a refund at the same moment either came first, and nothing is
     * earned, or waits, and a top-up's then finds the commission to take back (takeBack()).
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
        $rate = $payment->order->type === OrderType::Renewal ? $this->settings->renewalRate() : $this->settings->rate();
        $commission = Money::percentOf($payment->amount, $rate);
        if (!Money::isPositive($commission)) {
            return null;
        }

        try {
            return $this->db->transaction(function () use ($payment, $customer, $referrer, $rate, $commission): ?ReferralCommission {
                // The payment first, as a refund takes it: refunded meanwhile, it earns nothing.
                if (Payment::query()->whereKey($payment->id)->lockForUpdate()->value('status') !== PaymentStatus::Paid) {
                    return null;
                }
                // Under a lock on the customer: of two of their payments settled in the same moment, the first-only rule
                // sees the commission the other one just earned.
                User::query()->whereKey($customer->id)->lockForUpdate()->value('id');
                if ($this->settings->firstOnly() && self::notFirst($customer, $payment)) {
                    return null;
                }

                $row = ReferralCommission::query()->create(['payment_id' => $payment->id, 'referrer_id' => $referrer->id, 'rate' => $rate, 'commission' => $commission]);
                $this->wallet->credit($referrer, $commission, WalletService::describeReferral($payment));

                return $row;
            }, Ledger::ATTEMPTS);
        } catch (UniqueConstraintViolationException) {
            return null; // the payment's commission is there already
        }
    }

    /**
     * Whether the order's refund takes back the commission its payment earned: a top-up's only — support returns its
     * money outside the shop, so it leaves the shop. A refunded purchase, renewal, extra or agent's traffic gives its
     * money back to the customer's wallet: it stays in the shop, and its commission stands (the rule the shop's takings
     * are counted by, Payment::moneyIn()).
     */
    public static function refundTakesBack(Order $order): bool
    {
        return $order->type === OrderType::WalletTopUp;
    }

    /**
     * The commission the payment earned, with its referrer; null for none. A top-up's refund reads it first (Payments\
     * Services\PaymentActions::refund()): whom to tell after takeBack() deleted it.
     */
    public function commissionOf(Payment $payment): ?ReferralCommission
    {
        return ReferralCommission::query()->with('referrer')->where('payment_id', $payment->id)->first();
    }

    /**
     * Takes back the commission the payment earned, inside its top-up's refund's transaction (refundTakesBack(); Payments\
     * Services\PaymentService::refund()): out of the referrer's wallet as a line of its own (LINE_TAKEN_BACK) — below
     * zero only as far as an agent's credit, the rule the refunded top-up comes back out of its customer's wallet by —,
     * and its row deleted, so the referrer's earnings and the first-payment rule no longer count it. True when there was
     * one.
     *
     * @throws InsufficientBalanceException the referrer spent it: nothing taken
     */
    public function takeBack(Payment $payment): bool
    {
        $commission = ReferralCommission::query()->with('referrer')->where('payment_id', $payment->id)->lockForUpdate()->first();
        if ($commission === null) {
            return false;
        }
        $referrer = $commission->referrer;
        $this->wallet->debit($referrer, $commission->commission, sprintf(self::LINE_TAKEN_BACK, $payment->id), $referrer->credit());
        $commission->delete();

        return true;
    }

    /**
     * How many customers their link brought and what those earned them.
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

    /** How many plans the customer was given free for their invitations («جایزه دعوت»: the milestones given, not withheld). */
    public function rewardsOf(User $user): int
    {
        return ReferralReward::query()->where('referrer_id', $user->id)->whereNotNull('order_id')->count();
    }

    /**
     * Whether the payment is not the customer's first money in: another came in before it (by `paid_at`, then id), or
     * one of theirs already earned a commission.
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
}
