<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Exceptions;

use App\Core\Exceptions\DomainRuleException;
use App\Modules\Subscriptions\Enums\TestRefusal;
use Illuminate\Support\Carbon;

/** A free test refused before anything was made: why, and — a quota with a window — when the next one may be taken. */
final class TestAccountRefusedException extends DomainRuleException
{
    private function __construct(
        public readonly TestRefusal $reason,
        public readonly ?Carbon $nextAt = null,
    ) {
        parent::__construct($reason->message());
    }

    public static function because(TestRefusal $reason): self
    {
        return new self($reason);
    }

    /** The quota is used up; `$nextAt` is when a test leaves its window (null: for good). */
    public static function used(?Carbon $nextAt): self
    {
        return new self(TestRefusal::Used, $nextAt);
    }
}
