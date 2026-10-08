<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Enums;

/** Where a move to another server stopped (Exceptions\MoveException). */
enum MoveStep: string
{
    /** The server it is on: out of reach, or it refused to let the client go. */
    case Previous = 'previous';
    /** The server it was going to: it could not make the client. */
    case Target = 'target';
    /** The service itself: gone from its panel, ended or used up by the time it was read. */
    case Service = 'service';
}
