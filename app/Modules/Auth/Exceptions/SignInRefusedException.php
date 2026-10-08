<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use App\Core\Exceptions\DomainRuleException;
use App\Modules\Auth\PanelAuthMiddleware;

/**
 * A sign-in the shop turns down, in the words its page shows. A panel's: credentials or a link that do not open it
 * (401), an agent's link opened where another agent is signed in, which takes that session's place only when asked to
 * (409, on `replace`), a session ended meanwhile by another of the same agent's (401), no login set up yet (503 — the
 * login page's recovery sets one, with a key off the host's files), or a recovery whose key the host's storage/ does
 * not take (503). A customer's on the shop's website (Accounts): a token, a code or a password that does not sign them
 * in, a request to sign in that is spent (401), a way in the website has not set up (422), a banned customer (403), an
 * address another sign-up took a moment ago or an account Google's address may not take on by itself (409), a provider
 * out of reach (502), the shop's email unavailable — none goes out, or its budget is spent (503). A captcha that could
 * not be judged is Core\Captcha's own refusal (CaptchaUnavailableException).
 */
final class SignInRefusedException extends DomainRuleException
{
    public const WRONG_CREDENTIALS = 'نام کاربری یا رمز عبور اشتباه است.';
    public const NOT_SET_UP = 'ورود به پنل هنوز تنظیم نشده است؛ با «رمز را فراموش کرده‌اید؟» نام کاربری و رمز تازه بگذارید.';
    /** Said the way the agent's login page says it: the main bot, its «نمایندگی», the button. */
    public const LINK_REFUSED = 'این لینک ورود دیگر کار نمی‌کند؛ برای لینک تازه، در ربات اصلی به «نمایندگی» بروید و «🔐 ورود به پنل» را بزنید.';
    /** An agent's link opened in a browser signed in to another agent's panel: said before the link is spent. */
    public const ANOTHER_AGENT = 'در این مرورگر پنل نمایندگی دیگری باز است؛ ورود با این لینک از آن پنل خارج می‌شود.';
    /** The recovery's key has nowhere to go: what the owner fixes in their host's File Manager. */
    public const KEY_UNWRITABLE = 'کلید بازیابی در پوشه storage نوشته نشد؛ در File Manager هاست به پوشه storage اجازه نوشتن بدهید و دوباره امتحان کنید.';

    /** An id_token that is not one: its signature, its form, a key its provider does not publish. */
    public const TOKEN_INVALID = 'توکن ورود معتبر نیست؛ دوباره وارد شوید.';
    public const TOKEN_EXPIRED = 'زمان این ورود گذشته است؛ دوباره وارد شوید.';
    /** A token another provider signed, or the provider signed for another site: the website's Client ID is the one to check. */
    public const TOKEN_ELSEWHERE = 'این ورود برای این وب‌سایت صادر نشده است؛ Client ID وب‌سایت را بررسی کنید.';
    /** A nonce or a state the shop never issued, spent already, expired, or not the token's. */
    public const SIGN_IN_SPENT = 'این درخواست ورود منقضی شده یا قبلا استفاده شده است؛ دوباره وارد شوید.';
    public const NO_TELEGRAM_ID = 'تلگرام شناسه حساب را نفرستاد؛ ورود باید دسترسی profile را بخواهد.';
    public const CODE_REFUSED = 'تلگرام کد ورود را نپذیرفت؛ دوباره وارد شوید.';
    public const TELEGRAM_OFF = 'ورود با تلگرام برای این وب‌سایت فعال نیست.';
    public const REDIRECT_OFF = 'ورود با redirect برای این وب‌سایت آماده نیست؛ Client Secret تلگرام در پنل وارد نشده است.';
    public const BANNED = 'حساب کاربری شما مسدود شده است.';
    /**
     * A Google account whose address — one Google speaks for — is an account's that does not take it on by its address
     * alone: one with two-factor sign-in on (the address is no way past its second step), or one another Google account
     * signs in to already.
     */
    public const GOOGLE_ACCOUNT_EXISTS = 'حسابی با این ایمیل هست؛ با همان روش وارد شوید و گوگل را از تنظیمات حساب وصل کنید.';
    /** One answer whichever is wrong: the address, or its password — which addresses have an account is told nobody. */
    public const WRONG_EMAIL_OR_PASSWORD = 'ایمیل یا رمز عبور درست نیست.';
    public const EMAIL_OFF = 'ثبت‌نام با ایمیل برای این وب‌سایت فعال نیست.';
    public const MAIL_OFF = 'فعلا ایمیلی از این فروشگاه فرستاده نمی‌شود؛ کمی بعد دوباره امتحان کنید یا از راه دیگری وارد شوید.';
    public const EMAIL_TAKEN = 'با این ایمیل همین حالا حسابی ساخته شد؛ با ایمیل و رمز عبور وارد شوید.';
    public const GOOGLE_OFF = 'ورود با Google برای این وب‌سایت فعال نیست.';
    public const GOOGLE_UNREACHABLE = 'Google در دسترس نبود؛ چند لحظه بعد دوباره امتحان کنید.';

    private function __construct(
        string $message,
        private readonly int $status,
        ?\Throwable $previous = null,
        /** The one request field it is about, if any. */
        private readonly ?string $about = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function wrongCredentials(): self
    {
        return new self(self::WRONG_CREDENTIALS, 401);
    }

    public static function notSetUp(): self
    {
        return new self(self::NOT_SET_UP, 503);
    }

    public static function linkRefused(): self
    {
        return new self(self::LINK_REFUSED, 401);
    }

    public static function anotherAgent(): self
    {
        return new self(self::ANOTHER_AGENT, 409, about: 'replace');
    }

    /** A session that was open when the request began, and ended before it was done: a 401 like the guard's. */
    public static function signedOut(): self
    {
        return new self(PanelAuthMiddleware::SIGNED_OUT, 401);
    }

    public static function keyUnwritable(): self
    {
        return new self(self::KEY_UNWRITABLE, 503);
    }

    public static function tokenInvalid(): self
    {
        return new self(self::TOKEN_INVALID, 401);
    }

    public static function tokenExpired(): self
    {
        return new self(self::TOKEN_EXPIRED, 401);
    }

    public static function tokenElsewhere(): self
    {
        return new self(self::TOKEN_ELSEWHERE, 401);
    }

    public static function signInSpent(): self
    {
        return new self(self::SIGN_IN_SPENT, 401);
    }

    public static function noTelegramId(): self
    {
        return new self(self::NO_TELEGRAM_ID, 401);
    }

    public static function codeRefused(): self
    {
        return new self(self::CODE_REFUSED, 401);
    }

    public static function telegramOff(): self
    {
        return new self(self::TELEGRAM_OFF, 422);
    }

    public static function redirectOff(): self
    {
        return new self(self::REDIRECT_OFF, 422);
    }

    public static function banned(): self
    {
        return new self(self::BANNED, 403);
    }

    public static function googleAccountExists(): self
    {
        return new self(self::GOOGLE_ACCOUNT_EXISTS, 409);
    }

    public static function wrongEmailOrPassword(): self
    {
        return new self(self::WRONG_EMAIL_OR_PASSWORD, 401);
    }

    public static function emailOff(): self
    {
        return new self(self::EMAIL_OFF, 422);
    }

    public static function mailOff(): self
    {
        return new self(self::MAIL_OFF, 503);
    }

    public static function emailTaken(): self
    {
        return new self(self::EMAIL_TAKEN, 409);
    }

    public static function googleOff(): self
    {
        return new self(self::GOOGLE_OFF, 422);
    }

    public static function googleUnreachable(\Throwable $previous): self
    {
        return new self(self::GOOGLE_UNREACHABLE, 502, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    protected function field(): ?string
    {
        return $this->about;
    }

    /** A sign-in that did not open anything — what a throttle counts — rather than one the shop does not take at all. */
    public function failedSignIn(): bool
    {
        return $this->status === 401;
    }
}
