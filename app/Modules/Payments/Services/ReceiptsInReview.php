<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Bots\CurrentBot;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\ReceiptInReviewException;
use App\Modules\Payments\GatewayRegistry;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Users\Models\User;
use App\Support\Money;
use Illuminate\Database\ConnectionInterface;

/**
 * One receipt at a time (PaymentSettings::oneReceiptAtATime()): a customer whose receipt waits for support's review
 * sends no other receipt (the bot's ReceiptHandler, the website's CustomerReceipts) and starts no other card payment that
 * would need one (Checkout::pay(), both doors) until it is decided — told plainly that the last one is being reviewed.
 */
final class ReceiptsInReview
{
    public function __construct(
        private readonly PaymentSettings $settings,
        private readonly GatewayRegistry $gateways,
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * Refuse a receipt for `$payment` while another of the customer's waits for review — asked before the receipt is
     * looked at; submit() asks again as it is taken.
     *
     * @throws ReceiptInReviewException
     */
    public function allowReceipt(User $customer, Payment $payment): void
    {
        self::refuse($this->blocking($customer), $payment);
    }

    /**
     * Take the receipt for `$payment` by `$take` (PaymentService::submitReceipt() or submitUpload(), true when it took
     * it) while no other of the customer's waits for review: asked under a lock on the customer's row, so of two receipts
     * sent at once to two payments (the bot and the website, an album) the second finds the first in review.
     *
     * @param \Closure(): bool $take
     * @throws ReceiptInReviewException nothing taken
     */
    public function submit(User $customer, Payment $payment, \Closure $take): bool
    {
        if (!$this->settings->oneReceiptAtATime()) {
            return $take();
        }

        return $this->db->transaction(function () use ($customer, $payment, $take): bool {
            User::query()->withoutGlobalScope(CurrentBot::SCOPE)->whereKey($customer->id)->lockForUpdate()->first();
            self::refuse($this->oldest($customer, locking: true), $payment);

            return $take();
        });
    }

    /**
     * Refuse a card payment of `$price` with `$method` that would wait for a receipt while one of the customer's is in
     * review — not a free order, nor while the shop takes card payments in support's private chat (no receipt here then).
     * `$order` is a website request's order made again with its key: its own receipt in review lets it be answered as
     * before.
     *
     * Asked without a lock, before anything is ordered: what a receipt taken at the same moment could leave — a card
     * payment waiting for its transfer while a receipt of the customer's is in review — they reach without one too, by
     * starting two card payments before sending the first receipt. The rule itself, one receipt in review, is submit()'s,
     * under the customer's lock: the second payment's receipt waits for the first's verdict either way.
     *
     * @throws ReceiptInReviewException
     */
    public function allowCard(User $customer, PaymentMethod $method, string $price, ?Order $order = null): void
    {
        if (!$this->gateways->isManual($method) || !Money::isPositive($price) || $this->settings->sendsToSupport()) {
            return;
        }
        $blocking = $this->blocking($customer);
        if ($blocking !== null && $blocking->order_id !== $order?->id) {
            throw new ReceiptInReviewException($blocking);
        }
    }

    /** The customer's payment whose receipt waits for review — the oldest —, while the shop takes one at a time; null otherwise. */
    private function blocking(User $customer): ?Payment
    {
        return $this->settings->oneReceiptAtATime() ? $this->oldest($customer, locking: false) : null;
    }

    /**
     * The customer's oldest payment whose receipt waits for review; `$locking` reads it as committed this moment (under
     * MySQL's repeatable read a plain read could see a snapshot from before another receipt was taken).
     */
    private function oldest(User $customer, bool $locking): ?Payment
    {
        $query = Payment::query()
            ->where('status', PaymentStatus::AwaitingReview->value)
            ->whereRelation('order', 'user_id', $customer->id)
            ->oldest('receipt_at');

        return ($locking ? $query->lockForUpdate() : $query)->first();
    }

    /** @throws ReceiptInReviewException `$blocking` is another payment than `$payment` */
    private static function refuse(?Payment $blocking, Payment $payment): void
    {
        if ($blocking !== null && $blocking->id !== $payment->id) {
            throw new ReceiptInReviewException($blocking);
        }
    }
}
