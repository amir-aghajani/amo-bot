<?php

declare(strict_types=1);

namespace App\Modules\Providers\Enums;

/** Why a panel would refuse a client's name (DTO\ClientNameRule::problem()). */
enum NameProblem: string
{
    /** Shorter or longer than the panel takes. */
    case Length = 'length';
    /** A character the panel does not take. */
    case Characters = 'characters';
    /** Two separators (`_ - . @`) side by side. */
    case Runs = 'runs';
}
