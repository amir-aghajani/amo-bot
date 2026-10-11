<?php

declare(strict_types=1);

namespace App\Modules\Rewards\Exceptions;

use App\Core\Exceptions\DomainRuleException;
use App\Modules\Rewards\Enums\WheelRefusal;
use Illuminate\Support\Carbon;

/** A spin refused before anything was spent: why, and — too soon — when the next may be, or — short — the balance. */
final class WheelRefusedException extends DomainRuleException
{
    private function __construct(
        public readonly WheelRefusal $reason,
        public readonly ?Carbon $nextAt = null,
        public readonly ?string $balance = null,
    ) {
        parent::__construct($reason->message());
    }

    public static function because(WheelRefusal $reason): self
    {
        return new self($reason);
    }

    public static function tooSoon(Carbon $nextAt): self
    {
        return new self(WheelRefusal::TooSoon, nextAt: $nextAt);
    }

    /** @param string $balance What the wallet holds, as the debit read it under its lock */
    public static function short(string $balance): self
    {
        return new self(WheelRefusal::Short, balance: $balance);
    }
}
