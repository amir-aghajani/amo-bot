<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Broadcasts;

/** A broadcast's run: an admin's message to an audience, or the pins of an earlier one taken off again. */
enum BroadcastKind: string
{
    case Message = 'message';
    case Unpin = 'unpin';
}
