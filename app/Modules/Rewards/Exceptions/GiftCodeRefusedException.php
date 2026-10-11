<?php

declare(strict_types=1);

namespace App\Modules\Rewards\Exceptions;

use App\Core\Exceptions\DomainRuleException;
use App\Modules\Rewards\Enums\GiftCodeRefusal;

/** A gift code the customer cannot redeem (422 on `code`); `reason` lets the bot use its own text. */
final class GiftCodeRefusedException extends DomainRuleException
{
    public function __construct(public readonly GiftCodeRefusal $reason, ?string $message = null)
    {
        parent::__construct($message ?? $reason->message());
    }

    protected function field(): string
    {
        return 'code';
    }
}
