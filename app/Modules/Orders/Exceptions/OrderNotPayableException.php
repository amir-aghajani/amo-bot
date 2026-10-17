<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use App\Core\Exceptions\DomainRuleException;
use App\Modules\Orders\Models\Order;

/**
 * A payment was settled or started for an order that is no longer pending (paid by another payment at the same moment,
 * or cancelled). Thrown inside the settlement's transaction, so the payment's move and a wallet debit roll back with it:
 * an order is paid once, and no orphan paid payment is left.
 */
final class OrderNotPayableException extends DomainRuleException
{
    public function __construct(Order $order)
    {
        // A left-to-right mark before the number: in the report group's Persian popup «#12» would read «12#».
        parent::__construct("سفارش \u{200E}#{$order->id} دیگر باز نیست؛ یا قبلا پرداخت شده یا لغو شده است.");
    }

    protected function field(): string
    {
        return 'status';
    }
}
