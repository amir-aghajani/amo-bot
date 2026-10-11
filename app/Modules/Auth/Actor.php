<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Modules\Auth\Services\Reviewers;
use App\Modules\Users\Models\User;

/**
 * Who makes a decision, passed to every operation that records one: a principal (of() — the owner, an agent, a shop's
 * admin on its website), an admin in the report group (groupAdmin()) or the shop itself (system(), a timer). `reviewer`
 * is the name the decision keeps; `userId` is the acting admin's own customer account, so a rule can refuse them a
 * decision about themselves.
 */
final class Actor
{
    private function __construct(
        public readonly ActorKind $kind,
        /** The name a decision keeps (`reviewer`); null for the shop itself. */
        public readonly ?string $reviewer,
        /** The acting admin's customer account (website or report group); null for the panels and the shop itself. */
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

    /** The shop itself (a task, a timer): a decision with nobody's name on it. */
    public static function system(): self
    {
        return new self(ActorKind::System, null, null);
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

    /** The owner, who runs the servers and so reads a panel's failure in full. */
    public function isOwner(): bool
    {
        return $this->kind === ActorKind::Owner;
    }

    /** Whether the actor is this customer: a decision about their own payment, wallet, service or request. */
    public function is(int $customer): bool
    {
        return $this->userId === $customer;
    }

    /** The actor as a log line names them: kind, name and customer number. */
    public function label(): string
    {
        return trim($this->kind->value . ' ' . ($this->reviewer ?? '') . ($this->userId !== null ? " #{$this->userId}" : ''));
    }
}
