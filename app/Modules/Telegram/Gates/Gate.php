<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Gates;

use App\Modules\Telegram\Context;

/**
 * A check every private update passes before its handler (master switch, phone, channels), run in routes/bot.php's
 * order. A bot admin passes them all.
 */
interface Gate
{
    /** True lets the update through; false means the gate answered it itself and nothing else runs. */
    public function pass(Context $ctx): bool;
}
