<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Modules\Bots\Models\Bot;
use App\Modules\Users\Models\User;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Who is signed in to work a shop, and whose shop a request of theirs is worked in: on the owner's panel the one login
 * config.php keeps and the shop the request names, on an agent's panel the agent's bot and its shop (which of the two
 * is the panel itself, PanelAuth), and on a shop's website one of the shop's admins — its customer whose role is admin —
 * in the website's shop (Store\Http\StaffMiddleware). The guard works each request in `shop` and puts the principal on
 * it; what the request decides is the principal's (actor()), and what its answer shows, read by them (CurrentPrincipal).
 */
final class Principal
{
    /** The request attribute a guard puts the principal under. */
    public const ATTRIBUTE = 'principal';

    public function __construct(
        public readonly PrincipalKind $kind,
        /**
         * What a decision is recorded under (`reviewer`): the owner's login, the agent's bot ("@agent_bot"), an admin of
         * the shop's as the report group's buttons name them (Services\Reviewers::forAdmin()).
         */
        public readonly string $name,
        public readonly Bot $shop,
        /** One of the shop's admins: their account, the shop's customer the website signed in. Null on a panel. */
        public readonly ?User $customer = null,
    ) {}

    /** The principal of a request that passed a guard: a panel's, or the website's admins' (StaffMiddleware). */
    public static function of(ServerRequestInterface $request): self
    {
        $principal = $request->getAttribute(self::ATTRIBUTE);

        return $principal instanceof self ? $principal : throw new \LogicException('The request did not pass a principal\'s guard.');
    }

    /** Who decides what the principal's request decides. */
    public function actor(): Actor
    {
        return Actor::of($this);
    }
}
