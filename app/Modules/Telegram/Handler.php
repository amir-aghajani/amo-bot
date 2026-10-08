<?php

declare(strict_types=1);

namespace App\Modules\Telegram;

/**
 * Something that reacts to a Telegram update. Registered in routes/bot.php.
 */
interface Handler
{
    public function handle(Context $ctx): void;
}
