<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Captcha\CaptchaUnavailableException;
use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Mail\Mailer;
use App\Core\Mail\MailFailedException;
use App\Modules\Accounts\Contracts\SignInSite;
use App\Modules\Accounts\DTO\SignedIn;
use App\Modules\Accounts\DTO\TwoFactorChallenge;
use App\Modules\Accounts\Enums\CaptchaAction;
use App\Modules\Accounts\Enums\ChallengePurpose;
use App\Modules\Accounts\Enums\SignInMethod;
use App\Modules\Accounts\Exceptions\AccountRefusedException;
use App\Modules\Accounts\Mail\AlreadyRegistered;
use App\Modules\Accounts\Mail\LinkEmailCode;
use App\Modules\Accounts\Mail\NoAccount;
use App\Modules\Accounts\Mail\PasswordResetCode;
use App\Modules\Accounts\Mail\SignUpCode;
use App\Modules\Accounts\Models\CustomerSession;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Auth\Services\SignInThrottle;
use App\Modules\Bots\Services\Bots;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\Customers;
use App\Support\Email;
use App\Support\Input;
use App\Support\Password;
use App\Support\Persian;
use Illuminate\Database\UniqueConstraintViolationException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Signing up and in on the website with an email and a password. Every stored address is proven by a six-digit code
 * emailed to it, and no answer tells whether an address has an account:
 * - register() / verify(): a sign-up (while the website allows it) emails a code that keeps the new account's details —
 *   or, the address having an account, an email saying so; the code typed back registers the account and signs it in.
 * - login(): a wrong address and a wrong password are one 401, checked against a hash either way so the time tells
 *   nothing; an account with two-factor sign-in answers its second step's challenge instead (TwoFactor::signIn()).
 * - forgot() / reset(): a code to an address with an account, a "no account here" email to one without (once a day).
 *   The new password ends every session; with two-factor sign-in on it waits in the second step's challenge, since the
 *   email alone must not get past that step.
 * - a signed-in customer's own: their password set or changed (changePassword()), an email added (sendLinkCode(),
 *   proveLink()).
 *
 * Every email is held to its budgets before it goes, and they are given back when none went (emailCode()). Every
 * six-digit code is counted against the address's budget of wrong codes before it is judged (code()); a wrong one also
 * counts as a failed sign-in.
 */
final class EmailSignIn
{
    public const WRONG_CODE = 'کد درست نیست یا منقضی شده است.';

    public const WRONG_PASSWORD = 'رمز عبور درست نیست.';

    /** The account's password asked again before a change (confirm()), and left blank. */
    public const NO_PASSWORD = 'رمز عبور حساب را وارد کنید.';

    /** The longest invite code read: a code is eight characters. */
    private const REFERRAL_MAX = 32;

    /**
     * Bcrypt hashes of nothing anyone knows, at PASSWORD_DEFAULT's costs — 10 before PHP 8.4, 12 since. An address without
     * an account is checked against the one at the running PHP's cost (nobody()), so it answers as slowly as a wrong
     * password.
     */
    private const NOBODY = [
        '$2y$10$1MJJ8KlqfAULLnoAN.u6guudmvJ0/gdPoDsMhKbD8KbFJsqbQzSrK',
        '$2y$12$IuRTkh01D4edR0VFyylU4u6G8FOtmPOjbXE2WNIznhp1KUKrKYxP2',
    ];

    public function __construct(
        private readonly AuthChallenges $challenges,
        private readonly Customers $customers,
        private readonly CustomerSessions $sessions,
        private readonly SignInThrottle $throttle,
        private readonly Captcha $captcha,
        private readonly Mailer $mailer,
        private readonly Bots $bots,
        private readonly CustomerNotifier $notifier,
    ) {}

    /**
     * A sign-up's first step: a code to the address — or, when it has an account, an email saying so; the answer is the
     * same either way. How long the code waits.
     *
     * @param array<string, mixed> $input {first_name, last_name?, email, password, referral_code?, captcha?}
     * @throws SignInRefusedException 422 email sign-up off; 503 the shop's email does not go out, or its budget is spent
     * @throws ValidationException 422 under the fields, the captcha's too
     * @throws CaptchaUnavailableException 503 the captcha could not be judged
     * @throws TooManyAttemptsException 429 the address had a code a moment ago, or it — or this network — had its emails
     * @throws MailFailedException 502 the email did not go
     */
    public function register(SignInSite $site, ServerRequestInterface $request, array $input): int
    {
        if (!$site->allowsEmailSignUp()) {
            throw SignInRefusedException::emailOff();
        }
        if (!$this->mailer->ready()) {
            throw SignInRefusedException::mailOff();
        }

        $errors = [];
        $email = self::email($input, $errors);
        $firstName = AccountNames::first($input, $errors);
        $lastName = AccountNames::last($input, $errors);
        $password = self::newPassword($input, 'password', $errors);
        ValidationException::ifAny($errors);
        assert($email !== null && $firstName !== null);

        $this->captcha->check($site, $request, $input, CaptchaAction::SignUp);
        $referralCode = mb_substr(Input::text($input, 'referral_code'), 0, self::REFERRAL_MAX);
        $shop = $this->bots->name($site->shop());
        $this->emailCode($request, $email, function () use ($email, $firstName, $lastName, $password, $referralCode, $shop): void {
            // Hashed either way: an address with an account answers no sooner than one without.
            $hash = Password::hash($password);
            if (User::query()->where('email', $email)->exists()) {
                $this->mailer->send(AlreadyRegistered::message($email, $shop));

                return;
            }
            $code = $this->challenges->issueCode(ChallengePurpose::SignUp, $email, null, [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'password_hash' => $hash,
                'referral_code' => $referralCode,
            ], AuthChallenges::CODE_SECONDS);
            $this->mailer->send(SignUpCode::message($email, $shop, $code));
        });

        return AuthChallenges::CODE_SECONDS;
    }

    /**
     * A sign-up's last step: the code typed back registers the account it kept (Customers::byEmail(), as every newcomer)
     * and signs it in.
     *
     * @param array<string, mixed> $input {email, code}
     * @throws SignInRefusedException 422 email sign-up off; 409 the address has an account since
     * @throws ValidationException 422 on `email`; on `code`: wrong, expired, spent or tried out
     * @throws TooManyAttemptsException 429 while the address or the account must wait
     */
    public function verify(SignInSite $site, ServerRequestInterface $request, array $input): SignedIn
    {
        if (!$site->allowsEmailSignUp()) {
            throw SignInRefusedException::emailOff();
        }

        $errors = [];
        $email = self::email($input, $errors);
        ValidationException::ifAny($errors);
        assert($email !== null);

        $account = $this->code(ChallengePurpose::SignUp, $email, $request, $input);
        if (!is_string($account['password_hash'] ?? null)) {
            throw ValidationException::on('code', self::WRONG_CODE);
        }

        try {
            $user = $this->customers->byEmail(
                $email,
                $account['password_hash'],
                Customers::profile(null, $account['first_name'] ?? null, $account['last_name'] ?? null),
                is_string($account['referral_code'] ?? null) && $account['referral_code'] !== '' ? $account['referral_code'] : null,
            );
        } catch (UniqueConstraintViolationException) {
            throw SignInRefusedException::emailTaken();
        }

        return $this->sessions->open($user, $request, SignInMethod::Password);
    }

    /**
     * A sign-in with an address and its password; for an account with two-factor sign-in, its second step's challenge.
     * The hash is made again when PHP's default cost moved on.
     *
     * @param array<string, mixed> $input {email, password, captcha?}
     * @throws SignInRefusedException 401 not the address and password of an account; 403 banned
     * @throws ValidationException 422 under the fields, the captcha's too
     * @throws CaptchaUnavailableException 503 the captcha could not be judged
     * @throws TooManyAttemptsException 429 while the address or the account must wait
     */
    public function login(SignInSite $site, ServerRequestInterface $request, array $input): SignedIn|TwoFactorChallenge
    {
        $errors = [];
        $email = self::email($input, $errors);
        $password = Input::password($input, 'password');
        if ($password === '') {
            $errors['password'][] = 'رمز عبور را وارد کنید.';
        }
        ValidationException::ifAny($errors);
        assert($email !== null);

        $this->throttle->check(SignInThrottle::WEBSITE, $request, $email);
        $this->captcha->check($site, $request, $input, CaptchaAction::SignIn);

        $user = User::query()->where('email', $email)->whereNotNull('password_hash')->first();
        $hash = $user->password_hash ?? self::nobody();
        // Checked against a hash either way: an address without an account answers no sooner than a wrong password.
        if (!$this->throttle->password(SignInThrottle::WEBSITE, $request, $email, static fn(): bool => password_verify($password, $hash) && $user !== null)) {
            throw SignInRefusedException::wrongEmailOrPassword();
        }
        assert($user !== null);
        if ($user->isBanned()) {
            throw SignInRefusedException::banned();
        }
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $user->password_hash = Password::hash($password);
        }
        $this->customers->seen($user);

        return $user->hasTwoFactor() ? $this->secondStep($user) : $this->sessions->open($user, $request, SignInMethod::Password);
    }

    /**
     * A forgotten password: a code to an address with an account, or a "no account here" email (NoAccount, once a day an
     * address — later asks that day send nothing but count the same). The answer is the same either way. How long a
     * code waits.
     *
     * @param array<string, mixed> $input {email, captcha?}
     * @throws SignInRefusedException 503 the shop's email does not go out, or its budget is spent
     * @throws ValidationException 422 on `email`, on `captcha`
     * @throws CaptchaUnavailableException 503 the captcha could not be judged
     * @throws TooManyAttemptsException 429 the address had a code a moment ago, or it — or this network — had its emails
     * @throws MailFailedException 502 the email did not go
     */
    public function forgot(SignInSite $site, ServerRequestInterface $request, array $input): int
    {
        if (!$this->mailer->ready()) {
            throw SignInRefusedException::mailOff();
        }
        $errors = [];
        $email = self::email($input, $errors);
        ValidationException::ifAny($errors);
        assert($email !== null);

        $this->captcha->check($site, $request, $input, CaptchaAction::PasswordReset);
        $shop = $this->bots->name($site->shop());
        $this->emailCode($request, $email, function () use ($email, $shop): void {
            $user = User::query()->where('email', $email)->first();
            if ($user === null) {
                $this->throttle->noAccountEmail($email, fn() => $this->mailer->send(NoAccount::message($email, $shop)));

                return;
            }
            $code = $this->challenges->issueCode(ChallengePurpose::PasswordReset, $email, $user, [], AuthChallenges::CODE_SECONDS);
            $this->mailer->send(PasswordResetCode::message($email, $shop, $code));
        });

        return AuthChallenges::CODE_SECONDS;
    }

    /**
     * A new password with the code forgot() sent: set, every session of the account ended, this device signed in, the
     * customer told. With two-factor sign-in on, nothing changes yet: the new hash waits in the second step's challenge
     * and only that step sets it (TwoFactor::signIn()).
     *
     * @param array<string, mixed> $input {email, code, password}
     * @throws SignInRefusedException 403 banned
     * @throws ValidationException 422 under the fields; on `code`: wrong, expired, spent or tried out
     * @throws TooManyAttemptsException 429 while the address or the account must wait
     */
    public function reset(SignInSite $site, ServerRequestInterface $request, array $input): SignedIn|TwoFactorChallenge
    {
        $errors = [];
        $email = self::email($input, $errors);
        $password = self::newPassword($input, 'password', $errors);
        ValidationException::ifAny($errors);
        assert($email !== null);

        $this->code(ChallengePurpose::PasswordReset, $email, $request, $input);
        $user = User::query()->where('email', $email)->first() ?? throw ValidationException::on('code', self::WRONG_CODE);
        if ($user->isBanned()) {
            throw SignInRefusedException::banned();
        }
        if ($user->hasTwoFactor()) {
            return $this->secondStep($user, [TwoFactor::NEW_PASSWORD => Password::hash($password)]);
        }

        $user->password_hash = Password::hash($password);
        $this->customers->seen($user);
        $this->sessions->endAll($user);
        $this->notifier->passwordChanged($user);

        return $this->sessions->open($user, $request, SignInMethod::Password);
    }

    /**
     * The signed-in customer sets or changes their password. The account needs an email (the password signs in with it);
     * a current password, when there is one, must be typed and right (confirm()). Every other session ends; the customer
     * is notified.
     *
     * @param array<string, mixed> $input {current_password?, password}
     * @throws AccountRefusedException 422 no email on the account
     * @throws ValidationException 422 under the fields
     * @throws TooManyAttemptsException 429 while the address or the account must wait
     */
    public function changePassword(User $user, CustomerSession $current, ServerRequestInterface $request, array $input): User
    {
        if ($user->email === null) {
            throw AccountRefusedException::needsEmail();
        }

        $errors = [];
        $password = self::newPassword($input, 'password', $errors);
        $typed = Input::password($input, 'current_password');
        if ($user->password_hash !== null && $typed === '') {
            $errors['current_password'][] = 'رمز عبور فعلی را وارد کنید.';
        }
        ValidationException::ifAny($errors);
        if ($user->password_hash !== null) {
            $this->confirm($user, $request, $typed, 'current_password');
        }

        $user->forceFill(['password_hash' => Password::hash($password)])->save();
        $this->sessions->endOthers($user, $current);
        $this->notifier->passwordChanged($user);

        return $user;
    }

    /**
     * Check the account's password typed again before a change it guards. A wrong one is refused under `$field` and counts
     * as a failed sign-in against the address and the account, so this is no way around the sign-in throttle.
     *
     * @throws ValidationException 422 on `$field`
     * @throws TooManyAttemptsException 429 while the address or the account must wait
     */
    public function confirm(User $user, ServerRequestInterface $request, string $password, string $field): void
    {
        if ($password === '') {
            throw ValidationException::on($field, self::NO_PASSWORD);
        }
        $hash = $user->password_hash;
        if (!$this->throttle->password(SignInThrottle::WEBSITE, $request, $user->email, static fn(): bool => $hash !== null && password_verify($password, $hash))) {
            throw ValidationException::on($field, self::WRONG_PASSWORD);
        }
    }

    /**
     * Email a code to an address a signed-in customer adds to their account (the account has none), keeping the hash of
     * the password chosen for it. The code goes whether or not another account has the address: it is proven first. How
     * long the code waits.
     *
     * @param array<string, mixed> $input {email, password}
     * @throws AccountRefusedException 422 the account has an email
     * @throws SignInRefusedException 503 the shop's email does not go out, or its budget is spent
     * @throws ValidationException 422 under the fields
     * @throws TooManyAttemptsException 429 the address had a code a moment ago, or it, this network or this account had its emails
     * @throws MailFailedException 502 the email did not go
     */
    public function sendLinkCode(SignInSite $site, ServerRequestInterface $request, User $user, array $input): int
    {
        if ($user->email !== null) {
            throw AccountRefusedException::hasEmail();
        }
        if (!$this->mailer->ready()) {
            throw SignInRefusedException::mailOff();
        }
        $errors = [];
        $email = self::email($input, $errors);
        $password = self::newPassword($input, 'password', $errors);
        ValidationException::ifAny($errors);
        assert($email !== null);

        $shop = $this->bots->name($site->shop());
        $this->emailCode($request, $email, function () use ($email, $user, $password, $shop): void {
            $code = $this->challenges->issueCode(ChallengePurpose::LinkEmail, $email, $user, ['password_hash' => Password::hash($password)], AuthChallenges::CODE_SECONDS);
            $this->mailer->send(LinkEmailCode::message($email, $shop, $code));
        }, $user);

        return AuthChallenges::CODE_SECONDS;
    }

    /**
     * The code sendLinkCode() sent, typed back by the account it was sent for: the address proven theirs, and the hash of
     * the password they chose for it.
     *
     * @param array<string, mixed> $input {email, code}
     * @return array{email: string, password_hash: string}
     * @throws AccountRefusedException 422 the account has an email
     * @throws ValidationException 422 on `email`; on `code`: wrong, expired, spent, tried out or another account's
     * @throws TooManyAttemptsException 429 while the address or the account must wait
     */
    public function proveLink(ServerRequestInterface $request, User $user, array $input): array
    {
        if ($user->email !== null) {
            throw AccountRefusedException::hasEmail();
        }
        $errors = [];
        $email = self::email($input, $errors);
        ValidationException::ifAny($errors);
        assert($email !== null);

        $kept = $this->code(ChallengePurpose::LinkEmail, $email, $request, $input, $user);
        if (!is_string($kept['password_hash'] ?? null)) {
            throw ValidationException::on('code', self::WRONG_CODE);
        }

        return ['email' => $email, 'password_hash' => $kept['password_hash']];
    }

    /**
     * Send an email to `$address` by `$send`, its budgets counted first (SignInThrottle::emailing(); `$askedBy`, a
     * signed-in customer adding an email, has a budget too). The address's turn is taken before the mail server is asked,
     * so requests that come meanwhile send nothing of their own; everything counted is given back when no email went.
     *
     * @param \Closure(): void $send
     * @throws TooManyAttemptsException 429 the address had a code a moment ago, or it, the network or the account had its emails
     * @throws SignInRefusedException 503 the shop's or the installation's budget is spent
     * @throws MailFailedException 502 the email did not go
     */
    private function emailCode(ServerRequestInterface $request, string $address, \Closure $send, ?User $askedBy = null): void
    {
        $this->throttle->emailing($request, $address, $askedBy?->id);

        try {
            $send();
        } catch (\Throwable $e) {
            $this->throttle->emailNotSent($request, $address, $askedBy?->id);

            throw $e;
        }
    }

    /**
     * A password sign-in's second step: a challenge for the authenticator app's code (TwoFactor::signIn()), keeping what
     * the sign-in sets once it opens — a reset's new password.
     *
     * @param array<string, string> $payload
     */
    private function secondStep(User $user, array $payload = []): TwoFactorChallenge
    {
        return new TwoFactorChallenge($this->challenges->issue(ChallengePurpose::TwoFactor, null, $user, $payload, TwoFactor::CHALLENGE_SECONDS), TwoFactor::CHALLENGE_SECONDS);
    }

    /**
     * Take back the code typed for `$email` (with `$holder`, only one sent for that account): what was kept with it.
     *
     * Something that is not six digits takes no try off the real code nor off the budget. A six-digit code is counted
     * against the address's (and the holder's) budget of wrong codes before it is judged — spent, a 429 whatever it is,
     * and the address's account notified once a day — and gives its count back when right. A wrong one also counts as a
     * failed sign-in.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     * @throws ValidationException 422 on `code`
     * @throws TooManyAttemptsException
     */
    private function code(ChallengePurpose $purpose, string $email, ServerRequestInterface $request, array $input, ?User $holder = null): array
    {
        $code = Persian::latinDigits(Input::text($input, 'code'));
        if ($code === '') {
            throw ValidationException::on('code', 'کدی را که به ایمیل‌تان فرستاده شد وارد کنید.');
        }
        $this->throttle->check(SignInThrottle::WEBSITE, $request, $email);

        $kept = null;
        if (preg_match('/^\d{6}$/', $code) === 1) {
            $this->throttle->emailedCode($email, $holder?->id, fn() => $this->codesFailed($email));
            $kept = $this->challenges->checkCode($purpose, $email, $code, $holder);
            if ($kept !== null) {
                $this->throttle->emailedCodeOpened($email, $holder?->id);
            }
        }
        if ($kept === null) {
            $this->throttle->failed(SignInThrottle::WEBSITE, $request, $email);

            throw ValidationException::on('code', self::WRONG_CODE);
        }

        return $kept;
    }

    /** The codes emailed to `$address` ran out their budget: notify its account in this shop, if any. */
    private function codesFailed(string $address): void
    {
        $owner = User::query()->where('email', $address)->first();
        if ($owner !== null) {
            $this->notifier->emailCodesFailed($owner);
        }
    }

    /**
     * A new password typed under `$field`, or why it cannot be one (App\Support\Password) under it.
     *
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     */
    private static function newPassword(array $input, string $field, array &$errors): string
    {
        $password = Input::password($input, $field);
        $problem = Password::problem($password);
        if ($problem !== null) {
            $errors[$field][] = $problem;
        }

        return $password;
    }

    /** The NOBODY hash at this PHP's default cost (else the dearest), so checking it costs what a real one does. */
    private static function nobody(): string
    {
        foreach (self::NOBODY as $hash) {
            if (!password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                return $hash;
            }
        }

        return self::NOBODY[array_key_last(self::NOBODY)];
    }

    /**
     * The form's address as the shop keeps one (App\Support\Email); null, with why under `email`.
     *
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     */
    private static function email(array $input, array &$errors): ?string
    {
        $typed = Input::text($input, 'email');
        $problem = Email::problem($typed);
        if ($problem !== null) {
            $errors['email'][] = $problem;

            return null;
        }

        return Email::of($typed);
    }
}
