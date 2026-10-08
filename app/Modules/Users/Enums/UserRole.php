<?php

declare(strict_types=1);

namespace App\Modules\Users\Enums;

/**
 * What a customer may do inside the bot. Admins get the privileged commands (/broadcast) and are exempt
 * from the bot's rules (bot off, phone, channels). This has nothing to do with the panel's login.
 */
enum UserRole: string
{
    case Customer = 'customer';
    case Admin = 'admin';
}
