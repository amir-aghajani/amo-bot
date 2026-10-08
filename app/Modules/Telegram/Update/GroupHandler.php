<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Update;

/**
 * What the dispatcher hands an update from a group or supergroup. Customers are served in private chats only; a group
 * is where the admins read the shop's reports, so its updates have no user, no session and no gates.
 */
interface GroupHandler
{
    public function handle(Update $update): void;
}
