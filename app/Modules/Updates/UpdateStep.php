<?php

declare(strict_types=1);

namespace App\Modules\Updates;

/**
 * Where an update stands, a request a step: its files fetched and checked, unpacked, the host checked, installed — then
 * done, or taken back by the owner afterwards (RolledBack). An install that failed is taken back by itself and stays at
 * Install, its error said, for another try.
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
