<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin;

use App\Modules\Auth\Principal;
use App\Modules\Auth\StaffGrant;
use App\Modules\Bots\Models\Bot;
use App\Modules\Users\Models\User;

/**
 * One person managing a shop from its bot's private chat (AdminSeats::of()): what kind of seat it is, the principal
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

    /** The shop their requests run in: the owner's pick (any shop), else the bot's own. */
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

    public function isStaff(): bool
    {
        return $this->kind === SeatKind::Staff;
    }

    /** Whether the seat sets the shop up — its configuration, beyond the daily work: the owner and an agent. */
    public function configures(): bool
    {
        return !$this->isStaff();
    }

    /** Whether the seat may do what `$grant` opens: an admin while «مدیران ربات» grants it, the owner and an agent always. */
    public function holds(StaffGrant $grant): bool
    {
        return $this->principal->actor()->holds($grant);
    }

    /** The same person working another shop (the owner alone switches shops). */
    public function in(Bot $shop): self
    {
        if (!$this->isOwner()) {
            throw new \LogicException('Only the owner works another shop from the bot.');
        }

        return new self($this->kind, new Principal($this->principal->kind, $this->principal->name, $shop, $this->principal->customer, $this->principal->grants, $this->principal->inBot), $this->user);
    }
}
