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
 * password (with its second step's code while two-factor sign-in is on), its Telegram or Google account proven as a
 * sign-in proves them, or — on a website that signs in by SMS, an account without two-factor sign-in — a code texted to
 * its mobile for this very session (PhoneSignIn::sendReauthCode(), POST /me/reauthenticate/phone, then the code here) —
 * or, in the mini app (through its key alone: a website keeps neither), its launch data made within a recent sign-in's
 * minutes (it counts from when Telegram made it), or the secret the app keeps behind the device's fingerprint or face for
 * this session (CustomerSessions::setBiometric()). Every failure counts as a failed sign-in, and proving another account
 * is refused under its field. A strong way proven also makes a password-only session a strong one
 * (CustomerSessions::reauthenticated()) — a code by SMS is none (SignInMethod::strong()); the fingerprint proves the
 * session's own holder again and changes its way in not.
 */
final class Reauthentication
{
    public const METHOD = 'روش تایید را بفرستید: password، telegram، google، phone، mini_app یا biometric.';
    public const MINI_APP_ONLY = 'این روش تایید فقط در مینی‌اپ فروشگاه کار می‌کند؛ با روش ورود دیگری تایید کنید.';
    public const BIOMETRIC_REFUSED = 'تایید با اثر انگشت یا چهره درست نبود؛ مینی‌اپ را ببندید و دوباره از ربات باز کنید.';
    public const NO_PASSWORD = 'این حساب با رمز عبور وارد نمی‌شود؛ با روش ورود دیگری تایید کنید.';
    public const NOT_THIS_ACCOUNT = 'این ورود مال حساب دیگری است؛ با یکی از روش‌های ورود همین حساب تایید کنید.';

    /**
     * The ways only the mini app proves: through a website's key they are refused on `method` — a website that kept
     * the fingerprint secret beside the bearer token would hold a pair that keeps the session recent while it lasts.
     */
    private const MINI_APP_METHODS = ['mini_app', 'biometric'];

    public function __construct(
        private readonly EmailSignIn $email,
        private readonly TwoFactor $twoFactor,
        private readonly TelegramSignIn $telegram,
        private readonly GoogleSignIn $google,
        private readonly PhoneSignIn $phone,
        private readonly MiniAppSignIn $miniApp,
        private readonly CustomerSessions $sessions,
        private readonly SignInThrottle $throttle,
    ) {}

    /**
     * Prove a way into the customer's account again; the session is recent from now on.
     *
     * @param array<string, mixed> $input {method: password, password, code?} | {method: telegram, id_token, nonce} | {method: telegram, code, state, code_verifier} | {method: google, id_token, nonce} | {method: phone, code} | {method: mini_app, init_data} | {method: biometric, token}
     * @throws ValidationException 422 under the fields: a proof that does not hold, or proves another account; on
     *                             `method`, one of the mini app's ways through a website
     * @throws SignInRefusedException 422 a way in the website has not set up; 502 Google out of reach
     * @throws TooManyAttemptsException 429 while the address or the account must wait
     * @throws TelegramUnreachableException 502
     */
    public function prove(SignInSite $site, ServerRequestInterface $request, Customer $customer, array $input): void
    {
        $user = $customer->user;
        $method = Input::text($input, 'method');
        if (in_array($method, self::MINI_APP_METHODS, true) && !$site->isMiniApp()) {
            throw ValidationException::on('method', self::MINI_APP_ONLY);
        }
        if ($method === 'mini_app') {
            $launch = $this->miniApp->launch($site, $request, $input, CustomerSessions::RECENT_SECONDS, signedIn: true);
            $this->sessions->reauthenticated($customer->session, $this->ours($request, $launch->telegramId === $user->telegram_id, 'init_data', SignInMethod::MiniApp), $launch->madeAt);

            return;
        }
        if ($method === 'biometric') {
            $this->biometric($request, $customer, $input);

            return;
        }
        $proven = match ($method) {
            'password' => $this->password($request, $user, $input),
            'telegram' => $this->ours($request, $this->telegram->account($site, $request, $input, $customer)->id === $user->telegram_id, TelegramSignIn::proofField($input), SignInMethod::Telegram),
            'google' => $this->ours($request, $this->google->account($site, $request, $input, $customer)->sub === $user->google_sub, 'id_token', SignInMethod::Google),
            'phone' => $this->phoneCode($site, $request, $customer, $input),
            default => throw ValidationException::on('method', self::METHOD),
        };

        $this->sessions->reauthenticated($customer->session, $proven);
    }

    /**
     * The secret the mini app keeps behind the device's fingerprint or face for this very session, released by Telegram
     * only to that fingerprint or face: the session's holder proven again, its way in unchanged. Checked as a sign-in.
     *
     * @param array<string, mixed> $input
     */
    private function biometric(ServerRequestInterface $request, Customer $customer, array $input): void
    {
        $this->throttle->check(SignInThrottle::WEBSITE, $request);
        $secret = is_string($input['token'] ?? null) ? $input['token'] : '';
        if (!CustomerSessions::holdsBiometric($customer->session, $secret)) {
            $this->throttle->failed(SignInThrottle::WEBSITE, $request);

            throw ValidationException::on('token', self::BIOMETRIC_REFUSED);
        }
        $this->sessions->reauthenticated($customer->session, $customer->session->method ?? SignInMethod::Password);
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

    /**
     * The code texted to the account's own mobile for this very session (PhoneSignIn::proveReauth()), checked and counted
     * as a sign-in's — refused on an account with two-factor sign-in, whose password and code prove it.
     *
     * @param array<string, mixed> $input
     */
    private function phoneCode(SignInSite $site, ServerRequestInterface $request, Customer $customer, array $input): SignInMethod
    {
        $this->phone->proveReauth($site, $request, $customer, $input);

        return SignInMethod::Phone;
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
