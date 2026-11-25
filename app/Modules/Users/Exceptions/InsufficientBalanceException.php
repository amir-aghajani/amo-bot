<?php

declare(strict_types=1);

namespace App\Modules\Users\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * A debit would take the balance below its floor (zero, minus an agent's credit, or support's deepest debt by hand).
 * `balance` is the balance it was decided on, read under the ledger's lock, for callers that word the refusal themselves.
 */
final class InsufficientBalanceException extends DomainRuleException
{
    public function __construct(public readonly string $balance)
    {
        parent::__construct('موجودی کیف پول کافی نیست.');
    }
}
