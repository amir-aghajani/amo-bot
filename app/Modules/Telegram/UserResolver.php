<?php

declare(strict_types=1);

namespace App\Modules\Telegram;

use App\Modules\Referrals\Services\ReferralService;
use App\Modules\Telegram\Update\Update;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\Customers;

/**
 * Finds or registers the customer behind an update, in the current bot's shop (one person in two bots is two
 * customers), and keeps their profile fresh. A newcomer arriving with `/start ref_…` becomes that code's referral as
 * they register — before any gate, so a first /start held back by a gate still counts. Registration and the write-only-
 * when-changed rule are Users\Services\Customers'.
 */
final class UserResolver
{
    public function __construct(private readonly Customers $customers) {}

    public function resolve(Update $update): ?User
    {
        $from = $update->from();
        $telegramId = $update->fromId();

        if ($from === null || $telegramId === null || ($from['is_bot'] ?? false)) {
            return null;
        }

        $profile = Customers::profile($from['username'] ?? null, $from['first_name'] ?? null, $from['last_name'] ?? null);
        $code = $update->command() === 'start' ? ReferralService::codeOf($update->commandArgument()) : null;
        $user = $this->customers->byTelegram($telegramId, $profile, $code);
        if ($user->wasRecentlyCreated) {
            return $user;
        }

        // Whoever writes to the bot has not blocked it; a my_chat_member update is recorded by recordMembership().
        if ($update->type() !== 'my_chat_member') {
            $user->bot_blocked = false;
        }
        $this->customers->seen($user);

        return $user;
    }

    /** Record whether the customer blocked the bot (a my_chat_member update), so bulk sends skip them. */
    public function recordMembership(User $user, Update $update): void
    {
        $status = $update->memberStatus();
        if ($status !== null) {
            $user->forceFill(['bot_blocked' => in_array($status, ['kicked', 'left'], true)])->save();
        }
    }
}
