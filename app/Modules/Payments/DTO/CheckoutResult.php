<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTO;

use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Enums\CheckoutOutcome;
use App\Modules\Payments\Models\Payment;

/**
 * What came of a checkout (Payments\Services\Checkout::pay()), for its door to show: the outcome and what it carries —
 * the order as it stands (settled, transfer, processing, refused), the payment awaiting its receipt and the card to
 * transfer to (transfer), the payment the gateway refused (refused), the wallet's shortfall (short). Asking an outcome for
 * what it does not carry is the door's mistake.
 */
final class CheckoutResult
{
    private function __construct(
        public readonly CheckoutOutcome $outcome,
        private readonly ?Order $order = null,
        private readonly ?Payment $payment = null,
        private readonly ?CardTransfer $card = null,
        private readonly ?Shortfall $shortfall = null,
    ) {}

    /** Paid at once: the order as it ended — fulfilled, or failed and waiting for support (refunded since, made again). */
    public static function settled(Order $order): self
    {
        return new self(CheckoutOutcome::Settled, $order);
    }

    /** A card to transfer to, and the payment that waits for the receipt — or has it, with support. */
    public static function transfer(Order $order, Payment $payment, CardTransfer $card): self
    {
        return new self(CheckoutOutcome::Transfer, $order, $payment, $card);
    }

    /** Paid, and its delivery under way. */
    public static function processing(Order $order): self
    {
        return new self(CheckoutOutcome::Processing, $order);
    }

    /** Paid or closed elsewhere in the same moment — or held back by the door —: nothing charged. */
    public static function closed(): self
    {
        return new self(CheckoutOutcome::Closed);
    }

    /** Made again with its key, its order cancelled since — by support, or unpaid in its time —: nothing charged. */
    public static function cancelled(): self
    {
        return new self(CheckoutOutcome::Cancelled);
    }

    /** The wallet cannot cover it: nothing ordered. */
    public static function short(Shortfall $shortfall): self
    {
        return new self(CheckoutOutcome::Short, shortfall: $shortfall);
    }

    /** The gateway said no as it was charged: the payment failed with why (its `note`), the order still open. */
    public static function refused(Order $order, Payment $payment): self
    {
        return new self(CheckoutOutcome::Refused, $order, $payment);
    }

    /** The order — of every outcome but closed, cancelled and short. */
    public function order(): Order
    {
        return $this->order ?? throw new \LogicException("A {$this->outcome->value} checkout carries no order.");
    }

    /** The payment: a transfer's, which the receipt is for; the one the gateway refused. */
    public function payment(): Payment
    {
        return $this->payment ?? throw new \LogicException("A {$this->outcome->value} checkout carries no payment.");
    }

    /** A transfer's card: its number, its holder, the method's note. */
    public function card(): CardTransfer
    {
        return $this->card ?? throw new \LogicException("A {$this->outcome->value} checkout carries no card.");
    }

    /** What a short wallet lacks. */
    public function shortfall(): Shortfall
    {
        return $this->shortfall ?? throw new \LogicException("A {$this->outcome->value} checkout carries no shortfall.");
    }
}
