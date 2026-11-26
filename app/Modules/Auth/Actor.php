<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Modules\Auth\Services\Reviewers;
use App\Modules\Users\Models\User;

/**
 * Who makes a decision, passed to every operation that records one: a principal (of() — the owner, an agent, a shop's
 * admin on its website), an admin in the report group (groupAdmin()), a customer deciding about their own (customer(),
 * a service they hand to another) or the shop itself (system(), a timer). `reviewer` is the name the decision keeps;
 * `userId` is the acting admin's own customer account — or the customer's —, so a rule can refuse them a decision about
 * themselves; a shop's admin on its website carries the grants they may use (holds()), for a rule a route cannot name.
 */
final class Actor
{
    private function __construct(
        public readonly ActorKind $kind,
        /** The name a decision keeps (`reviewer`); null for the shop itself. */
        public readonly ?string $reviewer,
        /** The acting admin's customer account (website, report group, bot's management); null for panels and the shop. */
        public readonly ?int $userId,
        /** @var list<string>|null What a shop's admin on its website may use beyond the daily work (Principal::$grants); null: every grant. */
        private readonly ?array $grants = null,
        /** Whether the decision is made from the bot's private chat (Principal::$inBot). */
        public readonly bool $inBot = false,
        /** Another account of the same admin's that is theirs too (Principal::$home): null for none. */
        private readonly ?int $homeId = null,
    ) {}

    public static function of(Principal $principal): self
    {
        $kind = match ($principal->kind) {
            PrincipalKind::Owner => ActorKind::Owner,
            PrincipalKind::Agent => ActorKind::Agent,
            PrincipalKind::Staff => ActorKind::Staff,
        };

        return new self($kind, $principal->name, $principal->customer?->id, $principal->grants, $principal->inBot, $principal->home?->id);
    }

    /** One of the shop's admins deciding in its report group, by their account. */
    public static function groupAdmin(User $admin): self
    {
        return new self(ActorKind::GroupAdmin, Reviewers::forAdmin($admin), $admin->id);
    }

    /** A customer deciding about what is theirs (a service they hand to another): no admin's name on it. */
    public static function customer(User $customer): self
    {
        return new self(ActorKind::Customer, null, $customer->id);
    }

    /** The shop itself (a task, a timer): a decision with nobody's name on it. */
    public static function system(): self
    {
        return new self(ActorKind::System, null, null);
    }

    /**
     * The shop itself on what a machine told it, under the machine's name for the decision to keep (a card payment a bank
     * SMS approved: Payments\Deposits\Services\Deposits::REVIEWER).
     */
    public static function machine(string $reviewer): self
    {
        return new self(ActorKind::System, $reviewer, null);
    }

    /**
     * The name a person's decision keeps, for what only a person decides (a receipt refused, a refund).
     *
     * @throws \LogicException for the shop itself
     */
    public function name(): string
    {
        return $this->reviewer ?? throw new \LogicException('The shop itself makes no decision that keeps a name.');
    }

    /** A shop's admin working it from its website. */
    public function isStaff(): bool
    {
        return $this->kind === ActorKind::Staff;
    }

    /**
     * Whether the actor may do what `$grant` opens: a shop's admin on its website while the website grants it and their
     * sign-in is recent (StaffMiddleware); anyone else always.
     */
    public function holds(StaffGrant $grant): bool
    {
        return $this->grants === null || in_array($grant->value, $this->grants, true);
    }

    /** One of the shop's admins deciding in its report group. */
    public function isGroupAdmin(): bool
    {
        return $this->kind === ActorKind::GroupAdmin;
    }

    /** A customer about their own, not support. */
    public function isCustomer(): bool
    {
        return $this->kind === ActorKind::Customer;
    }

    /** The owner, who runs the servers and so reads a panel's failure in full. */
    public function isOwner(): bool
    {
        return $this->kind === ActorKind::Owner;
    }

    /**
     * The owner themselves (Principal::isTheOwner()): an owner's decision with no customer account beside it — not one of
     * the main shop's admins working the bot's management with the owner's seat.
     */
    public function isTheOwner(): bool
    {
        return $this->kind === ActorKind::Owner && $this->userId === null;
    }

    /** The agent themselves: an agent's decision with no customer account beside it — not one of their bot's admins. */
    public function isTheAgent(): bool
    {
        return $this->kind === ActorKind::Agent && $this->userId === null;
    }

    /** Whether the actor is this customer: a decision about their own payment, wallet, service or request. */
    public function is(int $customer): bool
    {
        return $this->userId === $customer || ($this->homeId !== null && $this->homeId === $customer);
    }

    /** The actor as a log line names them: kind, name and customer number. */
    public function label(): string
    {
        return trim($this->kind->value . ' ' . ($this->reviewer ?? '') . ($this->userId !== null ? " #{$this->userId}" : ''));
    }
}
