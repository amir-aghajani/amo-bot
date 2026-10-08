<?php

declare(strict_types=1);

namespace App\Modules\Bots\Enums;

/** Whether a bot runs: an agent's goes off when their agency ends, and on again when it is given back. */
enum BotStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}
