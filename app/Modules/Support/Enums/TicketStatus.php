<?php

declare(strict_types=1);

namespace App\Modules\Support\Enums;

/**
 * Where a support ticket stands (`tickets.status`): open — waiting on support (the queue the panels count) —, answered —
 * support wrote last, waiting on the customer —, closed. A customer's message opens it again, support's answers it; either
 * side closes it, support reopens it (Support\Services\Tickets).
 */
enum TicketStatus: string
{
    case Open = 'open';

    case Answered = 'answered';

    case Closed = 'closed';
}
