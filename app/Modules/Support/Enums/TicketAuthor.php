<?php

declare(strict_types=1);

namespace App\Modules\Support\Enums;

/** Who wrote a ticket's message (`ticket_messages.author`): the customer, or support — who of support is its `reviewer`. */
enum TicketAuthor: string
{
    case Customer = 'customer';

    case Support = 'support';
}
