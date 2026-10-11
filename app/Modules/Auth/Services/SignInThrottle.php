<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Http\RequestOrigin;
use App\Core\Security\RateLimiter;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Bots\CurrentBot;
use App\Support\Persian;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Every sign-in budget, on RateLimiter. Each try is counted before it is judged (RateLimiter::attempt()), so requests
 * sent at once never pass a limit together; a right answer gives its count back where the budget is the wrong tries'.
 *
 * - Failed sign-ins, per way in (`login`, `link`, `website`): per address network (an IPv6 /64) — MAX_ATTEMPTS, or the
 *   looser WEBSITE_ATTEMPTS since Iran's mobile carriers put many customers behind one address — and, for a password,
 *   per account (MAX_ACCOUNT_ATTEMPTS from all addresses; a success does not clear it, or a guesser would get it back).
 * - A website's second-step codes per account (secondStep()) and its emailed codes per address and account
 *   (emailedCode()), an hour's and a day's; a spent budget tells the customer once a day.
 * - What a website's sign-in costs before anything is tried (issuing(): nonces, states, captcha tokens), and each email
 *   (emailing(): the address's turn and day, the asking network's hour, a signed-in customer's day, the shop's and the
 *   installation's hour and day — the last two a 503, sparing the host's mail quota).
 * - A website backend's captcha checks (verifyingCaptcha()), never the sign-ins' budget.
 */
final class SignInThrottle
{
    /** The websites' customer sign-ins, all ways together: one count, apart from the panels'. */
    public const WEBSITE = 'website';

    /** Failures a panel's way in takes from one address in a window. */
    public const MAX_ATTEMPTS = 10;

    /** Failures a website's sign-ins take from one address network in a window (many customers share one on mobile). */
    public const WEBSITE_ATTEMPTS = 30;

    public const MAX_ACCOUNT_ATTEMPTS = 50;

    public const DECAY_SECONDS = 900;

    /** What a website's sign-ins may cost from one address network (nonces, states, emails) in ISSUE_SECONDS. */
    public const ISSUE_MAX = 120;

    public const ISSUE_SECONDS = 600;

    /** How often an address may get a code by email, whatever it is for. */
    public const CODE_EVERY_SECONDS = 60;

    /** The second-step codes one account's sign-ins may try: an hour's, and a day's. */
    public const SECOND_STEP_HOURLY = 10;

    public const SECOND_STEP_DAILY = 20;

    /** The emails a website's sign-ins send at the asking of one address network, in an hour. */
    public const EMAIL_NETWORK_HOURLY = 20;

    /** The emails one address gets from every shop's website together, in a day. */
    public const EMAIL_RECIPIENT_DAILY = 10;

    /** The codes one signed-in customer asks for, adding an email to their account, in a day. */
    public const EMAIL_LINK_DAILY = 5;

    /** The emails one shop's website sends, in an hour and in a day. */
    public const EMAIL_SHOP_HOURLY = 60;

    public const EMAIL_SHOP_DAILY = 300;

    /** The emails all websites together send, in an hour and in a day, sparing the host's mail quota for other mail. */
    public const EMAIL_INSTALLATION_HOURLY = 150;

    public const EMAIL_INSTALLATION_DAILY = 1000;

    /** The emails telling an address it has no account (a reset asked for it) that it gets from one shop, in a day. */
    public const EMAIL_NO_ACCOUNT_DAILY = 1;

    /** Wrong emailed codes an address (and an account adding an email) may try, in an hour and in a day. */
    public const CODE_FAILURES_HOURLY = 10;

    public const CODE_FAILURES_DAILY = 20;

    /** Tokens a website's backend may have judged in CAPTCHA_SECONDS — generous: it is one address for all visitors. */
    public const CAPTCHA_CHECKS = 3000;

    /** Refused tokens of one visitor's network (the backend's own, when unnamed) in that window, before the next is not judged. */
    public const CAPTCHA_REFUSALS = 120;

    public const CAPTCHA_SECONDS = 600;

    /** A sign-in's refusal past its budget of failures, its wait added. */
    private const FAILURES = 'تلاش‌های ناموفق زیاد بود';

    /** The wrong codes' refusal, its wait added. */
    private const CODES_FAILED = 'کد اشتباه زیادی برای این ایمیل وارد شد';

    private const HOUR = 3600;

    private const DAY = 86400;

    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly RequestOrigin $origin,
        private readonly LoggerInterface $logger,
    ) {}

    /** Seconds this address (or account) must wait before trying this way again; 0 while it may. */
    public function wait(string $way, ServerRequestInterface $request, ?string $account = null): int
    {
        return $this->waitOf($this->windows($way, $request, $account));
    }

    /**
     * Refuse a sign-in this way while the address (or account) must wait.
     *
     * @throws TooManyAttemptsException
     */
    public function check(string $way, ServerRequestInterface $request, ?string $account = null): void
    {
        $wait = $this->wait($way, $request, $account);
        if ($wait > 0) {
            throw self::refused($wait);
        }
    }

    /** Count a failed sign-in this way against the address, and against the account when named. */
    public function failed(string $way, ServerRequestInterface $request, ?string $account = null): void
    {
        $this->limiter->hit($this->addressKey($way, $request), self::DECAY_SECONDS);
        if ($account !== null) {
            $this->limiter->hit(self::accountKey($way, $account), self::DECAY_SECONDS);
        }
    }

    /** A sign-in worked: the address starts afresh; an account's count stays, or a guesser would get it back. */
    public function passed(string $way, ServerRequestInterface $request): void
    {
        $this->limiter->clear($this->addressKey($way, $request));
    }

    /**
     * Judge a password for `$account`: counted against the address and the account first, so simultaneous guesses never
     * pass the limits; a right one gives the counts back (a panel's address starts afresh), a wrong one keeps them. Past
     * a limit it is refused (429) without being judged. Returns whether `$verify` found it right.
     *
     * @param \Closure(): bool $verify
     * @throws TooManyAttemptsException
     */
    public function password(string $way, ServerRequestInterface $request, ?string $account, \Closure $verify): bool
    {
        $windows = $this->windows($way, $request, $account);
        $this->limiter->enforce($windows, self::FAILURES);
        if (!$verify()) {
            return false;
        }

        $this->giveBack($windows);
        if ($way !== self::WEBSITE) {
            $this->passed($way, $request);
        }

        return true;
    }

    /**
     * Count a second-step code (app or recovery code) for account `$account` before it is judged, against
     * SECOND_STEP_HOURLY and SECOND_STEP_DAILY from any address. Spent: a 429, and the day's first refusal runs `$spent`
     * (the customer is told). A right code gives its count back (secondStepOpened()).
     *
     * @param \Closure(): void $spent
     * @throws TooManyAttemptsException
     */
    public function secondStep(int $account, \Closure $spent): void
    {
        $this->budget(self::secondStepWindows($account), "second_step|told|{$account}", $spent, self::FAILURES);
    }

    /** Give back the count of a second-step code that was right. */
    public function secondStepOpened(int $account): void
    {
        $this->giveBack(self::secondStepWindows($account));
    }

    /**
     * Count a code emailed to `$address` (a sign-up's, a reset's, or one for an email account `$account` is adding)
     * before it is judged, against the address in its shop and the account, an hour's and a day's. Spent: a 429, no new
     * code goes to the address until there is room (emailing()), and the day's first refusal runs `$spent`. Counted
     * whether or not the address has an account, so a refusal gives nothing away.
     *
     * @param \Closure(): void $spent
     * @throws TooManyAttemptsException
     */
    public function emailedCode(string $address, ?int $account, \Closure $spent): void
    {
        $this->budget(self::codeWindows($address, $account), 'code_fail|told|' . CurrentBot::id() . '|' . self::addressOf($address), $spent, self::CODES_FAILED);
    }

    /** Give back the count of an emailed code that was right. */
    public function emailedCodeOpened(string $address, ?int $account): void
    {
        $this->giveBack(self::codeWindows($address, $account));
    }

    /**
     * Count something a website's sign-in costs the shop (a row kept, an email, a captcha judged) against the asking
     * network: a 429 past ISSUE_MAX.
     *
     * @throws TooManyAttemptsException
     */
    public function issuing(ServerRequestInterface $request): void
    {
        $this->limiter->enforce([[$this->issueKey($request), self::ISSUE_MAX, self::ISSUE_SECONDS]], 'درخواست‌ها زیاد بود');
    }

    /**
     * Give back what issuing() counted for a captcha token that passed or could not be judged; a refused one keeps it,
     * so a bot's guesses spend its budget and are then refused without asking the provider.
     */
    public function issuedNothing(ServerRequestInterface $request): void
    {
        $this->limiter->release($this->issueKey($request));
    }

    /**
     * Count a token a website's backend asks the shop to judge (POST /captcha/verify) against the website's
     * CAPTCHA_CHECKS and the visitor network's CAPTCHA_REFUSALS; spent, a 429 without asking the provider. Kept apart
     * from the sign-ins' budget, so a bot behind a site's forms locks nobody out of signing in.
     *
     * @throws TooManyAttemptsException
     */
    public function verifyingCaptcha(string $website, string $network): void
    {
        $this->limiter->enforce(self::captchaWindows($website, $network), 'درخواست تایید زیادی فرستاده شد');
    }

    /** Give back the network's count for a token that passed or could not be judged. */
    public function captchaNotRefused(string $website, string $network): void
    {
        $this->limiter->release(self::captchaWindows($website, $network)[1][0]);
    }

    /**
     * Count an email a website's sign-in is about to send to `$address` against every budget before it goes, so requests
     * arriving while the mail server talks never send one each. Refused first while the address's wrong codes are spent;
     * then the network's issuing, the address's turn and day, the network's hour and a signed-in customer's day are each
     * a 429; the shop's and the installation's hour and day a 503 (logged once a window). Counted whether or not the
     * address has an account; a refusal gives back what this email counted before it (all but the network's ask).
     *
     * @throws TooManyAttemptsException 429
     * @throws SignInRefusedException 503: the shop's or the installation's budget is spent
     */
    public function emailing(ServerRequestInterface $request, string $address, ?int $askedBy = null): void
    {
        $cooling = $this->waitOf(self::codeWindows($address, null));
        if ($cooling > 0) {
            throw TooManyAttemptsException::wait(self::CODES_FAILED, $cooling);
        }
        $this->issuing($request);

        $counted = [];
        try {
            foreach ($this->emailBudgets($request, $address, $askedBy) as [$windows, $refusal]) {
                $wait = $this->limiter->attempt($windows);
                if ($wait > 0) {
                    throw $refusal($wait, $windows);
                }
                $counted = [...$counted, ...$windows];
            }
        } catch (\Throwable $e) {
            $this->giveBack($counted);

            throw $e;
        }
    }

    /** Give back what emailing() counted for an email that did not go. */
    public function emailNotSent(ServerRequestInterface $request, string $address, ?int $askedBy = null): void
    {
        foreach ($this->emailBudgets($request, $address, $askedBy) as [$windows]) {
            $this->giveBack($windows);
        }
    }

    /**
     * Send (`$send`) the email telling `$address` it has no account here, at most EMAIL_NO_ACCOUNT_DAILY a day; a later
     * ask that day sends nothing and looks the same. A failed send gives its turn back.
     *
     * @param \Closure(): void $send
     */
    public function noAccountEmail(string $address, \Closure $send): void
    {
        $turn = ['email|no_account|' . CurrentBot::id() . '|' . self::addressOf($address), self::EMAIL_NO_ACCOUNT_DAILY, self::DAY];
        if ($this->limiter->attempt([$turn]) > 0) {
            return;
        }

        try {
            $send();
        } catch (\Throwable $e) {
            $this->giveBack([$turn]);

            throw $e;
        }
    }

    /** The 429 of a sign-in that must wait `$seconds`. */
    public static function refused(int $seconds): TooManyAttemptsException
    {
        return TooManyAttemptsException::wait(self::FAILURES, $seconds);
    }

    /**
     * Count a try in its windows before it is judged; with one spent, a 429 — and the first refusal of `$told`'s day
     * runs `$spent`.
     *
     * @param non-empty-list<array{string, int, int}> $windows
     * @param \Closure(): void $spent
     * @throws TooManyAttemptsException
     */
    private function budget(array $windows, string $told, \Closure $spent, string $what): void
    {
        $wait = $this->limiter->attempt($windows);
        if ($wait === 0) {
            return;
        }
        if ($this->limiter->hit($told, self::DAY) === 1) {
            $spent();
        }

        throw TooManyAttemptsException::wait($what, $wait);
    }

    /**
     * The budgets an email to `$address` spends, in counting order, each with its refusal.
     *
     * @return list<array{non-empty-list<array{string, int, int}>, \Closure(int, non-empty-list<array{string, int, int}>): \Throwable}>
     */
    private function emailBudgets(ServerRequestInterface $request, string $address, ?int $askedBy): array
    {
        $shop = CurrentBot::id();
        $to = self::addressOf($address);
        $asking = [['email|network|' . $this->origin->clientNetwork($request), self::EMAIL_NETWORK_HOURLY, self::HOUR]];
        if ($askedBy !== null) {
            $asking[] = ["email|link|{$askedBy}", self::EMAIL_LINK_DAILY, self::DAY];
        }

        return [
            [[["code|{$shop}|{$to}", 1, self::CODE_EVERY_SECONDS]], static fn(int $wait): \Throwable => new TooManyAttemptsException(sprintf('برای این ایمیل همین حالا درخواست شد؛ %s ثانیه دیگر دوباره امتحان کنید.', Persian::digits((string) $wait)), $wait)],
            [[["email|to|{$to}", self::EMAIL_RECIPIENT_DAILY, self::DAY]], static fn(int $wait): \Throwable => TooManyAttemptsException::wait('امروز ایمیل زیادی برای این آدرس فرستاده شد', $wait)],
            [$asking, static fn(int $wait): \Throwable => TooManyAttemptsException::wait('ایمیل زیادی درخواست کرده‌اید', $wait)],
            [[
                ["email|shop|hour|{$shop}", self::EMAIL_SHOP_HOURLY, self::HOUR],
                ["email|shop|day|{$shop}", self::EMAIL_SHOP_DAILY, self::DAY],
                ['email|installation|hour', self::EMAIL_INSTALLATION_HOURLY, self::HOUR],
                ['email|installation|day', self::EMAIL_INSTALLATION_DAILY, self::DAY],
            ], $this->mailSpent(...)],
        ];
    }

    /**
     * The 503 of a spent shop or installation email budget, logging each spent window once while it lasts.
     *
     * @param non-empty-list<array{string, int, int}> $windows
     */
    private function mailSpent(int $wait, array $windows): \Throwable
    {
        foreach ($windows as [$key, $max, $seconds]) {
            if ($this->limiter->availableIn($key, $max) > 0 && $this->limiter->hit("{$key}|logged", $seconds) === 1) {
                $this->logger->warning('The websites\' sign-in emails reached a budget ({budget}: {max} in {seconds} seconds): no code goes out for {wait} seconds', [
                    'budget' => $key,
                    'max' => $max,
                    'seconds' => $seconds,
                    'wait' => $wait,
                ]);
            }
        }

        return SignInRefusedException::mailOff();
    }

    /** @param list<array{string, int, int}> $windows Each given back what one try counted in it. */
    private function giveBack(array $windows): void
    {
        foreach ($windows as [$key]) {
            $this->limiter->release($key);
        }
    }

    /** @param non-empty-list<array{string, int, int}> $windows Seconds until every one has room; 0 while all have. */
    private function waitOf(array $windows): int
    {
        return max(array_map(fn(array $window): int => $this->limiter->availableIn($window[0], $window[1]), $windows));
    }

    /** Failures an address may have this way in a window. */
    private static function addressAttempts(string $way): int
    {
        return $way === self::WEBSITE ? self::WEBSITE_ATTEMPTS : self::MAX_ATTEMPTS;
    }

    /**
     * A way's windows: the address's, and the account's when named — [key, most failures, seconds].
     *
     * @return non-empty-list<array{string, int, int}>
     */
    private function windows(string $way, ServerRequestInterface $request, ?string $account): array
    {
        $windows = [[$this->addressKey($way, $request), self::addressAttempts($way), self::DECAY_SECONDS]];
        if ($account !== null) {
            $windows[] = [self::accountKey($way, $account), self::MAX_ACCOUNT_ATTEMPTS, self::DECAY_SECONDS];
        }

        return $windows;
    }

    /** @return array{array{string, int, int}, array{string, int, int}} The website's window, then the network's refusals */
    private static function captchaWindows(string $website, string $network): array
    {
        return [
            ["captcha|website|{$website}", self::CAPTCHA_CHECKS, self::CAPTCHA_SECONDS],
            ["captcha|refused|{$website}|{$network}", self::CAPTCHA_REFUSALS, self::CAPTCHA_SECONDS],
        ];
    }

    /** @return non-empty-list<array{string, int, int}> The second step's windows of an account: key, most codes, seconds. */
    private static function secondStepWindows(int $account): array
    {
        return [
            ["second_step|hour|{$account}", self::SECOND_STEP_HOURLY, self::HOUR],
            ["second_step|day|{$account}", self::SECOND_STEP_DAILY, self::DAY],
        ];
    }

    /** @return non-empty-list<array{string, int, int}> The wrong emailed codes' windows of an address (and account) */
    private static function codeWindows(string $address, ?int $account): array
    {
        $to = CurrentBot::id() . '|' . self::addressOf($address);
        $windows = [
            ["code_fail|hour|{$to}", self::CODE_FAILURES_HOURLY, self::HOUR],
            ["code_fail|day|{$to}", self::CODE_FAILURES_DAILY, self::DAY],
        ];
        if ($account !== null) {
            $windows[] = ["code_fail|hour|account|{$account}", self::CODE_FAILURES_HOURLY, self::HOUR];
            $windows[] = ["code_fail|day|account|{$account}", self::CODE_FAILURES_DAILY, self::DAY];
        }

        return $windows;
    }

    /** One address, however it is typed. */
    private static function addressOf(string $address): string
    {
        return mb_strtolower(trim($address));
    }

    private function addressKey(string $way, ServerRequestInterface $request): string
    {
        return $way . '|' . $this->origin->clientNetwork($request);
    }

    private function issueKey(ServerRequestInterface $request): string
    {
        return 'issue|' . $this->origin->clientNetwork($request);
    }

    /**
     * An account's key however its name is typed; a website's per shop, since one address in two shops is two customers.
     */
    private static function accountKey(string $way, string $account): string
    {
        $shop = $way === self::WEBSITE ? CurrentBot::id() . '|' : '';

        return $way . '|account|' . $shop . self::addressOf($account);
    }
}
