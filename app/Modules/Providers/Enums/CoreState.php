<?php

declare(strict_types=1);

namespace App\Modules\Providers\Enums;

/** How a panel's proxy core is doing, as the servers screen shows it. */
enum CoreState: string
{
    case Running = 'running';
    case Stopped = 'stopped';
    case Error = 'error';
    /** The panel does not say (its cores run on nodes the shop does not read). */
    case Unknown = 'unknown';
}
