<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Enums;

/** Whose services a grant is for. */
enum GrantAudience: string
{
    /** Every service, on every server. */
    case All = 'all';
    /** The services the agents' bots sold, on every server. */
    case Agents = 'agents';
    /** The services on one server (its page's «افزودن زمان و حجم», or the broadcasts page's pick). */
    case Server = 'server';
}
