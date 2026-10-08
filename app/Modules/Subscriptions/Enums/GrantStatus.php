<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Enums;

/** Where a grant's part stands — and, read off its parts, the grant itself (Models\Grant::status()). */
enum GrantStatus: string
{
    case Running = 'running';
    case Done = 'done';
    case Cancelled = 'cancelled';
}
