<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\DTO;

use App\Modules\Providers\DTO\Expiry;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Services\ServiceTerms;

/**
 * What a free test account gets on a server, as the server's owner set it: its traffic, its term in hours, and whether
 * the term counts from the first connection ("on hold") or from the moment the test is made.
 */
final class TestTerms
{
    public function __construct(
        public readonly int $bytes,
        public readonly int $hours,
        public readonly bool $onHold,
    ) {}

    /** The terms a test on the server gets now. */
    public static function of(Server $server): self
    {
        return new self($server->test_traffic_bytes, $server->test_duration_hours, $server->test_on_hold);
    }

    /** The term as the panel takes it: counted from the first connection, or a deadline `hours` from now. */
    public function expiry(): Expiry
    {
        return $this->onHold
            ? Expiry::afterFirstUse($this->hours * ServiceTerms::HOUR)
            : Expiry::at(now()->addHours($this->hours)->toDateTimeImmutable());
    }
}
