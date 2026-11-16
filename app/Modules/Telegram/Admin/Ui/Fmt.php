<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Ui;

use App\Modules\Telegram\Messages;
use App\Support\Money;
use App\Support\Persian;
use Illuminate\Support\Carbon;

/**
 * How the management screens say things — the way the bot's other messages do (Persian digits, Toman, gigabytes, Jalali
 * dates) — from the panels' JSON: money as decimal strings, moments as ISO 8601, bytes as integers. Everything returned
 * is HTML-safe, but for plain(), bare() and cut(): plain text, for a button's label or a title.
 */
final class Fmt
{
    /** What is said for a value there is none of (Words'). */
    public const NONE = Words::NONE;

    /** Text as HTML: what a person wrote, a name, anything from the panel. */
    public static function e(?string $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** HTML (what Fmt returns, a line) as plain text — a button's label, a title, a notice: tags dropped, entities read back. */
    public static function plain(string $html): string
    {
        return html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
    }

    /** Latin text inside a Persian line, isolated so it keeps its order (an address, a code, a username). */
    public static function ltr(?string $text): string
    {
        return $text === null || $text === '' ? self::NONE : "\u{2068}" . self::e($text) . "\u{2069}";
    }

    /** Copyable text: a code, a link, a key — tapped, Telegram copies it. */
    public static function code(?string $text): string
    {
        return $text === null || $text === '' ? self::NONE : '<code>' . self::e($text) . '</code>';
    }

    /** An amount of money: "۱۲۰٬۰۰۰ تومان"; "—" for none. */
    public static function money(string|int|float|null $amount): string
    {
        return $amount === null || $amount === '' ? self::NONE : Money::format($amount);
    }

    /** A quota: "۳۰ گیگابایت", "نامحدود" for 0. */
    public static function quota(int|float|null $bytes): string
    {
        return Messages::traffic((int) ($bytes ?? 0));
    }

    /** An amount of traffic used: "۱٫۵ گیگابایت", "۰ مگابایت". */
    public static function bytes(int|float|null $bytes): string
    {
        return Messages::bytes((int) ($bytes ?? 0));
    }

    /** A count, in Persian digits with separators. */
    public static function number(int|float|string|null $value): string
    {
        return $value === null || $value === '' ? self::NONE : Persian::number((float) $value);
    }

    /** Digits as they were, in Persian (an id, a year). */
    public static function digits(int|string|null $value): string
    {
        return $value === null || $value === '' ? self::NONE : Persian::digits((string) $value);
    }

    /** "#۱۲": a row's number. */
    public static function id(int|string|null $id): string
    {
        return $id === null ? self::NONE : '#' . Persian::digits((string) $id);
    }

    /** A moment the panel wrote (ISO 8601): "۲۶ شهریور ۱۴۰۵"; with `$time`, its hour too; "—" for none. */
    public static function date(?string $iso, bool $time = false): string
    {
        $moment = self::moment($iso);

        return $moment === null ? self::NONE : Persian::date($moment, $time);
    }

    /** A moment the panel wrote, as a date-time; null for none or one unreadable. */
    public static function moment(?string $iso): ?Carbon
    {
        if ($iso === null || $iso === '') {
            return null;
        }

        try {
            return Carbon::parse($iso);
        } catch (\Throwable) {
            return null;
        }
    }

    /** A duration in hours: "۳۰ روز", "۱۲ ساعت", "نامحدود" for 0. */
    public static function hours(int|null $hours): string
    {
        return Messages::duration((int) ($hours ?? 0));
    }

    /** A percentage: "۲۰٪". */
    public static function percent(int|float|string|null $value): string
    {
        return $value === null || $value === '' ? self::NONE : Persian::digits(rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.')) . '٪';
    }

    /** A switch as it stands: «🟢 روشن» / «⏸ خاموش» (Words' chips). */
    public static function onOff(bool $on): string
    {
        return $on ? Words::ON : Words::OFF;
    }

    /** A row that sells or works as it stands (a plan, a code, a server): «🟢 فعال» / «⏸ غیرفعال». */
    public static function active(bool $active): string
    {
        return $active ? Words::ACTIVE : Words::INACTIVE;
    }

    /** A title without the emoji it starts with (a screen's name in another's path): «📦 یک‌ماهه» → «یک‌ماهه». */
    public static function bare(string $title): string
    {
        return trim(preg_replace('/^[\p{So}\p{Sk}\p{Mn}\x{200D}\s]+/u', '', $title) ?? $title);
    }

    /** «بله» / «خیر». */
    public static function yesNo(bool $yes): string
    {
        return $yes ? 'بله' : 'خیر';
    }

    /** A count beside a section's name: " (۳)", nothing for none. */
    public static function badge(int $count): string
    {
        return $count > 0 ? ' (' . Persian::number($count) . ')' : '';
    }

    /** Text cut to `$most` characters with «…», for a line in a list. */
    public static function cut(?string $text, int $most = 60): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');

        return mb_strlen($text) > $most ? rtrim(mb_substr($text, 0, $most - 1)) . '…' : $text;
    }
}
