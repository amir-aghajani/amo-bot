<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Core\Exceptions\ValidationException;
use App\Core\Session\Session;
use App\Modules\Auth\Credentials;
use App\Modules\Auth\Exceptions\ShopRefusedException;
use App\Modules\Auth\NamedShop;
use App\Modules\Auth\PanelAuth;
use App\Modules\Auth\Principal;
use App\Modules\Auth\PrincipalKind;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Bots\Services\Bots;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The owner's panel session (/api/admin). They sign in with the one account config.php keeps — the session keeps a
 * fingerprint of the credentials it was opened with, so a new login (the file edited, its recovery, the owner's own
 * change from another browser) ends every open session — and may work in any shop: the main bot's, or any
 * agent's. Which one is each request's own (NamedShop: the shop its tab shows), never the session's — every tab of one
 * browser shares the session, and a shop it remembered would move under the tabs that did not pick it. Its keys are its
 * own: an agent signed in to their panel in the same browser stays signed in.
 */
final class OwnerAuth implements PanelAuth
{
    private const FINGERPRINT = 'auth.owner';

    public function __construct(
        private readonly Session $session,
        private readonly AdminAccount $account,
        private readonly Bots $bots,
    ) {}

    public function principal(ServerRequestInterface $request): ?Principal
    {
        if (!$this->account->configured() || $this->session->get(self::FINGERPRINT) !== $this->account->fingerprint()) {
            return null;
        }

        return new Principal(PrincipalKind::Owner, $this->account->username(), $this->shop($request));
    }

    /**
     * Sign in with the panel's credentials, in the shop the request names; null when they do not match (or none are
     * configured). The shop is asked for once the credentials are found right, before the session is held: a sign-in at
     * the address of a shop that is not there opens nothing (and tells that to nobody who has not the password).
     *
     * @throws ValidationException 422 on `shop`: the shop named is no bot's id
     * @throws ShopRefusedException 404: the shop named is not there
     */
    public function attempt(string $username, string $password, ServerRequestInterface $request): ?Principal
    {
        if (!$this->account->verify($username, $password)) {
            return null;
        }

        $shop = $this->shop($request);
        $this->hold();

        return new Principal(PrincipalKind::Owner, $this->account->username(), $shop);
    }

    /**
     * A new login for the panel — the owner's change from their panel, or its recovery (the caller has checked it is
     * them: the current password, or the key off the host's files): written to config.php, every other session ends with
     * the credentials it was opened under, and this one goes on under the new ones, in `$shop` (the request's).
     *
     * @throws ValidationException when config.php cannot be written; nothing changed then
     */
    public function changeCredentials(Credentials $credentials, Bot $shop): Principal
    {
        $this->account->save($credentials);
        $this->hold();

        return new Principal(PrincipalKind::Owner, $this->account->username(), $shop);
    }

    /**
     * The shop the request names (NamedShop) — the main bot's when it names none —, as the owner may open any.
     *
     * @throws ValidationException 422 on `shop`: no bot's id
     * @throws ShopRefusedException 404: a bot that is not there — never the main shop in its place
     */
    public function shop(ServerRequestInterface $request): Bot
    {
        return $this->bots->find(NamedShop::of($request) ?? Bot::MAIN) ?? throw ShopRefusedException::notFound();
    }

    /** @return list<Bot> Every shop the owner may open: the main bot's, then each agent's. */
    public function shops(): array
    {
        return [CurrentBot::main(), ...Bot::agents()->with('agent')->get()->all()];
    }

    public function logout(): void
    {
        $this->session->remove(self::FINGERPRINT);
        $this->session->regenerate();
    }

    /** This session is the owner's under the credentials of now — with a new id: one learnt before is worth nothing after. */
    private function hold(): void
    {
        $this->session->regenerate();
        $this->session->set(self::FINGERPRINT, $this->account->fingerprint());
    }
}
