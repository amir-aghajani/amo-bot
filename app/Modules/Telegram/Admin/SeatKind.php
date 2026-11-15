<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin;

/**
 * Who manages a shop from its bot's private chat, and so which of the panels' doors their requests take
 * (BotAdminDoor::PATH): the owner — a Telegram account linked to config.php's login (OwnerLinks), every section, any
 * shop —, an agent in their own bot (their panel's sections), or one of the shop's admins (the daily work and the grants
 * «مدیران ربات» gives them, as the website's admins have it).
 */
enum SeatKind: string
{
    case Owner = 'owner';
    case Agent = 'agent';
    case Staff = 'staff';
}
