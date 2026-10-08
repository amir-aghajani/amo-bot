<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Modules\Auth\Services\Reviewers;
use App\Modules\Users\Models\User;

/**
 * Who decides what an operation decides — the second argument of every one that records it (an Actions service's, a
 * ticket's answer, a grant, a traffic line set right, a customer signed out by support…): a principal (of()) — the owner,
 * an agent, one of the shop's admins on its website —, one of the shop's admins in its report group (groupAdmin(): the
 * buttons under its reports, a reply to a ticket's), or the shop itself (system(): a timer). `reviewer` is the name the
 * decision keeps; `userId` the acting customer's own account — a shop's admin is its customer —, so a rule may refuse them
 * a decision about themselves.
 */
final class Actor
{
    private function __construct(
        public readonly ActorKind $kind,
        /** The name a decision keeps (`reviewer`); null for the shop itself. */
        public readonly ?string $reviewer,
        /** The acting customer: one of the shop's admins, on its website or in its report group. Null for the panels' and the shop itself. */
        public readonly ?int $userId,
    ) {}

    public static function of(Principal $principal): self
    {
        $kind = match ($principal->kind) {
            PrincipalKind::Owner => ActorKind::Owner,
            PrincipalKind::Agent => ActorKind::Agent,
            PrincipalKind::Staff => ActorKind::Staff,
        };

        return new self($kind, $principal->name, $principal->customer?->id);
    }

    /** One of the shop's admins deciding in its report group, by their account. */
    public static function groupAdmin(User $admin): self
    {
        return new self(ActorKind::GroupAdmin, Reviewers::forAdmin($admin), $admin->id);
    }

    /** The shop itself — a task, a timer —: a decision with nobody's name on it. */
    public static function system(): self
    {
        return new self(ActorKind::System, null, null);
    }

    /**
     * The name a person's decision keeps — what only a person decides (a receipt refused, a payment given back).
     *
     * @throws \LogicException for the shop itself, which decides nothing of the kind
     */
    public function name(): string
    {
        return $this->reviewer ?? throw new \LogicException('The shop itself makes no decision that keeps a name.');
    }

    /** One of the shop's admins working it from its website. */
    public function isStaff(): bool
    {
        return $this->kind === ActorKind::Staff;
    }

    /** The owner, on their panel — who runs the servers, and reads what a panel said as it said it. */
    public function isOwner(): bool
    {
        return $this->kind === ActorKind::Owner;
    }

    /** Whether the actor is this customer of the shop themselves — a decision about their own payment, wallet, service or request. */
    public function is(int $customer): bool
    {
        return $this->userId === $customer;
    }

    /** The actor on a line of the log: who, by the name their decisions keep, and the acting customer's number. */
    public function label(): string
    {
        return trim($this->kind->value . ' ' . ($this->reviewer ?? '') . ($this->userId !== null ? " #{$this->userId}" : ''));
    }
}
