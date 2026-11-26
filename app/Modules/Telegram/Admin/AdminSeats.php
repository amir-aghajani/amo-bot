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
 * a ban, an owner unlinked or an agency ended ends it at once:
 * 1. in the main bot, an account linked as the owner (OwnerLinks) and every admin of it (role admin): the owner's
 *    seat — every section, in the shop it picked (any shop, as the owner's panel opens any);
 * 2. in an agent's bot while their agency stands, the agent — their own Telegram account — and every admin of it: the
 *    agent's seat — their panel's sections, in their shop.
 * An admin's decisions keep their own name and none is about their own account (AdminSeat::isAdmin()). Nobody banned
 * manages anything.
 */
final class AdminSeats
{
    public function __construct(
        private readonly OwnerLinks $owners,
        private readonly AdminAccount $account,
    ) {}

    /** `$user`'s seat in the current bot's shop — an owner's seat in `$shop` when it picked one —, or null for none. */
    public function of(User $user, ?int $shop = null): ?AdminSeat
    {
        $bot = CurrentBot::get();
        if ($user->isBanned() || $user->telegram_id === null) {
            return null;
        }
        if ($bot->isMain()) {
            $picked = $shop === null || $shop === $bot->id ? $bot : Bot::query()->find($shop) ?? $bot;
            if ($this->owners->isOwner($user->telegram_id)) {
                return new AdminSeat(SeatKind::Owner, new Principal(PrincipalKind::Owner, $this->account->username(), $picked, inBot: true), $user);
            }
            if (!$user->isAdmin()) {
                return null;
            }
            $seat = new AdminSeat(SeatKind::Owner, new Principal(PrincipalKind::Owner, Reviewers::forAdmin($user), $bot, $user, inBot: true), $user);

            return $picked->id === $bot->id ? $seat : $seat->in($picked);
        }
        if (!self::stands($bot)) {
            return null;
        }
        if (self::isAgentOf($bot, $user)) {
            return new AdminSeat(SeatKind::Agent, new Principal(PrincipalKind::Agent, AgentAuth::name($bot), $bot, inBot: true), $user);
        }

        return $user->isAdmin() ? new AdminSeat(SeatKind::Agent, new Principal(PrincipalKind::Agent, Reviewers::forAdmin($user), $bot, $user, inBot: true), $user) : null;
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

        return $bot->isMain() ? $this->owners->isOwner($user->telegram_id) : self::stands($bot) && self::isAgentOf($bot, $user);
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
     * Whether `$bot` is an agent's bot whose agency stands — as their panel asks it (AgentAuth): an agency ended manages
     * nothing from the bot either, nor do its admins.
     */
    private static function stands(Bot $bot): bool
    {
        return !$bot->isMain() && $bot->status() === BotStatus::Active && $bot->agent !== null;
    }

    /** Whether `$user` is the agent of `$bot` (an agent's bot whose agency stands()), by their Telegram account. */
    private static function isAgentOf(Bot $bot, User $user): bool
    {
        return $bot->agent?->telegram_id === $user->telegram_id;
    }
}
