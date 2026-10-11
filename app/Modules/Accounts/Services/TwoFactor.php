<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Database\ChangeFeed;
use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Security\Encrypter;
use App\Core\Security\ReadableCode;
use App\Core\Security\Totp;
use App\Modules\Accounts\Contracts\SignInSite;
use App\Modules\Accounts\DTO\SignedIn;
use App\Modules\Accounts\Enums\ChallengePurpose;
use App\Modules\Accounts\Enums\SignInMethod;
use App\Modules\Accounts\Exceptions\AccountRefusedException;
use App\Modules\Auth\Actor;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Auth\Services\SignInThrottle;
use App\Modules\Bots\Services\Bots;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\Customers;
use App\Support\Input;
use App\Support\Persian;
use Illuminate\Database\Eloquent\Builder;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Two-factor sign-in on the website: the email-and-password sign-in's second step, an authenticator app's code (RFC 6238,
 * Core\Security\Totp). Telegram and Google sign-ins are not asked — the provider signed them in.
 *
 * On: setup() keeps a new secret waiting, enable() takes the app's first code and hands out RECOVERY_CODES one-time
 * codes (shown once, kept as keyed hashes). Then a password sign-in answers a challenge (EmailSignIn) that signIn()
 * opens with the app's code or a recovery code. Every code tried is counted first against the account's second-step
 * budget (SignInThrottle::secondStep()), so someone holding the password cannot guess on from many addresses; no app
 * code opens twice (`totp_last_step`), and a recovery code is spent. A reset's new password waits in the challenge and
 * is set only when the second step opens. Off: disable() with the password, or turnOff() by support. The customer is
 * told every time.
 */
final class TwoFactor
{
    /** How long a new secret waits for the app's first code. */
    public const SETUP_SECONDS = 900;

    /** How long a password sign-in's second step waits for its code. */
    public const CHALLENGE_SECONDS = 300;

    /** How many recovery codes, and how long each is (ReadableCode). */
    public const RECOVERY_CODES = 10;
    public const RECOVERY_LENGTH = 10;

    /** Its columns on `users`: they go with the email when it is removed, and move with it in a merge. */
    public const COLUMNS = ['totp_secret', 'totp_recovery_codes', 'totp_enabled_at', 'totp_last_step'];

    /** The payload key a reset's challenge keeps the new password's hash under, until the second step opens. */
    public const NEW_PASSWORD = 'password_hash';

    public const SETUP_GONE = 'زمان راه‌اندازی ورود دو مرحله‌ای گذشته است؛ آن را از اول راه‌اندازی کنید.';
    public const WRONG_CODE = 'کد درست نیست؛ کدی را که برنامه احراز هویت همین حالا نشان می‌دهد وارد کنید.';
    public const WRONG_SIGN_IN_CODE = 'کد درست نیست؛ کد شش‌رقمی برنامه احراز هویت یا یکی از کدهای بازیابی را وارد کنید.';
    public const CODE_MISSING = 'کد برنامه احراز هویت یا یکی از کدهای بازیابی را وارد کنید.';

    public function __construct(
        private readonly AuthChallenges $challenges,
        private readonly EmailSignIn $email,
        private readonly CustomerSessions $sessions,
        private readonly Customers $customers,
        private readonly SignInThrottle $throttle,
        private readonly Bots $bots,
        private readonly CustomerNotifier $notifier,
        private readonly Encrypter $encrypter,
        private readonly ChangeFeed $changes,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * A new secret for the customer's app, kept waiting for its first code (enable()) in place of any earlier one, and its
     * otpauth:// address: the shop's name as issuer, the account's email as name.
     *
     * @return array{secret: string, uri: string}
     * @throws AccountRefusedException 422 no email and password to ask it of, or it is on already
     */
    public function setup(SignInSite $site, User $user): array
    {
        self::askable($user);
        $secret = Totp::secret();
        $this->challenges->hold(ChallengePurpose::TotpSetup, $user, ['secret' => $secret], self::SETUP_SECONDS);

        return ['secret' => $secret, 'uri' => Totp::uri($secret, $this->bots->name($site->shop()), (string) $user->email)];
    }

    /**
     * Turn it on with the app's first code for the secret setup() gave; answers the recovery codes, shown this once. The
     * code's step is taken, so the next sign-in needs a later one. The customer is notified.
     *
     * @param array<string, mixed> $input {code}
     * @return list<string>
     * @throws AccountRefusedException 422 no email and password to ask it of, or it is on already
     * @throws ValidationException 422 on `code`: none, wrong, or no secret waiting for it (expired, or tried out)
     */
    public function enable(User $user, array $input): array
    {
        self::askable($user);
        $code = self::typed($input);
        if ($code === '') {
            throw ValidationException::on('code', 'کدی را که برنامه احراز هویت نشان می‌دهد وارد کنید.');
        }
        $pending = $this->challenges->held(ChallengePurpose::TotpSetup, $user) ?? throw ValidationException::on('code', self::SETUP_GONE);

        $step = null;
        $kept = preg_match('/^\d{6}$/', $code) !== 1 ? null : $this->challenges->attempt($pending, static function (array $payload) use ($code, &$step): bool {
            $step = is_string($payload['secret'] ?? null) ? Totp::verify($payload['secret'], $code) : null;

            return $step !== null;
        });
        if ($kept === null || !is_string($kept['secret'] ?? null)) {
            throw ValidationException::on('code', self::WRONG_CODE);
        }

        $codes = [];
        while (count($codes) < self::RECOVERY_CODES) {
            $codes[ReadableCode::make(self::RECOVERY_LENGTH)] = true;
        }
        $codes = array_map(strval(...), array_keys($codes));
        $user->forceFill([
            'totp_secret' => $kept['secret'],
            'totp_recovery_codes' => json_encode(array_map($this->hashOf(...), $codes), JSON_THROW_ON_ERROR),
            'totp_enabled_at' => now(),
            'totp_last_step' => $step,
        ])->save();
        $this->notifier->twoFactorEnabled($user);

        return $codes;
    }

    /**
     * The customer turns it off with the account's password (EmailSignIn::confirm(): a wrong one is a failed sign-in).
     * The customer is notified.
     *
     * @param array<string, mixed> $input {password}
     * @throws AccountRefusedException 422 it is not on
     * @throws ValidationException 422 on `password`
     * @throws TooManyAttemptsException 429 while the address or the account must wait
     */
    public function disable(User $user, ServerRequestInterface $request, array $input): User
    {
        if (!$user->hasTwoFactor()) {
            throw AccountRefusedException::twoFactorOff();
        }
        $this->email->confirm($user, $request, Input::password($input, 'password'), 'password');
        self::clear($user)->save();
        $this->notifier->twoFactorTurnedOff($user);

        return $user;
    }

    /**
     * Support turns it off for a customer who lost the phone it was on (a panel, or a shop admin on the website). The
     * customer is notified and the log names who did it.
     *
     * @throws AccountRefusedException 422 it is not on
     */
    public function turnOff(User $user, Actor $actor): void
    {
        if (!$user->hasTwoFactor()) {
            throw AccountRefusedException::twoFactorOff();
        }
        self::clear($user)->save();
        $this->logger->info('{reviewer} turned two-factor sign-in off for customer #{customer}', ['reviewer' => $actor->reviewer, 'customer' => $user->id]);
        $this->notifier->twoFactorDisabled($user);
    }

    /**
     * A password sign-in's second step: the challenge and the app's code (Persian digits too) or a recovery code. A wrong
     * code is a 422 on `code`, counted on the challenge (AuthChallenges::CODE_ATTEMPTS), on the second-step budget and as a
     * failed sign-in. A challenge that opens nothing any more (unknown, expired, tried out, two-factor off since) is a 401:
     * start with the password again. A reset's new password kept in the challenge is set now, every session ended and the
     * customer told.
     *
     * @param array<string, mixed> $input {challenge, code}
     * @throws SignInRefusedException 401 the challenge opens nothing; 403 banned
     * @throws ValidationException 422 a field missing; on `code`: wrong
     * @throws TooManyAttemptsException 429 while the address or the account must wait, or its second-step budget is spent
     */
    public function signIn(ServerRequestInterface $request, array $input): SignedIn
    {
        $secret = Input::text($input, 'challenge');
        if ($secret === '') {
            throw ValidationException::on('challenge', 'challenge ورود را هم بفرستید؛ همان که POST /auth/login برگرداند.');
        }
        $code = self::typed($input);
        if ($code === '') {
            throw ValidationException::on('code', self::CODE_MISSING);
        }
        $this->throttle->check(SignInThrottle::WEBSITE, $request);

        $challenge = $this->challenges->find(ChallengePurpose::TwoFactor, $secret);
        $user = $challenge?->user_id === null ? null : User::query()->find($challenge->user_id);
        if ($challenge === null || $user === null || !$user->hasTwoFactor()) {
            $this->throttle->failed(SignInThrottle::WEBSITE, $request);

            throw SignInRefusedException::signInSpent();
        }
        $this->throttle->check(SignInThrottle::WEBSITE, $request, $user->email);

        $kept = $this->tryCode($user, $request, $code, fn(): ?array => $this->challenges->attempt($challenge, fn(): bool => $this->passes($user, $code)));
        if ($user->isBanned()) {
            throw SignInRefusedException::banned();
        }

        // A reset's new password, kept until its second step: set now.
        $password = $kept[self::NEW_PASSWORD] ?? null;
        if (is_string($password)) {
            $user->forceFill(['password_hash' => $password])->save();
            $this->sessions->endAll($user);
            $this->notifier->passwordChanged($user);
        }
        $this->customers->seen($user);

        return $this->sessions->open($user, $request, SignInMethod::PasswordAndCode);
    }

    /**
     * The second step asked of a signed-in customer proving their password again (Reauthentication): checked and counted
     * as a sign-in's.
     *
     * @param array<string, mixed> $input {code}
     * @throws ValidationException 422 on `code`: none, or wrong
     * @throws TooManyAttemptsException 429 while the address or the account must wait, or its second-step budget is spent
     */
    public function confirm(User $user, ServerRequestInterface $request, array $input): void
    {
        $code = self::typed($input);
        if ($code === '') {
            throw ValidationException::on('code', self::CODE_MISSING);
        }

        $this->tryCode($user, $request, $code, fn(): ?array => $this->passes($user, $code) ? [] : null);
    }

    /** The account with its two-factor columns emptied, left to save. */
    private static function clear(User $user): User
    {
        return $user->forceFill(array_fill_keys(self::COLUMNS, null));
    }

    /**
     * Try a second-step code; answers what it opened (a challenge's payload). A code of neither shape is judged by nothing
     * and spends no budget. Any other is counted first against the account's second-step budget (a 429 once spent, the
     * customer told the first time a day), then judged by `$opens`: right gives the count back. Anything wrong counts as a
     * failed sign-in.
     *
     * @param \Closure(): (array<string, mixed>|null) $opens What the code opens; null when it is wrong
     * @return array<string, mixed>
     * @throws ValidationException 422 on `code`
     * @throws TooManyAttemptsException 429
     */
    private function tryCode(User $user, ServerRequestInterface $request, string $code, \Closure $opens): array
    {
        if (self::shaped($code)) {
            $this->throttle->secondStep($user->id, fn() => $this->notifier->secondStepLocked($user));
            $opened = $opens();
            if ($opened !== null) {
                $this->throttle->secondStepOpened($user->id);

                return $opened;
            }
        }
        $this->throttle->failed(SignInThrottle::WEBSITE, $request, $user->email);

        throw ValidationException::on('code', self::WRONG_SIGN_IN_CODE);
    }

    /**
     * Whether `$code` opens the second step now: an app code for a step later than the last one taken, or a recovery code.
     * Each is taken by one conditional UPDATE (the step, or the list as it was read), so of two sign-ins with one code only
     * one gets it. Written quietly: no panel shows either.
     */
    private function passes(User $user, string $code): bool
    {
        if (preg_match('/^\d{6}$/', $code) === 1) {
            $step = Totp::verify((string) $user->totp_secret, $code, $user->totp_last_step);

            return $step !== null && $this->changes->quietly(static fn(): int => User::query()->whereKey($user->id)
                ->where(static fn(Builder $taken) => $taken->whereNull('totp_last_step')->orWhere('totp_last_step', '<', $step))
                ->update(['totp_last_step' => $step])) === 1;
        }

        $hash = $this->hashOf($code);
        $hashes = json_decode((string) $user->totp_recovery_codes, true);
        $kept = $user->getRawOriginal('totp_recovery_codes');
        if (!is_array($hashes) || !is_string($kept)) {
            return false;
        }
        $left = array_values(array_filter($hashes, static fn(mixed $one): bool => !is_string($one) || !hash_equals($one, $hash)));
        if (count($left) === count($hashes)) {
            return false;
        }

        $user->totp_recovery_codes = json_encode($left, JSON_THROW_ON_ERROR);
        $spent = $user->getAttributes()['totp_recovery_codes'];
        if ($this->changes->quietly(static fn(): int => User::query()->whereKey($user->id)->where('totp_recovery_codes', $kept)->toBase()->update(['totp_recovery_codes' => $spent])) !== 1) {
            return false;
        }
        $user->syncOriginalAttribute('totp_recovery_codes');

        return true;
    }

    /** A recovery code's keyed hash: ten characters are too few to keep under a bare hash. */
    private function hashOf(string $code): string
    {
        return $this->encrypter->mac("totp_recovery|{$code}");
    }

    /**
     * Refuse unless the account signs in with an email and a password and does not have two-factor sign-in on yet.
     *
     * @throws AccountRefusedException
     */
    private static function askable(User $user): void
    {
        if ($user->email === null || $user->password_hash === null) {
            throw AccountRefusedException::twoFactorNeedsPassword();
        }
        if ($user->hasTwoFactor()) {
            throw AccountRefusedException::twoFactorOn();
        }
    }

    /**
     * The typed code normalised: Latin digits, no spaces or dashes (as apps and printed sheets show them), upper case.
     *
     * @param array<string, mixed> $input
     */
    private static function typed(array $input): string
    {
        return strtoupper((string) preg_replace('/[\s\-]+/u', '', Persian::latinDigits(Input::text($input, 'code'))));
    }

    /** A code of either shape: an app's six digits, or a recovery code's RECOVERY_LENGTH characters of ReadableCode's. */
    private static function shaped(string $code): bool
    {
        return preg_match('/^(\d{6}|[' . ReadableCode::ALPHABET . ']{' . self::RECOVERY_LENGTH . '})$/', $code) === 1;
    }
}
