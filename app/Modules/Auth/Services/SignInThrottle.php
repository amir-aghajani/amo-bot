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
 * Failed sign-ins, counted per way in — the owner's password (`login`), an agent's link (`link`), the shops' websites
 * (`website`) — and per address: an address gets MAX_ATTEMPTS failures (a website's WEBSITE_ATTEMPTS) in a window of
 * DECAY_SECONDS, which opens with the first one, then waits for it to close (check() refuses it, a 429); a sign-in that
 * works closes it early. An IPv6 address counts by its /64 (RequestOrigin::clientNetwork()). A password guessed at from
 * many addresses is counted per account too (`$account`, the username or the email tried — a website's in its shop,
 * where it is one customer's): MAX_ACCOUNT_ATTEMPTS failures from every address together, and the account waits — its
 * own owner too, while someone is at it — until the window closes. That count is not closed by a sign-in that works:
 * whoever is guessing would get it all back.
 *
 * A website's second step has a budget of its own (secondStep()): the codes tried for one account, counted before each
 * is judged — SECOND_STEP_HOURLY an hour and SECOND_STEP_DAILY a day, whoever has its password and from wherever. So do
 * the codes a website emails (emailedCode()): CODE_FAILURES_HOURLY wrong ones an hour and CODE_FAILURES_DAILY a day for
 * an address, and for the account adding it, counted before each is judged; spent, no new code goes to the address
 * until it has room again (emailing()) — and its account is told, once a day.
 *
 * And what a website's sign-in costs the shop before anything is tried: a row kept (a nonce, a redirect's state) or an
 * email sent (a code, or the email in its place) — ISSUE_MAX in ISSUE_SECONDS an address (issuing()). An email costs
 * more, so it has budgets of its own (emailing()): the address it goes to has a turn once in CODE_EVERY_SECONDS (taken
 * before the email goes, given back when none went — emailNotSent()) and gets EMAIL_RECIPIENT_DAILY a day from every
 * shop together; the address network that asks, EMAIL_NETWORK_HOURLY an hour; a signed-in customer adding an email to
 * their account, EMAIL_LINK_DAILY a day; the shop, EMAIL_SHOP_HOURLY and EMAIL_SHOP_DAILY, and the installation —
 * every shop together —, EMAIL_INSTALLATION_HOURLY and EMAIL_INSTALLATION_DAILY: past those two, the host's mail quota
 * is spared for the rest of the shop's mail and no code goes out at all (a 503, the log told once a window). An address
 * that asked for a reset and has no account is told so once a day (noAccountEmail()). A sign-in form's captcha token is
 * counted in the issuing window before it is judged and keeps its count only when it was refused (issuedNothing()). A
 * website's backend asking the shop to judge its own forms' tokens has a budget of its own, never the sign-ins'
 * (verifyingCaptcha()): the website's CAPTCHA_CHECKS in CAPTCHA_SECONDS — a backend is one address for all its
 * visitors —, and CAPTCHA_REFUSALS refused tokens of a visitor's network in them. Each is counted as it is asked and
 * judged by its count (RateLimiter::attempt()): requests that come at the same moment never pass a limit together.
 *
 * A website's address limits are the looser ones, by decision: Iran's mobile networks put many customers behind one
 * address (carrier NAT), and the per-account counts are what stands against a guesser who aims at one account.
 */
final class SignInThrottle
{
    /** The way the shops' websites sign their customers in — Telegram, Google, an email and its password, a code: one count, apart from the panels'. */
    public const WEBSITE = 'website';

    /** Failures a panel's way in takes from one address in a window. */
    public const MAX_ATTEMPTS = 10;

    /** Failures a website's sign-ins take from one address network in a window: many customers share one on a mobile network. */
    public const WEBSITE_ATTEMPTS = 30;

    public const MAX_ACCOUNT_ATTEMPTS = 50;

    public const DECAY_SECONDS = 900;

    /** What a website's sign-ins may cost the shop from one address network — nonces, states, emails — in a window of ISSUE_SECONDS. */
    public const ISSUE_MAX = 120;

    public const ISSUE_SECONDS = 600;

    /** How often an address may get a code by email, whatever it is for. */
    public const CODE_EVERY_SECONDS = 60;

    /** The second-step codes one account's sign-ins may try: an hour's, and a day's. */
    public const SECOND_STEP_HOURLY = 10;

    public const SECOND_STEP_DAILY = 20;

    /** The emails a website's sign-ins send at the asking of one address network, in an hour. */
    public const EMAIL_NETWORK_HOURLY = 20;

    /** The emails one address gets from every shop's website together, in a day — however many networks ask. */
    public const EMAIL_RECIPIENT_DAILY = 10;

    /** The codes one signed-in customer asks for, adding an email to their account, in a day. */
    public const EMAIL_LINK_DAILY = 5;

    /** The emails one shop's website sends, in an hour and in a day. */
    public const EMAIL_SHOP_HOURLY = 60;

    public const EMAIL_SHOP_DAILY = 300;

    /** The emails every shop's website together sends, in an hour and in a day: the rest of the host's mail quota is the shop's other mail. */
    public const EMAIL_INSTALLATION_HOURLY = 150;

    public const EMAIL_INSTALLATION_DAILY = 1000;

    /** The emails telling an address it has no account (a reset asked for it) that it gets from one shop, in a day. */
    public const EMAIL_NO_ACCOUNT_DAILY = 1;

    /** The wrong codes an address's emails take — and those an account adding an email tries —, in an hour and in a day. */
    public const CODE_FAILURES_HOURLY = 10;

    public const CODE_FAILURES_DAILY = 20;

    /**
     * The captcha tokens a website's backend asks the shop to judge for its own forms, in a window of CAPTCHA_SECONDS:
     * the website's, whoever its visitors are — generous, since the backend is one address for all of them.
     */
    public const CAPTCHA_CHECKS = 3000;

    /** The tokens of one visitor's network — the backend's own, when it does not name the visitor — refused in that window: then that network's next is not judged. */
    public const CAPTCHA_REFUSALS = 120;

    public const CAPTCHA_SECONDS = 600;

    /** The wrong codes' refusal, its wait added. */
    private const CODES_FAILED = 'کد اشتباه زیادی برای این ایمیل وارد شد';

    private const HOUR = 3600;

    private const DAY = 86400;

    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly RequestOrigin $origin,
        private readonly LoggerInterface $logger,
    ) {}

    /** Seconds this address — or this account, when named — must wait before signing in this way again; 0 while it may try. */
    public function wait(string $way, ServerRequestInterface $request, ?string $account = null): int
    {
        return $this->waitOf($this->windows($way, $request, $account));
    }

    /**
     * A sign-in this way, refused while the address — or the account, when named — must wait.
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

    public function failed(string $way, ServerRequestInterface $request, ?string $account = null): void
    {
        $this->limiter->hit($this->addressKey($way, $request), self::DECAY_SECONDS);
        if ($account !== null) {
            $this->limiter->hit(self::accountKey($way, $account), self::DECAY_SECONDS);
        }
    }

    public function passed(string $way, ServerRequestInterface $request): void
    {
        $this->limiter->clear($this->addressKey($way, $request));
    }

    /**
     * A password about to be judged for `$account` this way — a sign-in's, or one typed again to change what it guards:
     * the try counted before `$verify` judges it, against the address and the account, each under its lock
     * (RateLimiter::attempt()), so guesses sent at once never pass the limits; then a right one gives its counts back
     * (the limits are the wrong tries'; a panel's address starts afresh, as passed() has it) and a wrong one keeps them.
     * With a limit reached it is refused unjudged and uncounted (a 429). Whether `$verify` found it right.
     *
     * @param \Closure(): bool $verify
     * @throws TooManyAttemptsException
     */
    public function password(string $way, ServerRequestInterface $request, ?string $account, \Closure $verify): bool
    {
        $windows = $this->windows($way, $request, $account);
        $wait = $this->limiter->attempt($windows);
        if ($wait > 0) {
            throw self::refused($wait);
        }
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
     * A second-step code about to be tried for the account numbered `$account` — its authenticator app's, or a recovery
     * code —: counted before it is judged, in an hour's window and a day's (RateLimiter::attempt()), so codes tried at
     * once never pass SECOND_STEP_HOURLY an hour nor SECOND_STEP_DAILY a day, from however many addresses. With the
     * budget spent the try is refused (a 429) and not counted, and the first refusal of a day runs `$spent` — its
     * customer is told. A code that opened gives its count back (secondStepOpened()): the budget is the wrong codes'.
     *
     * @param \Closure(): void $spent
     * @throws TooManyAttemptsException
     */
    public function secondStep(int $account, \Closure $spent): void
    {
        $this->budget(self::secondStepWindows($account), "second_step|told|{$account}", $spent, 'تلاش‌های ناموفق زیاد بود');
    }

    /** The code secondStep() counted opened: its count given back. */
    public function secondStepOpened(int $account): void
    {
        $this->giveBack(self::secondStepWindows($account));
    }

    /**
     * A code the website emailed to `$address` about to be tried — a sign-up's, a reset's, or one for an email the account
     * numbered `$account` is adding —: counted before it is judged, against the address (in its shop) and that account,
     * in an hour's window and a day's (RateLimiter::attempt()), so codes tried at once never pass CODE_FAILURES_HOURLY an
     * hour nor CODE_FAILURES_DAILY a day, from however many addresses and whatever codes they were sent. With either
     * spent the try is refused (a 429) and not counted, no new code goes to the address until it has room again
     * (emailing()), and the first refusal of a day runs `$spent` — the address's account is told. Counted whether or not
     * the address has an account, so a refusal gives none away. A code that opened gives its count back
     * (emailedCodeOpened()): the budget is the wrong codes'.
     *
     * @param \Closure(): void $spent
     * @throws TooManyAttemptsException
     */
    public function emailedCode(string $address, ?int $account, \Closure $spent): void
    {
        $this->budget(self::codeWindows($address, $account), 'code_fail|told|' . CurrentBot::id() . '|' . self::addressOf($address), $spent, self::CODES_FAILED);
    }

    /** The code emailedCode() counted opened: its count given back. */
    public function emailedCodeOpened(string $address, ?int $account): void
    {
        $this->giveBack(self::codeWindows($address, $account));
    }

    /**
     * Something a website's sign-in costs the shop — a row kept, an email sent —, counted against the address that asked:
     * refused, a 429, once it asked ISSUE_MAX in its window.
     *
     * @throws TooManyAttemptsException
     */
    public function issuing(ServerRequestInterface $request): void
    {
        $wait = $this->limiter->attempt([[$this->issueKey($request), self::ISSUE_MAX, self::ISSUE_SECONDS]]);
        if ($wait > 0) {
            throw TooManyAttemptsException::wait('درخواست‌ها زیاد بود', $wait);
        }
    }

    /**
     * What issuing() counted cost the shop nothing after all — a sign-in form's captcha token, counted before it was
     * judged, that passed or could not be judged: a refused one keeps its count, so a bot's tokens cost it what asking
     * for codes costs, and once that is spent the next is refused unjudged — its provider not asked.
     */
    public function issuedNothing(ServerRequestInterface $request): void
    {
        $this->limiter->release($this->issueKey($request));
    }

    /**
     * A website's backend asking the shop to judge a token of its own form's captcha (POST /captcha/verify) — the
     * website's by its store key, `$network` its visitor's (the address the backend named), else the backend's own —:
     * counted, before it is judged, against the website's CAPTCHA_CHECKS and the network's CAPTCHA_REFUSALS, each in
     * CAPTCHA_SECONDS; with either spent a 429, and the token is not judged — its provider not asked. One that passed or
     * could not be judged gives the network's count back (captchaNotRefused()): its budget is the refused ones'. Never the
     * sign-ins' budget (issuing()): a bot behind a site's forms keeps nobody from signing in.
     *
     * @throws TooManyAttemptsException
     */
    public function verifyingCaptcha(string $website, string $network): void
    {
        $wait = $this->limiter->attempt(self::captchaWindows($website, $network));
        if ($wait > 0) {
            throw TooManyAttemptsException::wait('درخواست تایید زیادی فرستاده شد', $wait);
        }
    }

    /** The token verifyingCaptcha() counted was not refused — it passed, or could not be judged: the network's count given back. */
    public function captchaNotRefused(string $website, string $network): void
    {
        $this->limiter->release(self::captchaWindows($website, $network)[1][0]);
    }

    /**
     * An email a website's sign-in is about to send to `$address` (in the current shop) — a code, or the email that goes
     * in its place —, counted against every budget it spends before it goes, so requests that come while the mail server
     * is still talking never send one each. Refused before anything is counted while the address's wrong codes are spent
     * (emailedCode()); then what the asking network may cost (issuing()), the address's turn (CODE_EVERY_SECONDS) and its
     * day (EMAIL_RECIPIENT_DAILY, from every shop), the network's hour (EMAIL_NETWORK_HOURLY) — and, `$askedBy` a
     * signed-in customer adding an email to their account, their day (EMAIL_LINK_DAILY) —, each a 429; and the shop's
     * and the installation's hour and day, a 503 (the log told once a window). Each is counted whether or not the
     * address has an account, so a refusal gives none away; one refused gives back what the email had counted before it
     * (but the network's ask). An email that did not go after all gives its counts back (emailNotSent()).
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

    /** No email went to `$address` after all — refused on the way, or the mail server did not take it —: what emailing() counted given back. */
    public function emailNotSent(ServerRequestInterface $request, string $address, ?int $askedBy = null): void
    {
        foreach ($this->emailBudgets($request, $address, $askedBy) as [$windows]) {
            $this->giveBack($windows);
        }
    }

    /**
     * The email telling `$address` it has no account in this shop (a reset asked for it), by `$send` — at most
     * EMAIL_NO_ACCOUNT_DAILY a day: a later ask that day sends nothing, every count and the answer the same. One `$send`
     * did not send gives its turn back.
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

    /** The refusal of a sign-in that must wait `$seconds`: a 429, the wait in words and in `Retry-After`. */
    public static function refused(int $seconds): TooManyAttemptsException
    {
        return TooManyAttemptsException::wait('تلاش‌های ناموفق زیاد بود', $seconds);
    }

    /**
     * A try held to a budget of its windows, counted before it is judged: with one spent it is refused (a 429, `$what`
     * and the wait) and not counted, and the first refusal while `$told`'s day lasts runs `$spent`.
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
     * What an email to `$address` spends, in the order it is counted — each budget's windows, and its refusal given the
     * wait: the address's turn and day, the asking network's hour (and a signed-in customer's day adding an email), the
     * shop's and the installation's hour and day.
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
     * The shop's or the installation's email budget spent: the log told of each window spent — once while it is —, and a
     * 503 in the customer's words: no email goes from the shop for now.
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
     * The windows a try this way counts in: the address's, and the account's when one is named — key, most failures,
     * seconds.
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

    /**
     * @return array{array{string, int, int}, array{string, int, int}} The windows a website's captcha check counts in —
     *                                                                 the website's, then its visitor's network's refused
     *                                                                 tokens: key, most, seconds
     */
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

    /**
     * @return non-empty-list<array{string, int, int}> The wrong emailed codes' windows of an address in its shop — and of
     *                                                 the account adding it, when one is named: key, most codes, seconds
     */
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
     * One count however the name is typed (the panel's own check trims it, so the throttle does too); a website's in its
     * shop — the same address in two shops is two customers, and an agent's website must not lock one out of another's.
     */
    private static function accountKey(string $way, string $account): string
    {
        $shop = $way === self::WEBSITE ? CurrentBot::id() . '|' : '';

        return $way . '|account|' . $shop . self::addressOf($account);
    }
}
