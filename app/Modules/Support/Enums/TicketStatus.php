<?php

declare(strict_types=1);

namespace App\Modules\Support\Enums;

/**
 * Where a support ticket stands (`tickets.status`): open (waiting on support — the queue the panels count), answered
 * (support wrote last, waiting on the customer), or closed.
 */
enum TicketStatus: string
{
    case Open = 'open';

    case Answered = 'answered';

    case Closed = 'closed';
}
