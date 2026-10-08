<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Modules\Accounts\Contracts\SignInSite;
use App\Modules\Accounts\Enums\SignInMethod;
use App\Modules\Accounts\Http\Customer;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Auth\Services\SignInThrottle;
use App\Modules\Telegram\Api\TelegramUnreachableException;
use App\Modules\Users\Models\User;
use App\Support\Input;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A signed-in customer proving one way into their account again (POST /me/reauthenticate) — what a change of how the
 * account is signed in to asks of a session whose sign-in is not recent (Http\RecentSignInMiddleware), so a bearer token
 * alone changes none of it: the account's password — and, its sign-in asking a second step, its app's code or a recovery
 * code (TwoFactor::confirm(), the second step's own budget) —, or its Telegram account or its Google account, proven as a
 * sign-in proves them (the redirect's state this session's own: POST /me/telegram/authorize). A proof of another account
 * is refused under its field; every failure counts as a failed sign-in does. Proven, the session may change the
 * account's ways in for CustomerSessions::RECENT_SECONDS — and a strong way proven (Telegram, Google, a password with its
 * second step) makes a session signed in with a password alone a strong one (CustomerSessions::reauthenticated()).
 */
final class Reauthentication
{
    public const METHOD = 'روش تایید را بفرستید: password، telegram یا google.';
    public const NO_PASSWORD = 'این حساب با رمز عبور وارد نمی‌شود؛ با روش ورود دیگری تایید کنید.';
    public const NOT_THIS_ACCOUNT = 'این ورود مال حساب دیگری است؛ با یکی از روش‌های ورود همین حساب تایید کنید.';

    public function __construct(
        private readonly EmailSignIn $email,
        private readonly TwoFactor $twoFactor,
        private readonly TelegramSignIn $telegram,
        private readonly GoogleSignIn $google,
        private readonly CustomerSessions $sessions,
        private readonly SignInThrottle $throttle,
    ) {}

    /**
     * The customer's way in proven again: the session they asked from is recent from now on.
     *
     * @param array<string, mixed> $input {method: password, password, code?} | {method: telegram, id_token, nonce} | {method: telegram, code, state, code_verifier} | {method: google, id_token, nonce}
     * @throws ValidationException 422 under the fields — a proof that does not hold, or proves another account
     * @throws SignInRefusedException 422 a way in the website has not set up; 502 Google out of reach
     * @throws TooManyAttemptsException 429 while the address or the account must wait
     * @throws TelegramUnreachableException 502
     */
    public function prove(SignInSite $site, ServerRequestInterface $request, Customer $customer, array $input): void
    {
        $user = $customer->user;
        $method = Input::text($input, 'method');
        if ($method === 'password') {
            $proven = $this->password($request, $user, $input);
        } elseif ($method === 'telegram') {
            $this->ours($request, $this->telegram->account($site, $request, $input, $customer)->id === $user->telegram_id, TelegramSignIn::proofField($input));
            $proven = SignInMethod::Telegram;
        } elseif ($method === 'google') {
            $this->ours($request, $this->google->account($site, $request, $input, $user)->sub === $user->google_sub, 'id_token');
            $proven = SignInMethod::Google;
        } else {
            throw ValidationException::on('method', self::METHOD);
        }

        $this->sessions->reauthenticated($customer->session, $proven);
    }

    /**
     * The account's password — and its second step's code while it asks one —, each checked as a sign-in checks it: how
     * the account was proven.
     *
     * @param array<string, mixed> $input
     */
    private function password(ServerRequestInterface $request, User $user, array $input): SignInMethod
    {
        if ($user->email === null || $user->password_hash === null) {
            throw ValidationException::on('password', self::NO_PASSWORD);
        }
        $errors = [];
        $password = Input::password($input, 'password');
        if ($password === '') {
            $errors['password'][] = 'رمز عبور حساب را وارد کنید.';
        }
        if ($user->hasTwoFactor() && Input::text($input, 'code') === '') {
            $errors['code'][] = TwoFactor::CODE_MISSING;
        }
        ValidationException::ifAny($errors);

        $this->email->confirm($user, $request, $password, 'password');
        if (!$user->hasTwoFactor()) {
            return SignInMethod::Password;
        }
        $this->twoFactor->confirm($user, $request, $input);

        return SignInMethod::PasswordAndCode;
    }

    /**
     * A provider's proof held: `$ours` — the account it proved is this one's —, else refused under `$field`, counted as
     * the failed sign-in it is.
     */
    private function ours(ServerRequestInterface $request, bool $ours, string $field): void
    {
        if (!$ours) {
            $this->throttle->failed(SignInThrottle::WEBSITE, $request);

            throw ValidationException::on($field, self::NOT_THIS_ACCOUNT);
        }
    }
}
