<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Captcha\CaptchaUnavailableException;
use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Sms\Sms;
use App\Core\Sms\SmsFailedException;
use App\Modules\Accounts\Contracts\SignInSite;
use App\Modules\Accounts\DTO\MergeOffer;
use App\Modules\Accounts\DTO\SignedIn;
use App\Modules\Accounts\DTO\TwoFactorChallenge;
use App\Modules\Accounts\Enums\CaptchaAction;
use App\Modules\Accounts\Enums\ChallengePurpose;
use App\Modules\Accounts\Enums\SignInMethod;
use App\Modules\Accounts\Exceptions\AccountRefusedException;
use App\Modules\Accounts\Http\Customer;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Auth\Services\SignInThrottle;
use App\Modules\Bots\CurrentBot;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\Customers;
use App\Support\Input;
use App\Support\Mobile;
use App\Support\Persian;
use Illuminate\Database\Eloquent\Builder;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Signing in on the website with a six-digit code texted to an Iranian mobile (App\Support\Mobile) — while the site
 * takes it (SignInSite::allowsPhoneSignIn()):
 * - start(): the code to the number, whether or not the number has an account — the answer is the same either way.
 * - verify(): the code typed back signs in the shop's customer whose verified mobile it is (holderOf()) — offered a merge
 *   with another account holding it too (mergeOffer()) —, or registers a newcomer with the number alone
 *   (Customers::byPhone(), as every newcomer). The number is a way in of its own, but a weaker one than Telegram's or
 *   Google's: a SIM swapped, or a number the carrier gave someone else, reads its code. So an account with two-factor
 *   sign-in is asked its second step, as a password is (secondStep()), and a session it opens alone is no strong one
 *   (SignInMethod::strong()).
 * - a signed-in customer's own: a number added to their account (sendLinkCode(), proveLink()) and theirs proven again
 *   (sendReauthCode(), proveReauth()), a code good on the session that asked for it alone.
 *
 * Every code lives CODE_SECONDS, is kept only as a keyed hash and takes AuthChallenges::CODE_ATTEMPTS tries; a new one for
 * the number replaces the last once the provider took it. Every SMS is held to its budgets before it goes, given back
 * when the provider did not take it (text()); a six-digit code typed while one waits is counted against the number's
 * budget of wrong codes before it is judged (code()), and a wrong one counts as a failed sign-in. The code is never
 * logged.
 */
final class PhoneSignIn
{
    /** How long a texted code may be typed back. */
    public const CODE_SECONDS = 300;

    /** How soon the same number may be texted another code: the site's resend timer. */
    public const RESEND_SECONDS = SignInThrottle::CODE_EVERY_SECONDS;

    public const CODE_MISSING = 'کدی را که با پیامک فرستاده شد وارد کنید.';

    /** Proving the account's mobile again where it has none an SMS reaches. */
    public const NO_PHONE = 'این حساب شماره موبایلی ندارد که پیامک به آن برسد؛ با روش ورود دیگری تایید کنید.';

    /** Proving by SMS an account with two-factor sign-in: the number alone does not stand in for its password and code. */
    public const TWO_FACTOR_FIRST = 'این حساب ورود دو مرحله‌ای دارد؛ با رمز عبور و کد برنامه احراز هویت تایید کنید.';

    /** What a provider's words in a log line carry in place of the code. */
    private const CODE_REDACTED = '••••••';

    public function __construct(
        private readonly AuthChallenges $challenges,
        private readonly Customers $customers,
        private readonly ProviderSignIn $provider,
        private readonly MergeOffers $offers,
        private readonly SignInThrottle $throttle,
        private readonly Captcha $captcha,
        private readonly Sms $sms,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * A sign-in's first step: a code texted to the number, whether or not it has an account. How long the code waits.
     *
     * @param array<string, mixed> $input {phone, captcha?}
     * @throws SignInRefusedException 422 the site takes no sign-in by SMS; 503 the shop's or the installation's SMS budget is spent
     * @throws ValidationException 422 on `phone`, on `captcha`
     * @throws CaptchaUnavailableException 503 the captcha could not be judged
     * @throws TooManyAttemptsException 429 the number had a code a moment ago, or it — or this network — had its codes
     * @throws SmsFailedException 502 the provider did not take it
     */
    public function start(SignInSite $site, ServerRequestInterface $request, array $input): int
    {
        self::open($site);
        $phone = self::phone($input);
        $this->captcha->check($site, $request, $input, CaptchaAction::PhoneSignIn);
        $this->text($request, $phone, ChallengePurpose::PhoneSignIn, $phone, null);

        return self::CODE_SECONDS;
    }

    /**
     * A sign-in's last step: the code typed back signs in the customer whose verified mobile it is — with the merge offered
     * with another account holding it too (mergeOffer()) —, or registers a newcomer with it (and the site's
     * `referral_code`). An account with two-factor sign-in answers its second step's challenge instead (secondStep()).
     *
     * @param array<string, mixed> $input {phone, code, referral_code?}
     * @throws SignInRefusedException 422 the site takes no sign-in by SMS; 403 banned
     * @throws ValidationException 422 on `phone`; on `code`: wrong, expired, spent or tried out
     * @throws TooManyAttemptsException 429 while the number or this network must wait
     */
    public function verify(SignInSite $site, ServerRequestInterface $request, array $input): SignedIn|TwoFactorChallenge
    {
        self::open($site);
        $phone = self::phone($input);
        $this->code(ChallengePurpose::PhoneSignIn, $phone, $phone, $request, $input);

        $user = self::holderOf($phone) ?? $this->customers->byPhone($phone, ProviderSignIn::referralCode($input));
        if ($user->hasTwoFactor()) {
            return $this->secondStep($user, $phone);
        }
        $signedIn = $this->provider->open($user, $request, SignInMethod::Phone);

        return new SignedIn($signedIn->token, $signedIn->session, $this->mergeOffer($user, $phone));
    }

    /**
     * After a sign-in by a code texted to `$phone` (E.164), the merge offered with another account of the shop holding the
     * number. The code proved the number for every one of them, and an account a sign-in by SMS does not open (holderOf()
     * opens one) may have no other way in: a website newcomer by phone whose number the bot's phone gate later kept on a
     * new Telegram account. Offered as every merge is (MergeOffers, accepted by POST /me/merge on the session just
     * opened), never made unasked: a number given up passes to someone else, who sees what the other account holds before
     * taking it on. The first holder in holderOf()'s order the rules let be one with `$user`; none when they refuse every
     * one — a banned one, two of a kind of way in, two agents — or the other has two-factor sign-in on: the number takes
     * nobody past its second step, as it takes no sign-in past it.
     */
    public function mergeOffer(User $user, string $phone): ?MergeOffer
    {
        foreach (self::holders($phone)->whereKeyNot($user->id)->get() as $other) {
            try {
                return $this->offers->offer($user, $other, ['phone' => $phone], byAddress: true);
            } catch (AccountRefusedException) {
                // Not with this one: the next holder, if any.
            }
        }

        return null;
    }

    /**
     * Text a code to a number a signed-in customer adds to their account (it has none). The code goes whether or not
     * another account has the number: it is proven first. How long the code waits.
     *
     * @param array<string, mixed> $input {phone}
     * @throws AccountRefusedException 422 the account has a number
     * @throws SignInRefusedException 422 the site takes no sign-in by SMS; 503 the SMS budget is spent
     * @throws ValidationException 422 on `phone`
     * @throws TooManyAttemptsException 429 the number had a code a moment ago, or it, this network or this account had its codes
     * @throws SmsFailedException 502
     */
    public function sendLinkCode(SignInSite $site, ServerRequestInterface $request, User $user, array $input): int
    {
        self::open($site);
        if ($user->phone !== null) {
            throw AccountRefusedException::hasPhone();
        }
        $phone = self::phone($input);
        $this->text($request, $phone, ChallengePurpose::LinkPhone, $phone, $user);

        return self::CODE_SECONDS;
    }

    /**
     * The code sendLinkCode() sent, typed back by the account it was sent for: the number (E.164) proven theirs.
     *
     * @param array<string, mixed> $input {phone, code}
     * @throws AccountRefusedException 422 the account has a number
     * @throws SignInRefusedException 422 the site takes no sign-in by SMS
     * @throws ValidationException 422 on `phone`; on `code`: wrong, expired, spent, tried out or another account's
     * @throws TooManyAttemptsException 429
     */
    public function proveLink(SignInSite $site, ServerRequestInterface $request, User $user, array $input): string
    {
        self::open($site);
        if ($user->phone !== null) {
            throw AccountRefusedException::hasPhone();
        }
        $phone = self::phone($input);
        $this->code(ChallengePurpose::LinkPhone, $phone, $phone, $request, $input, $user);

        return $phone;
    }

    /**
     * Text a code to the account's own mobile, to prove it again on this very session (proveReauth()). How long it waits.
     *
     * @throws SignInRefusedException 422 the site takes no sign-in by SMS; 503 the SMS budget is spent
     * @throws ValidationException 422 on `method`: the account has no mobile an SMS reaches, or has two-factor sign-in on
     * @throws TooManyAttemptsException 429
     * @throws SmsFailedException 502
     */
    public function sendReauthCode(SignInSite $site, ServerRequestInterface $request, Customer $customer): int
    {
        self::open($site);
        $phone = self::own($customer->user);
        $this->text($request, $phone, ChallengePurpose::PhoneReauth, self::reauthSubject($customer, $phone), $customer->user);

        return self::CODE_SECONDS;
    }

    /**
     * The code sendReauthCode() texted for this very session, typed back: the account's mobile proven again.
     *
     * @param array<string, mixed> $input {code}
     * @throws SignInRefusedException 422 the site takes no sign-in by SMS
     * @throws ValidationException 422 on `method`: no mobile an SMS reaches, or two-factor sign-in on; on `code`: wrong,
     *                             expired, spent or another session's
     * @throws TooManyAttemptsException 429
     */
    public function proveReauth(SignInSite $site, ServerRequestInterface $request, Customer $customer, array $input): void
    {
        self::open($site);
        $phone = self::own($customer->user);
        $this->code(ChallengePurpose::PhoneReauth, $phone, self::reauthSubject($customer, $phone), $request, $input, $customer->user);
    }

    /**
     * What texting a code answers: that it went, how long it waits, and how soon the same number may ask another — the
     * site's resend timer.
     *
     * @return array{sent: true, expires_in: int, resend_in: int}
     */
    public static function sent(int $expiresIn): array
    {
        return ['sent' => true, 'expires_in' => $expiresIn, 'resend_in' => self::RESEND_SECONDS];
    }

    /**
     * The shop's customer whose verified mobile is `$phone` (E.164). A number is no unique key: one shared with the bot
     * is taken as Telegram vouches for it, and a number given up passes to someone else. So of two holding it, the one
     * with Telegram comes first — Telegram vouched for the number as that very account's (its own contact card), and the
     * bot's account is where the customer's services and wallet are —; then the one seen last, likelier the holder of a
     * number that changed hands; then the oldest. The others are offered as a merge (mergeOffer()).
     */
    public static function holderOf(string $phone): ?User
    {
        return self::holders($phone)->first();
    }

    /** @return Builder<User> The shop's customers whose verified mobile is `$phone`, in holderOf()'s order. */
    private static function holders(string $phone): Builder
    {
        return User::query()
            ->where('phone', $phone)
            ->orderByRaw('telegram_id is null')
            ->orderByDesc('last_seen_at')
            ->orderBy('id');
    }

    /**
     * The second step a code by SMS asks of an account with two-factor sign-in, as a password does: the challenge
     * TwoFactor::signIn() opens with the app's code, keeping the number (TwoFactor::BY_PHONE) for the session's way in and
     * the merge it offers then. Refused for a banned account first, as a password's is.
     *
     * @throws SignInRefusedException 403 banned
     */
    private function secondStep(User $user, string $phone): TwoFactorChallenge
    {
        if ($user->isBanned()) {
            throw SignInRefusedException::banned();
        }
        $this->customers->seen($user);

        return new TwoFactorChallenge($this->challenges->issue(ChallengePurpose::TwoFactor, null, $user, [TwoFactor::BY_PHONE => $phone], TwoFactor::CHALLENGE_SECONDS), TwoFactor::CHALLENGE_SECONDS);
    }

    /**
     * Text a code for `$purpose` about `$subject` to `$phone`, its budgets counted first (SignInThrottle::texting();
     * `$holder`, a signed-in customer, has a budget too) — before the provider is asked, so requests that come meanwhile
     * send nothing of their own; all of it given back when no SMS went, and the last code then left standing
     * (AuthChallenges::issueCode()'s `$deliver`). Why the provider refused is logged, the number masked and the code left
     * out (redacted()); the customer hears only that it did not go.
     *
     * @throws TooManyAttemptsException 429
     * @throws SignInRefusedException 503
     * @throws SmsFailedException 502
     */
    private function text(ServerRequestInterface $request, string $phone, ChallengePurpose $purpose, string $subject, ?User $holder): void
    {
        $this->throttle->texting($request, $phone, $holder?->id);

        $code = null;
        try {
            $this->challenges->issueCode($purpose, $subject, $holder, [], self::CODE_SECONDS, function (string $issued) use ($phone, &$code): void {
                $code = $issued;
                $this->sms->sendCode($phone, $issued);
            });
        } catch (\Throwable $e) {
            $this->throttle->textNotSent($request, $phone, $holder?->id);
            if ($e instanceof SmsFailedException) {
                $this->logger->warning('A sign-in code by SMS to {phone} did not go out (shop {shop}): {reason}', ['phone' => Mobile::masked($phone), 'shop' => CurrentBot::id(), 'reason' => self::redacted($e->reason, $phone, $code)]);
            }

            throw $e;
        }
    }

    /**
     * The provider's words as a log line may carry them: the number masked however they write it (E.164, without its
     * «+», with the trunk «0», the national digits alone), and the code left out.
     */
    private static function redacted(string $reason, string $phone, ?string $code): string
    {
        $national = substr($phone, 3);
        $reason = str_replace([$phone, substr($phone, 1), '0' . $national, $national], Mobile::masked($phone), $reason);

        return $code === null ? $reason : str_replace($code, self::CODE_REDACTED, $reason);
    }

    /**
     * Take back the code typed for `$subject` (with `$holder`, only one texted for that account). Something that is not
     * six digits takes no try off the real code nor off the budget. A six-digit code is counted against the number's
     * (and the holder's) budget of wrong codes before it is judged — spent, a 429 whatever it is — and gives its count
     * back when right; but only while a code waits to be tried (AuthChallenges::liveCode()): with none, a guess opens
     * nothing whatever it is, so it costs the number's holder nothing — or a stranger guessing blind would lock them out of
     * their own codes. A wrong one also counts as a failed sign-in: against the number while a code waited, against this
     * network alone otherwise.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException 422 on `code`
     * @throws TooManyAttemptsException
     */
    private function code(ChallengePurpose $purpose, string $phone, string $subject, ServerRequestInterface $request, array $input, ?User $holder = null): void
    {
        $code = Persian::latinDigits(Input::text($input, 'code'));
        if ($code === '') {
            throw ValidationException::on('code', self::CODE_MISSING);
        }
        $this->throttle->check(SignInThrottle::WEBSITE, $request, $phone);

        $waiting = preg_match('/^\d{6}$/', $code) === 1 && $this->challenges->liveCode($purpose, $subject, $holder) !== null;
        $opened = false;
        if ($waiting) {
            $this->throttle->textedCode($phone, $holder?->id);
            $opened = $this->challenges->checkCode($purpose, $subject, $code, $holder) !== null;
            if ($opened) {
                $this->throttle->textedCodeOpened($phone, $holder?->id);
            }
        }
        if (!$opened) {
            $this->throttle->failed(SignInThrottle::WEBSITE, $request, $waiting ? $phone : null);

            throw ValidationException::on('code', EmailSignIn::WRONG_CODE);
        }
    }

    /**
     * Refuse every step while the site takes no sign-in by SMS.
     *
     * @throws SignInRefusedException 422
     */
    private static function open(SignInSite $site): void
    {
        if (!$site->allowsPhoneSignIn()) {
            throw SignInRefusedException::phoneOff();
        }
    }

    /**
     * The form's mobile number in E.164, or why it is none under `phone`.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException 422 on `phone`
     */
    private static function phone(array $input): string
    {
        $typed = Input::text($input, 'phone');
        $phone = Mobile::of($typed);

        return $phone ?? throw ValidationException::on('phone', (string) Mobile::problem($typed));
    }

    /**
     * The account's own mobile, as an SMS reaches it to prove the account again: an Iranian one (a number shared with the
     * bot may be another country's), on an account without two-factor sign-in — one with it proves its password and its
     * code (Reauthentication), the number standing in for no more than the password, as in a sign-in.
     *
     * @throws ValidationException 422 on `method`
     */
    private static function own(User $user): string
    {
        if ($user->hasTwoFactor()) {
            throw ValidationException::on('method', self::TWO_FACTOR_FIRST);
        }

        return Mobile::of((string) $user->phone) ?? throw ValidationException::on('method', self::NO_PHONE);
    }

    /** What a re-authentication's code is about: the number, on the session that asked — no other session proves by it. */
    private static function reauthSubject(Customer $customer, string $phone): string
    {
        return "{$phone}|session:{$customer->session->id}";
    }
}
