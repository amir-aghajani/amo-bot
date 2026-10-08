<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\Exceptions\ValidationException;
use App\Modules\Auth\Exceptions\ShopRefusedException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * One panel's session — the owner's (Services\OwnerAuth) or an agent's (Services\AgentAuth) —, as the panel's guard
 * reads it (PanelAuthMiddleware). Each keeps its own keys in the browser's one session, so an owner and an agent stay
 * signed in side by side; signing out of one leaves the other. The session says who is signed in, never in which shop:
 * that is each request's own (NamedShop).
 */
interface PanelAuth
{
    /**
     * Who is signed in to this panel, in the shop this request is worked in; null when nobody is (or their session no
     * longer holds).
     *
     * @throws ValidationException 422 on `shop`: the shop named is no bot's id (NamedShop)
     * @throws ShopRefusedException a shop the request may not be worked in: one that is not there, another's than an agent's
     */
    public function principal(ServerRequestInterface $request): ?Principal;
}
