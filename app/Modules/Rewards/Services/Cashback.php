<?php

declare(strict_types=1);

namespace App\Modules\Rewards\Services;

use App\Core\Database\Ledger;
use App\Core\Database\Transitions;
use App\Modules\Bots\CurrentBot;
use App\Modules\Discounts\Services\Loyalty;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Rewards\Models\Cashback as CashbackRow;
use App\Modules\Rewards\Models\CashbackRule;
use App\Modules\Users\Services\WalletService;
use App\Support\Money;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Cashback («کش‌بک»): part of what a delivered order paid comes back to the customer's wallet. While the switch is on, an
 * order bought for themselves earns the highest percent of the enabled rules it matches plus their loyalty tier's
 * percent (100 at most), rounded down to whole Toman. Earned once per order (`cashbacks.order_id` is unique) under the
 * order's row lock, which a refund takes too; a refund takes it back (takeBack()) by giving that much less.
 */
final class Cashback
{
    /** The wallet line of a cashback: «کش‌بک سفارش #12». */
    public const LINE = 'کش‌بک سفارش #%d';

    public function __construct(
        private readonly CashbackSettings $settings,
        private readonly Loyalty $loyalty,
        private readonly WalletService $wallet,
        private readonly CustomerNotifier $notifier,
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * The percent the delivered order earns (0: none): the best matching rule — its kind, the customer's group, the
     * method that paid — plus the customer's tier, 100 at most. Read in the order's shop.
     */
    public function percentFor(Order $order): int
    {
        return CurrentBot::run($order->shop(), function () use ($order): int {
            if (!$this->settings->enabled() || !$order->type->forThemselves() || !Money::isPositive($order->amount)) {
                return 0;
            }

            $order->loadMissing(['payments', 'user']);
            $method = $order->paidBy()?->payment_method_id;
            $groups = $order->user->groups()->pluck('customer_groups.id')->all();
            $rule = 0;
            foreach (CashbackRule::query()->where('enabled', true)->get() as $candidate) {
                if (in_array($order->type->value, $candidate->types(), true)
                    && ($candidate->customer_group_id === null || in_array($candidate->customer_group_id, $groups, true))
                    && ($candidate->payment_method_id === null || $candidate->payment_method_id === $method)) {
                    $rule = max($rule, $candidate->percent);
                }
            }

            return min(100, $rule + ($this->loyalty->tierOf($order->user)->cashback_percent ?? 0));
        });
    }

    /**
     * Credits the delivered order's cashback and tells the customer; null when it earns none, earned it already, or was
     * refunded meanwhile. Called after the delivery's commit, so the wallet's lock never holds a delivery up.
     */
    public function earn(Order $order): ?CashbackRow
    {
        $percent = $this->percentFor($order);
        $amount = Money::percentOf($order->amount, $percent);
        if (!Money::isPositive($amount)) {
            return null;
        }

        $cashback = CurrentBot::run($order->shop(), function () use ($order, $percent, $amount): ?CashbackRow {
            try {
                return $this->db->transaction(function () use ($order, $percent, $amount): ?CashbackRow {
                    // Still delivered: a refund in the same moment holds this lock first, and then there is nothing to earn.
                    if (Order::query()->whereKey($order->id)->lockForUpdate()->value('status') !== OrderStatus::Fulfilled) {
                        return null;
                    }
                    $row = CashbackRow::query()->create(['order_id' => $order->id, 'user_id' => $order->user_id, 'percent' => $percent, 'amount' => $amount]);
                    $this->wallet->credit($order->user, $amount, sprintf(self::LINE, $order->id));

                    return $row;
                }, Ledger::ATTEMPTS);
            } catch (UniqueConstraintViolationException) {
                return null;
            }
        });
        if ($cashback !== null) {
            $this->notifier->cashbackEarned($cashback, $order);
        }

        return $cashback;
    }

    /**
     * Marks the order's cashback as taken back (once) and returns its amount, for the refund to give that much less;
     * "0.00" when it earned none. Runs inside the refund's transaction, under its lock on the order.
     */
    public function takeBack(Order $order): string
    {
        return CurrentBot::run($order->shop(), static function () use ($order): string {
            $row = CashbackRow::query()->where('order_id', $order->id)->whereNull('reversed_at')->first();

            return $row !== null && Transitions::claim($row, 'reversed_at') ? Money::normalize($row->amount) : '0.00';
        });
    }
}
