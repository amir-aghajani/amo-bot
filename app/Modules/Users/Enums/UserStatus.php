<?php

declare(strict_types=1);

namespace App\Modules\Users\Enums;

/** A customer's standing: the bot answers a banned one only with BotText::Banned, and the website refuses them. */
enum UserStatus: string
{
    case Active = 'active';
    case Banned = 'banned';
}
