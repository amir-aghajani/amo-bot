<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin;

use App\Modules\Auth\Principal;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Users\Models\User;

/**
 * One person managing a shop from its bot's private chat (AdminSeats::of()): which panel's seat it is, the principal
 * their requests run as — the panels' own (Telegram\Admin\Door\BotAdminDoor puts it on each request) —, and their
 * account in this bot.
 */
final class AdminSeat
{
    public function __construct(
        public readonly SeatKind $kind,
        public readonly Principal $principal,
        /** Their customer account in the bot they write to. */
        public readonly User $user,
    ) {}

    /** The shop their requests run in: an owner's seat's pick (any shop), else the bot's own. */
    public function shop(): Bot
    {
        return $this->principal->shop;
    }

    public function isOwner(): bool
    {
        return $this->kind === SeatKind::Owner;
    }

    public function isAgent(): bool
    {
        return $this->kind === SeatKind::Agent;
    }

    /**
     * Whether the seat is one of the shop's admins (role admin) — the owner's or the agent's whole seat, their decisions
     * under their own name, and none about their own account (Principal::$customer) —, not the owner's linked account nor
     * the agent themselves.
     */
    public function isAdmin(): bool
    {
        return $this->principal->customer !== null;
    }

    /**
     * The same person working another shop (an owner's seat alone switches shops). An admin's own account there is their
     * account in that shop — the one of their Telegram account, where they are a customer of it too —, so a decision about
     * it is refused there as well, and their account in the bot they write to stays theirs (Principal::$home: their agency,
     * their requests); with none there, still the latter (a customer account stays beside an admin's seat).
     */
    public function in(Bot $shop): self
    {
        if (!$this->isOwner()) {
            throw new \LogicException('Only an owner\'s seat works another shop from the bot.');
        }
        $customer = $this->principal->customer;
        if ($customer !== null && $shop->id !== $customer->bot_id && $this->user->telegram_id !== null) {
            $customer = User::query()->withoutGlobalScope(CurrentBot::SCOPE)->where('bot_id', $shop->id)->where('telegram_id', $this->user->telegram_id)->first() ?? $this->user;
        }
        // Their account in the bot they write to stays theirs in any shop: its agency and requests are the main shop's.
        $home = $customer !== null && $customer->id !== $this->user->id ? $this->user : null;

        return new self($this->kind, new Principal($this->principal->kind, $this->principal->name, $shop, $customer, $this->principal->grants, $this->principal->inBot, $home), $this->user);
    }
}
