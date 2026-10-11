<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Core\Exceptions\TooManyAttemptsException;
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
use App\Support\Input;
use Psr\Http\Message\ServerRequestInterface;

/**
 * An agent's panel sign-in (/api/agent), by the one-time link the main bot gives (AgentBots::loginLink()); they work in
 * their bot's shop only. Every request checks again that the bot is still theirs and active and that its `panel_epoch`
 * is the one they signed in under (raising it ends their sessions).
 */
final class AgentAuth implements PanelAuth
{
    private const KEY = 'auth.agent';

    /** The throttle's way for login links: its failures counted per address. */
    private const WAY = 'link';

    public function __construct(
        private readonly Session $session,
        private readonly SignInThrottle $throttle,
    ) {}

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
     * Sign in with a login link — {code, replace?}. The address must not be waiting; a code that opens nothing is a
     * failure counted against it, one that works clears its count. `replace` lets the link take the place of another
     * agent's session open in this browser, which it is refused without (the panel asks first; nothing counted).
     *
     * @param array<string, mixed> $input
     * @throws SignInRefusedException 401: a code no link of now carries; 409: another agent's session is open here
     * @throws TooManyAttemptsException 429 while this address must wait
     */
    public function login(array $input, ServerRequestInterface $request): Principal
    {
        $this->throttle->check(self::WAY, $request);

        $principal = $this->attemptCode(Input::text($input, 'code'), Input::truthy($input['replace'] ?? false));
        if ($principal === null) {
            $this->throttle->failed(self::WAY, $request);

            throw SignInRefusedException::linkRefused();
        }
        $this->throttle->passed(self::WAY, $request);

        return $principal;
    }

    /**
     * Sign in with a login link's code, spent by a compare-and-swap so two tabs sign in once; null for a wrong, used or
     * expired code or an agency that ended. Where another agent is signed in, it is refused unspent unless `$replace`.
     *
     * @throws SignInRefusedException 409: another agent's session is open here, and the request does not replace it
     */
    private function attemptCode(string $code, bool $replace): ?Principal
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
     * Sign the agent's other browsers out: raise the bot's `panel_epoch` (a compare-and-swap — of two at once, the first
     * ends the other) and hold this session under the new one.
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

    /** The bot this session holds, while it is still its agent's, active, and under the same epoch. */
    private function held(): ?Bot
    {
        $held = $this->session->get(self::KEY);
        if (!is_array($held) || !isset($held['bot'], $held['agent'], $held['epoch'])) {
            return null;
        }
        $bot = Bot::query()->find((int) $held['bot']);

        return $bot !== null && self::open($bot) && $bot->user_id === (int) $held['agent'] && $bot->panel_epoch === (int) $held['epoch'] ? $bot : null;
    }

    /** Hold this session for the bot's agent under `$epoch`. */
    private function hold(Bot $bot, int $epoch): void
    {
        $this->session->set(self::KEY, ['bot' => $bot->id, 'agent' => $bot->user_id, 'epoch' => $epoch]);
    }

    /** An agent's bot whose agent still has an agency. */
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
