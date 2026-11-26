<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin;

/**
 * Which panel a shop is managed as from its bot's private chat, and so which of the panels' doors its requests take
 * (BotAdminDoor::PATH): the owner's — in the main bot, a Telegram account linked to config.php's login (OwnerLinks) or
 * one of its admins: every section, any shop — or an agent's — in an agent's bot, the agent or one of its admins: their
 * panel's sections, in their shop.
 */
enum SeatKind: string
{
    case Owner = 'owner';
    case Agent = 'agent';
}
