<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Gates;

use App\Modules\Telegram\Context;

/**
 * A check every update passes before it reaches a handler: the bot's master switch, phone verification, the
 * required channels. Gates are registered in routes/bot.php and run in that order; a bot admin passes them all.
 */
interface Gate
{
    /**
     * True lets the update through. False means the gate answered it (the "bot is off" notice, the
     * "share your number" prompt) and nothing else runs; the session is still saved, and a tap it left unanswered
     * is acknowledged.
     */
    public function pass(Context $ctx): bool;
}
