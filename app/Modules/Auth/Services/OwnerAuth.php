<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Session\Session;
use App\Modules\Auth\Credentials;
use App\Modules\Auth\Exceptions\ShopRefusedException;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Auth\NamedShop;
use App\Modules\Auth\PanelAuth;
use App\Modules\Auth\Principal;
use App\Modules\Auth\PrincipalKind;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Bots\Services\Bots;
use App\Support\Input;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The owner's panel sign-in (/api/admin). The session keeps a fingerprint of the login it was opened under, so a new
 * login (edited, recovered, changed elsewhere) ends every other session. The owner may work in any shop; which one is
 * each request's (NamedShop).
 */
final class OwnerAuth implements PanelAuth
{
    private const FINGERPRINT = 'auth.owner';

    public function __construct(
        private readonly Session $session,
        private readonly AdminAccount $account,
        private readonly SignInThrottle $throttle,
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
     * Sign in with {username, password}, in the shop the request names. In this order: the address (and the username
     * typed) must not be waiting, both fields are asked, a login must be set up; then the password is counted before it
     * is judged (SignInThrottle::password()), so guesses sent at once never pass the limits. The right credentials at
     * the address of a shop that is not there are no failed sign-in: the count goes back, and the shop is the refusal.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException 422: a field left blank, or `shop` that is no bot's id
     * @throws SignInRefusedException 401 wrong credentials, 503 no login set up yet
     * @throws ShopRefusedException 404: the right credentials at the address of a shop that is not there (nothing opened)
     * @throws TooManyAttemptsException 429 while this address, or this username, must wait
     */
    public function login(array $input, ServerRequestInterface $request): Principal
    {
        $username = Input::text($input, 'username');
        $password = Input::password($input, 'password');
        $this->throttle->check(AdminAccount::WAY, $request, $username === '' ? null : $username);

        $errors = [];
        if ($username === '') {
            $errors['username'][] = 'نام کاربری را وارد کنید.';
        }
        if ($password === '') {
            $errors['password'][] = 'رمز عبور را وارد کنید.';
        }
        ValidationException::ifAny($errors);
        if (!$this->account->configured()) {
            throw SignInRefusedException::notSetUp();
        }

        $principal = null;
        $refused = null;
        $this->throttle->password(AdminAccount::WAY, $request, $username, function () use ($username, $password, $request, &$principal, &$refused): bool {
            try {
                $principal = $this->attempt($username, $password, $request);
            } catch (ShopRefusedException|ValidationException $shop) {
                $refused = $shop;

                return true;
            }

            return $principal !== null;
        });
        if ($refused !== null) {
            throw $refused;
        }

        return $principal ?? throw SignInRefusedException::wrongCredentials();
    }

    /**
     * The owner changes the login from a session — {current_password, username, password, password_confirmation} — and
     * this session goes on under the new one, in `$shop`. A blank password keeps the current one; a change that changes
     * nothing is refused. The current password is judged with the login's failures (AdminAccount::confirm()), so this is
     * no way around the throttle; every refusal comes at once.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException 422: the form's refusals, or a config.php the server may not write (`file`)
     * @throws TooManyAttemptsException 429 while this address, or the account, must wait
     */
    public function changeLogin(array $input, ServerRequestInterface $request, Bot $shop): Principal
    {
        $account = $this->account->username();
        $this->throttle->check(AdminAccount::WAY, $request, $account);

        [$credentials, $errors] = Credentials::fromInput($input, keepPassword: true);
        if ($credentials->password === null && $credentials->username === $account) {
            $errors['password'][] = 'رمز عبور جدید را وارد کنید، یا نام کاربری را عوض کنید.';
        }
        $unproven = $this->account->confirm($request, Input::password($input, 'current_password'));
        if ($unproven !== null) {
            $errors['current_password'][] = $unproven;
        }
        ValidationException::ifAny($errors);

        return $this->changeCredentials($credentials, $shop);
    }

    /**
     * Sign in, in the shop the request names; null for wrong credentials. The shop is looked up only once the
     * credentials are right (so only the owner learns a shop is missing), and before the session is held. Nothing is
     * counted here: login() judges it within the throttle, tests open a session with it directly.
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
     * Save a new login (the caller has proved it is the owner) and keep this session under it; every other session
     * ends with the old fingerprint.
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
     * The shop the request names, the main one when none.
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

    /** Hold this session for the owner under the current login, with a new session id. */
    private function hold(): void
    {
        $this->session->regenerate();
        $this->session->set(self::FINGERPRINT, $this->account->fingerprint());
    }
}
