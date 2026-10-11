<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Modules\Bots\Models\Bot;
use App\Modules\Users\Models\User;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Who is signed in to work a shop, and the shop the request runs in: the owner (any shop the request names), an
 * agent (their bot's shop), or a shop's admin on its website (Store\Http\StaffMiddleware). The guard puts it on the
 * request; its decisions are its actor()'s.
 */
final class Principal
{
    /** The request attribute a guard puts the principal under. */
    public const ATTRIBUTE = 'principal';

    public function __construct(
        public readonly PrincipalKind $kind,
        /** The name decisions are recorded under: the owner's login, the agent's "@bot", an admin's Reviewers::forAdmin(). */
        public readonly string $name,
        public readonly Bot $shop,
        /** A shop's admin's customer account; null on a panel. */
        public readonly ?User $customer = null,
    ) {}

    /** The principal a guard put on the request. */
    public static function of(ServerRequestInterface $request): self
    {
        $principal = $request->getAttribute(self::ATTRIBUTE);

        return $principal instanceof self ? $principal : throw new \LogicException('The request did not pass a principal\'s guard.');
    }

    /** The principal as the actor of its decisions. */
    public function actor(): Actor
    {
        return Actor::of($this);
    }
}
