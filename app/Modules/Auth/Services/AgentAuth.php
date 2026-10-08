<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Core\Exceptions\ValidationException;
use App\Core\Session\Session;
use App\Modules\Auth\Exceptions\ShopRefusedException;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Auth\NamedShop;
use App\Modules\Auth\PanelAuth;
use App\Modules\Auth\Principal;
use App\Modules\Auth\PrincipalKind;
use App\Modules\Bots\Enums\BotStatus;
use App\Modules\Bots\Models\Bot;
use Psr\Http\Message\ServerRequestInterface;

/**
 * An agent's panel session (/api/agent). They sign in with the one-time link the main bot gave them
 * (AgentBots::loginLink()) and work in their bot's shop only — the shop is their bot, never anything the request names:
 * one that names another bot is refused. Every request asks again: the bot is still theirs and switched on (their
 * agency stands), and its `panel_epoch` is still the one they signed in under (the shop raises it to end their
 * sessions, and so does the agent, to end every one but the session they are in). A link opened where another agent is
 * signed in asks before it takes that session's place. Its key is its own: the owner signed in to their panel in the
 * same browser stays signed in.
 */
final class AgentAuth implements PanelAuth
{
    private const KEY = 'auth.agent';

    public function __construct(private readonly Session $session) {}

    /**
     * @throws ValidationException 422 on `shop`: a shop named that is no bot's id
     * @throws ShopRefusedException 403: another bot's shop named
     */
    public function principal(ServerRequestInterface $request): ?Principal
    {
        $bot = $this->held();
        if ($bot === null) {
            return null;
        }
        $named = NamedShop::of($request);
        if ($named !== null && $named !== $bot->id) {
            throw ShopRefusedException::notYours();
        }

        return new Principal(PrincipalKind::Agent, self::name($bot), $bot);
    }

    /**
     * Sign in with the code of a login link — once: the code is taken by a compare-and-swap on the bot's row, so two
     * tabs opening the same link sign in once. Null for a code that is wrong, used or old, or an agent whose agency or
     * bot is no more. A link of another agent's bot than the one this browser is signed in to takes that session's place
     * only when the request says so (`$replace`): otherwise it is refused before it is spent, and the panel asks.
     *
     * @throws SignInRefusedException 409: another agent's session is open here, and the request does not replace it
     */
    public function attemptCode(string $code, bool $replace): ?Principal
    {
        $hash = hash('sha256', $code);
        $bot = $code === '' ? null : Bot::query()->where('login_code', $hash)->first();
        if ($bot === null || !self::open($bot) || $bot->login_code_expires_at === null || $bot->login_code_expires_at->isPast()) {
            return null;
        }
        $open = $this->held();
        if ($open !== null && $open->id !== $bot->id && !$replace) {
            throw SignInRefusedException::anotherAgent();
        }
        if (Bot::query()->whereKey($bot->id)->where('login_code', $hash)->update(['login_code' => null, 'login_code_expires_at' => null]) !== 1) {
            return null;
        }

        $this->session->regenerate();
        $this->hold($bot, $bot->panel_epoch);

        return new Principal(PrincipalKind::Agent, self::name($bot), $bot);
    }

    /**
     * End every other session of this agent's — each browser signed in to their panel but this one: their bot's
     * `panel_epoch` goes up, and this session is held again under the new one. Of two sessions doing it at once, the
     * first to move the epoch ends the other.
     *
     * @throws SignInRefusedException 401: this session was ended meanwhile
     */
    public function endOthers(Principal $principal): void
    {
        $bot = $principal->shop;
        $epoch = $bot->panel_epoch + 1;
        if (Bot::query()->whereKey($bot->id)->where('panel_epoch', $bot->panel_epoch)->update(['panel_epoch' => $epoch]) !== 1) {
            throw SignInRefusedException::signedOut();
        }
        $bot->forceFill(['panel_epoch' => $epoch])->syncOriginalAttribute('panel_epoch');

        $this->session->regenerate();
        $this->hold($bot, $epoch);
    }

    public function logout(): void
    {
        $this->session->remove(self::KEY);
        $this->session->regenerate();
    }

    /** The bot this session holds: while it is still its agent's, switched on, under the epoch they signed in under. */
    private function held(): ?Bot
    {
        $held = $this->session->get(self::KEY);
        if (!is_array($held) || !isset($held['bot'], $held['agent'], $held['epoch'])) {
            return null;
        }
        $bot = Bot::query()->find((int) $held['bot']);

        return $bot !== null && self::open($bot) && $bot->user_id === (int) $held['agent'] && $bot->panel_epoch === (int) $held['epoch'] ? $bot : null;
    }

    /** This session is the bot's agent's, under the bot's `panel_epoch` of now. */
    private function hold(Bot $bot, int $epoch): void
    {
        $this->session->set(self::KEY, ['bot' => $bot->id, 'agent' => $bot->user_id, 'epoch' => $epoch]);
    }

    /** An agent's bot whose panel may be used: switched on — its owner still an agent. */
    private static function open(Bot $bot): bool
    {
        return !$bot->isMain() && $bot->status() === BotStatus::Active;
    }

    /** "@agent_bot" — or the agent's own handle while their bot has no @username yet. */
    private static function name(Bot $bot): string
    {
        if (($bot->username ?? '') !== '') {
            return '@' . $bot->username;
        }
        $handle = $bot->agent?->username;

        return $handle !== null && $handle !== '' ? '@' . $handle : 'agent#' . $bot->id;
    }
}
