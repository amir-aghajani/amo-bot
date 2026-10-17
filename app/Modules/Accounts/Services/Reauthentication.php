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
 * POST /me/reauthenticate: a signed-in customer proves one way into their account again, so a bearer token alone (one
 * that leaked) cannot change how the account is signed in to (Http\RecentSignInMiddleware). The proof is the account's
 * password (with its second step's code while two-factor sign-in is on), or its Telegram or Google account proven as a
 * sign-in proves them. Every failure counts as a failed sign-in, and proving another account is refused under its field.
 * A strong way proven also makes a password-only session a strong one (CustomerSessions::reauthenticated()).
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
     * Prove a way into the customer's account again; the session is recent from now on.
     *
     * @param array<string, mixed> $input {method: password, password, code?} | {method: telegram, id_token, nonce} | {method: telegram, code, state, code_verifier} | {method: google, id_token, nonce}
     * @throws ValidationException 422 under the fields: a proof that does not hold, or proves another account
     * @throws SignInRefusedException 422 a way in the website has not set up; 502 Google out of reach
     * @throws TooManyAttemptsException 429 while the address or the account must wait
     * @throws TelegramUnreachableException 502
     */
    public function prove(SignInSite $site, ServerRequestInterface $request, Customer $customer, array $input): void
    {
        $user = $customer->user;
        $proven = match (Input::text($input, 'method')) {
            'password' => $this->password($request, $user, $input),
            'telegram' => $this->ours($request, $this->telegram->account($site, $request, $input, $customer)->id === $user->telegram_id, TelegramSignIn::proofField($input), SignInMethod::Telegram),
            'google' => $this->ours($request, $this->google->account($site, $request, $input, $customer)->sub === $user->google_sub, 'id_token', SignInMethod::Google),
            default => throw ValidationException::on('method', self::METHOD),
        };

        $this->sessions->reauthenticated($customer->session, $proven);
    }

    /**
     * The account's password, and its second step's code while one is asked — each checked and counted as a sign-in's.
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
            $errors['password'][] = EmailSignIn::NO_PASSWORD;
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

    /** A provider's proof must be of this very account; another account's is refused under `$field` and counted as a failed sign-in. */
    private function ours(ServerRequestInterface $request, bool $ours, string $field, SignInMethod $method): SignInMethod
    {
        if (!$ours) {
            $this->throttle->failed(SignInThrottle::WEBSITE, $request);

            throw ValidationException::on($field, self::NOT_THIS_ACCOUNT);
        }

        return $method;
    }
}
