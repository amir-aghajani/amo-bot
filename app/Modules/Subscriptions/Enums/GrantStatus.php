<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Enums;

/** Where a grant's part stands; a grant's own status is read off its parts (Models\Grant::status()). */
enum GrantStatus: string
{
    case Running = 'running';
    case Done = 'done';
    case Cancelled = 'cancelled';
}
