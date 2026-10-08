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
 * Two-factor sign-in for the shop's website — the email and password sign-in's second step, a code of an authenticator
 * app (RFC 6238, Core\Security\Totp): the customer turns it on with a new secret (setup(): kept waiting, an auth challenge
 * of theirs, SETUP_SECONDS — a new one replaces it) and the app's first code (enable()), and gets RECOVERY_CODES one-time
 * codes for the day the phone is lost, shown once, kept as keyed hashes (Encrypter::mac(), as the emailed codes are). A
 * password sign-in then answers a challenge (EmailSignIn), which signIn() opens with the app's code or a recovery code
 * — the challenge takes CODE_ATTEMPTS codes, each wrong one counted against the address and the account too, and every
 * code tried for the account is counted first against its second-step budget (SignInThrottle::secondStep(): whoever has
 * its password guesses no further, from however many addresses at once — its customer told the first time a day it runs
 * out); no code opens twice (`totp_last_step`), a recovery code is spent. A reset's new password waits in its challenge
 * and is set only as the second step opens. Google's and Telegram's sign-ins are not asked: the provider signed them in.
 * Off again with the account's password (disable()), or by support for one who lost the phone it was on (turnOff():
 * logged with who did). The customer is told whichever way it goes on or off. A password reset leaves it on.
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

    /** What an account keeps of it — gone with it, and with the email it is asked of (Identities); carried with that email (AccountMerger). */
    public const COLUMNS = ['totp_secret', 'totp_recovery_codes', 'totp_enabled_at', 'totp_last_step'];

    /** What a second step's challenge keeps of a reset: the new password's hash, set once the second step opens. */
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
     * A new secret for the customer's authenticator app — kept waiting for its first code (enable()), any earlier one
     * gone — and the otpauth:// address the app takes it by: the shop's name its issuer, the account's email its name.
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
     * Two-factor sign-in turned on by the app's first code for the secret setup() gave: its recovery codes, shown this
     * once. The code is taken once — the next sign-in asks a later one. The customer is told.
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
     * Two-factor sign-in turned off by the customer, the account's password typed again — a wrong one counted as a
     * failed sign-in (EmailSignIn::confirm()). The customer is told.
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
     * Support turns it off for a customer who lost the phone it was on (either panel, or one of the shop's admins on its
     * website): the password alone signs them in from now on. The customer is told (CustomerNotifier: in Telegram and by email, and on their website), the log says
     * who did it.
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
     * A password sign-in's second step: the challenge it answered, and the code of the account's app — six digits,
     * Persian ones too — or one of its recovery codes (spent). A wrong code is a 422 on `code`, counted on the challenge
     * (CODE_ATTEMPTS codes at most), against the account's second-step budget, and against the address and the account;
     * a challenge that opens nothing any more (unknown, expired, tried out, two-factor turned off since) asks for the
     * password again (a 401). A reset's new password the challenge kept is set as it opens, every other session of the
     * account ended and the customer told.
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
     * The account's second step asked again of a signed-in customer who proves the account theirs by its password
     * (Reauthentication): its app's code or a recovery code, as a sign-in's — counted the same, against the same budget.
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

    /** The account without two-factor sign-in — its secret, its recovery codes, its mark —, left to save. */
    private static function clear(User $user): User
    {
        return $user->forceFill(array_fill_keys(self::COLUMNS, null));
    }

    /**
     * A second-step code tried for the account. One of neither shape — no six digits, no recovery code — is judged by
     * nothing and takes no try off anything, but counts as a failed sign-in; any other is counted first against the
     * account's second-step budget (SignInThrottle::secondStep(): a 429 once spent, its customer told the first time a
     * day), then `$opens` judges it: a right one gives its count back, a wrong one counts against the address and the
     * account too. What the code opened (a challenge's payload).
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
     * Whether `$code` opens the account's second step now: a code of its app for a step later than the last one taken
     * — taken by one conditional UPDATE, so of two sign-ins with it one gets it —, or one of its recovery codes —
     * spent by one conditional UPDATE on the list as it was read. Quietly: no panel shows either.
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

    /** What is kept of a recovery code: its keyed hash, as the emailed codes are kept. */
    private function hashOf(string $code): string
    {
        return $this->encrypter->mac("totp_recovery|{$code}");
    }

    /**
     * Two-factor sign-in can be asked of the account: it signs in with an email and a password, and does not ask it yet.
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
     * The code as typed: Latin digits, without the spaces and dashes an app or a sheet shows it with, a recovery code in capitals.
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
