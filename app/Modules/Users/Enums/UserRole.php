<?php

declare(strict_types=1);

namespace App\Modules\Users\Enums;

/**
 * A customer's role in the shop: admins get the bot's privileged commands, pass its gates (bot off, phone, channels),
 * press the report group's buttons and may work the website's admin side. Unrelated to the panel's login.
 */
enum UserRole: string
{
    case Customer = 'customer';
    case Admin = 'admin';
}
