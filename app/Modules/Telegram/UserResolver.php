<?php

declare(strict_types=1);

namespace App\Modules\Telegram;

use App\Modules\Referrals\Services\ReferralService;
use App\Modules\Telegram\Update\Update;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\Customers;

/**
 * Finds (or registers) the shop User behind a Telegram update and keeps their profile fresh — a customer of the bot the
 * update came to (CurrentBot): one person who talks to two bots is a customer of each. A customer who is not in the
 * database yet and arrives with /start and someone's referral link (`/start ref_…`) becomes that someone's referral as
 * they are registered — here, before any gate, so a first /start the phone or channel rule holds back still counts.
 * Registering is Users\Services\Customers' — the shop's website registers its newcomers the same way: reported to the
 * admins' report group with whoever brought them, the agent who owns a bot its admin there.
 *
 * The row is written only when something on it changes — the profile, a block lifted — or `last_seen_at` is a minute
 * old, and a visit alone quietly (Customers::seen()).
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

        // Whoever writes to the bot has not blocked it; a my_chat_member update says either way itself (recordMembership()).
        if ($update->type() !== 'my_chat_member') {
            $user->bot_blocked = false;
        }
        $this->customers->seen($user);

        return $user;
    }

    /**
     * Whether the customer blocked the bot, as a my_chat_member update says — so what the bot sends on its own skips
     * them instead of failing.
     */
    public function recordMembership(User $user, Update $update): void
    {
        $status = $update->memberStatus();
        if ($status !== null) {
            $user->forceFill(['bot_blocked' => in_array($status, ['kicked', 'left'], true)])->save();
        }
    }
}
