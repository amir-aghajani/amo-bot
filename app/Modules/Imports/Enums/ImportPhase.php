<?php

declare(strict_types=1);

namespace App\Modules\Imports\Enums;

/**
 * Where an import stands. Reading stages the other bot's data, a step at a time; review waits for the owner's choices;
 * importing writes it, a step at a time; then it is done, or failed (reading what was no data of that bot's, say), or
 * the owner cancelled it. Only reading and importing go on by steps; one import at a time is in any of the first three.
 */
enum ImportPhase: string
{
    case Reading = 'reading';
    case Review = 'review';
    case Importing = 'importing';
    case Done = 'done';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /** Still under way: the installation's one import, which another cannot start beside. */
    public function open(): bool
    {
        return in_array($this, [self::Reading, self::Review, self::Importing], true);
    }
}
