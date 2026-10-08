<?php

declare(strict_types=1);

namespace App\Core\Database;

/** Which way a sorted list runs (Sort): the smallest, the oldest, the soonest first — or the largest, the newest. */
enum SortDirection: string
{
    case Asc = 'asc';
    case Desc = 'desc';
}
