<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use App\Core\Exceptions\DomainRuleException;
use App\Modules\Orders\Models\Order;

/**
 * A payment succeeded for an order that is not pending any more — paid by another payment in the same moment (two
 * taps, two approvals), or cancelled meanwhile — or a payment was started for one. Thrown inside PaymentService's
 * settlement transaction, so the payment's move and the gateway's work (a wallet debit) unwind with it: the order is
 * paid once and no orphan paid payment is left behind. The message is what the customer or the admin is told — on the
 * screen, a refusal of the order's state.
 */
final class OrderNotPayableException extends DomainRuleException
{
    public function __construct(Order $order)
    {
        parent::__construct("سفارش #{$order->id} دیگر باز نیست؛ یا قبلا پرداخت شده یا لغو شده است.");
    }

    protected function field(): string
    {
        return 'status';
    }
}
