<?php

declare(strict_types=1);

namespace App\Modules\Users\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * Raised by WalletService when a debit would take the balance below what it may go to (zero, or an agent's credit
 * below it). The message is what the customer reads when the wallet pays (the payment's refusal); `balance` is the
 * balance the refusal was decided on — read under the ledger's lock — for wording it elsewhere.
 */
final class InsufficientBalanceException extends DomainRuleException
{
    public function __construct(public readonly string $balance)
    {
        parent::__construct('موجودی کیف پول کافی نیست.');
    }
}
