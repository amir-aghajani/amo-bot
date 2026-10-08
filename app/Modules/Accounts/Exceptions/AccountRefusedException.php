<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * A change a customer of the shop's website asks of their own account that the shop turns down, in their words (a 422):
 * two-factor sign-in without an email and a password to ask it of, or turned on or off twice; a password without an
 * email; a way in of a kind the account has already — another Telegram or Google account, an email —, one it does not
 * have, or its last one; a merge ticket that no longer holds, or one that would take the customer past the other
 * account's second step by its address alone (MergeOffers); and the merges the rules refuse (AccountMerger): an account with itself, a banned one, two of
 * one kind of way in, two agents.
 */
final class AccountRefusedException extends DomainRuleException
{
    public const TWO_FACTOR_NEEDS_PASSWORD = 'ورود دو مرحله‌ای برای ورود با ایمیل و رمز است؛ اول ایمیل و رمز عبور را به حساب اضافه کنید.';
    public const TWO_FACTOR_ON = 'ورود دو مرحله‌ای این حساب از قبل روشن است؛ برای کلید تازه، اول آن را خاموش کنید.';
    public const TWO_FACTOR_OFF = 'ورود دو مرحله‌ای این حساب روشن نیست.';
    public const NEEDS_EMAIL = 'اول یک ایمیل به حساب اضافه کنید.';
    public const OTHER_TELEGRAM = 'این حساب به حساب تلگرام دیگری وصل است؛ اول آن را جدا کنید.';
    public const OTHER_GOOGLE = 'این حساب به حساب گوگل دیگری وصل است؛ اول آن را جدا کنید.';
    public const HAS_EMAIL = 'این حساب ایمیل دارد.';
    public const NOT_LINKED = 'این روش ورود به حساب وصل نیست.';
    public const LAST_WAY_IN = 'حداقل یک روش ورود باید بماند.';
    public const OFFER_GONE = 'این پیشنهاد ادغام دیگر معتبر نیست؛ دوباره تلاش کنید.';
    public const TWO_FACTOR_ACCOUNT = 'این ایمیل به حسابی با ورود دو مرحله‌ای وصل است؛ برای یکی کردن دو حساب، با همان ایمیل وارد شوید و روش ورود این حساب را از آنجا وصل کنید.';
    public const SAME_ACCOUNT = 'هر دو یک حساب هستند؛ چیزی برای ادغام نیست.';
    public const BANNED = 'یکی از دو حساب مسدود است؛ ادغام انجام نمی‌شود.';
    public const BOTH_HAVE = 'هر دو حساب به یک نوع روش ورود متفاوت وصل هستند (%s)؛ اول یکی را جدا کنید.';
    public const BOTH_AGENTS = 'هر دو حساب نماینده هستند؛ دو نماینده با هم ادغام نمی‌شوند.';

    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function twoFactorNeedsPassword(): self
    {
        return new self(self::TWO_FACTOR_NEEDS_PASSWORD);
    }

    public static function twoFactorOn(): self
    {
        return new self(self::TWO_FACTOR_ON);
    }

    public static function twoFactorOff(): self
    {
        return new self(self::TWO_FACTOR_OFF);
    }

    public static function needsEmail(): self
    {
        return new self(self::NEEDS_EMAIL);
    }

    public static function otherTelegram(): self
    {
        return new self(self::OTHER_TELEGRAM);
    }

    public static function otherGoogle(): self
    {
        return new self(self::OTHER_GOOGLE);
    }

    public static function hasEmail(): self
    {
        return new self(self::HAS_EMAIL);
    }

    public static function notLinked(): self
    {
        return new self(self::NOT_LINKED);
    }

    public static function lastWayIn(): self
    {
        return new self(self::LAST_WAY_IN);
    }

    public static function offerGone(): self
    {
        return new self(self::OFFER_GONE);
    }

    public static function twoFactorAccount(): self
    {
        return new self(self::TWO_FACTOR_ACCOUNT);
    }

    public static function sameAccount(): self
    {
        return new self(self::SAME_ACCOUNT);
    }

    public static function banned(): self
    {
        return new self(self::BANNED);
    }

    /** @param non-empty-list<string> $kinds The kinds of way in both have, a different one each: «تلگرام», «ایمیل» */
    public static function bothHave(array $kinds): self
    {
        return new self(sprintf(self::BOTH_HAVE, implode('، ', $kinds)));
    }

    public static function bothAgents(): self
    {
        return new self(self::BOTH_AGENTS);
    }
}
