<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin;

use App\Modules\Auth\Principal;
use App\Modules\Auth\PrincipalKind;
use App\Modules\Auth\Services\AdminAccount;
use App\Modules\Auth\Services\AgentAuth;
use App\Modules\Auth\Services\Reviewers;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Enums\BotStatus;
use App\Modules\Bots\Models\Bot;
use App\Modules\Telegram\Context;
use App\Modules\Users\Models\User;

/**
 * Who of a bot's private chats may manage its shop, and as what (AdminSeat), asked on each update — a role taken away,
 * a ban, an owner unlinked or the bot admins' switch turned off ends it at once:
 * 1. in the main bot, an account linked as the owner (OwnerLinks): every section, in the shop it picked (any shop, as
 *    the owner's panel opens any);
 * 2. in an agent's bot, the agent — their own Telegram account, while their agency stands: their panel's sections, in
 *    their shop;
 * 3. the shop's admins (role admin) while «مدیران ربات» lets them in: the daily work and its grants.
 * Nobody banned manages anything.
 */
final class AdminSeats
{
    public function __construct(
        private readonly OwnerLinks $owners,
        private readonly BotAdminSettings $staff,
        private readonly AdminAccount $account,
    ) {}

    /** `$user`'s seat in the current bot's shop — the owner in `$shop` when they picked one —, or null for none. */
    public function of(User $user, ?int $shop = null): ?AdminSeat
    {
        $bot = CurrentBot::get();
        if ($user->isBanned() || $user->telegram_id === null) {
            return null;
        }
        if ($bot->isMain() && $this->owners->isOwner($user->telegram_id)) {
            $picked = $shop === null || $shop === $bot->id ? $bot : Bot::query()->find($shop) ?? $bot;

            return new AdminSeat(SeatKind::Owner, new Principal(PrincipalKind::Owner, $this->account->username(), $picked, inBot: true), $user);
        }
        if (self::isAgentOf($bot, $user)) {
            return new AdminSeat(SeatKind::Agent, new Principal(PrincipalKind::Agent, AgentAuth::name($bot), $bot, inBot: true), $user);
        }
        if ($user->isAdmin() && $this->staff->enabled()) {
            return new AdminSeat(SeatKind::Staff, new Principal(PrincipalKind::Staff, Reviewers::forAdmin($user), $bot, $user, $this->staff->grants(), true), $user);
        }

        return null;
    }

    /**
     * Whether `$user` manages the current bot's shop whatever the switches say — a role admin, the owner's linked
     * account in the main bot, the agent in their own bot —: the bot's gates (its off switch, the phone, the channels)
     * never stop them, so nobody who manages is locked out of the management.
     */
    public function exempt(User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        if ($user->isBanned() || $user->telegram_id === null) {
            return false;
        }
        $bot = CurrentBot::get();

        return $bot->isMain() ? $this->owners->isOwner($user->telegram_id) : self::isAgentOf($bot, $user);
    }

    /**
     * Whether the bot's gates let this update through: its sender is exempt(), or it is the /start of the owner's open
     * link in the main bot (OwnerLinks::opens()) — the account linking needs no channel, phone or open shop for it.
     */
    public function passGates(Context $ctx): bool
    {
        if ($this->exempt($ctx->user)) {
            return true;
        }

        return CurrentBot::isMain() && $ctx->update->command() === 'start' && $this->owners->opens($ctx->update->commandArgument());
    }

    /**
     * Whether `$user` is the agent of `$bot`, an agent's bot whose agency stands — as their panel asks it
     * (AgentAuth): an agency ended manages nothing from the bot either.
     */
    private static function isAgentOf(Bot $bot, User $user): bool
    {
        return !$bot->isMain() && $bot->status() === BotStatus::Active && $bot->agent !== null && $bot->agent->telegram_id === $user->telegram_id;
    }
}
