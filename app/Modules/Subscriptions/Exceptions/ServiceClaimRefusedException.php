<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Exceptions;

use App\Core\Exceptions\DomainRuleException;
use App\Modules\Subscriptions\Enums\ClaimRefusal;
use App\Modules\Subscriptions\Models\Subscription;

/**
 * A service sent by its link that was not added to the customer's account: why, under `link` — and, when it is theirs
 * already, which of their services it is.
 */
final class ServiceClaimRefusedException extends DomainRuleException
{
    private function __construct(
        public readonly ClaimRefusal $reason,
        public readonly ?Subscription $service = null,
    ) {
        parent::__construct($reason->message());
    }

    public static function because(ClaimRefusal $reason): self
    {
        return new self($reason);
    }

    /** The customer's own service `$service` already. */
    public static function yours(Subscription $service): self
    {
        return new self(ClaimRefusal::Yours, $service);
    }

    public function status(): int
    {
        return $this->reason->status();
    }

    protected function field(): string
    {
        return 'link';
    }
}
