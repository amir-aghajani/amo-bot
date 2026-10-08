<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Broadcasts;

/**
 * How the admin's message goes out: a copy from the bot (media and formatting kept, the admin's link buttons under it)
 * or a forward ("Forwarded from" its first sender — a channel post keeps its channel; a forward takes no buttons).
 */
enum BroadcastMode: string
{
    case Copy = 'copy';
    case Forward = 'forward';
}
