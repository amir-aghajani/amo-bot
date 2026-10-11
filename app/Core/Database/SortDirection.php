<?php

declare(strict_types=1);

namespace App\Core\Database;

/** Which way a sorted list runs: ascending (smallest, oldest first) or descending. */
enum SortDirection: string
{
    case Asc = 'asc';
    case Desc = 'desc';
}
