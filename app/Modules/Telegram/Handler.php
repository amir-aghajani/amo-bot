<?php

declare(strict_types=1);

namespace App\Modules\Telegram;

/** Reacts to a customer's update in a private chat; registered in routes/bot.php. */
interface Handler
{
    public function handle(Context $ctx): void;
}
