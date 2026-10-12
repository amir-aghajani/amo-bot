<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Enums;

/** Where a free test account stands, as the panels' list shows it and filters by it (Services\TestAccountDirectory). */
enum TestState: string
{
    /** Claimed, its panel still being asked for the client. */
    case Making = 'making';
    /** Made on hold: its hours start at the customer's first connection. */
    case Waiting = 'waiting';
    /** In use: its clock runs. */
    case Running = 'running';
    /** Switched off by support. */
    case Disabled = 'disabled';
    /** Its time or traffic ran out, or its panel no longer has it; the clean-up removes it in time. */
    case Ended = 'ended';
    /** Removed from its panel and the shop; the row stays, so the quota still counts it. */
    case Removed = 'removed';
}
