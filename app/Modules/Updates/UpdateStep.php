<?php

declare(strict_types=1);

namespace App\Modules\Updates;

/**
 * An update's steps, one request each. A failed install takes itself back and stays at Install with its error, for
 * another try; RolledBack is a Done update the owner took back.
 */
enum UpdateStep: string
{
    case Download = 'download';
    case Extract = 'extract';
    case Preflight = 'preflight';
    case Install = 'install';
    case Done = 'done';
    case RolledBack = 'rolled_back';

    /** Whether the update is under way: a step of it still to take. */
    public function isUnderWay(): bool
    {
        return !in_array($this, [self::Done, self::RolledBack], true);
    }
}
