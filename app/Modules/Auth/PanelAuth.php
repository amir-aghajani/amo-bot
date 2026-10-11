<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\Exceptions\ValidationException;
use App\Modules\Auth\Exceptions\ShopRefusedException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * One panel's sign-in (OwnerAuth, AgentAuth), read by PanelAuthMiddleware. Each keeps its own key in the browser's one
 * session, so the owner and an agent can be signed in side by side. The session says who is signed in, never in which
 * shop (NamedShop).
 */
interface PanelAuth
{
    /**
     * Who is signed in to this panel, in the shop the request runs in; null when nobody is.
     *
     * @throws ValidationException 422 on `shop`: the shop named is no bot's id (NamedShop)
     * @throws ShopRefusedException a shop the request may not be worked in: one that is not there, another's than an agent's
     */
    public function principal(ServerRequestInterface $request): ?Principal;
}
