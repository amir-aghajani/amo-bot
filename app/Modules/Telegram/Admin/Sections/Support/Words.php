<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Support;

use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Support\Persian;
use Illuminate\Support\Carbon;

/**
 * What the support screens say beyond Fmt, the panel's way (lib/format.ts): how long ago, a change against the period
 * before, a customer as the panel's UserIdentity names them, and a day's curve in a line. Everything returned is
 * HTML-safe unless said otherwise.
 */
final class Words
{
    /** What is said, once, of figures whose period before had none to compare with (the panel's Trend). */
    public const NO_BASELINE = 'دوره قبل رقمی برای مقایسه نداشت';

    /** The bars of a curve, lowest first. */
    private const BARS = ['▁', '▂', '▃', '▄', '▅', '▆', '▇', '█'];

    private const MINUTE = 60;
    private const HOUR = 3600;
    private const DAY = 86_400;
    private const MONTH = 30 * self::DAY;
    private const YEAR = 365 * self::DAY;

    /** How long ago a moment the panel wrote was: "۳ روز قبل", "همین حالا" within the minute, "در ۲ ساعت" ahead; "—" for none. */
    public static function ago(?string $iso): string
    {
        $moment = Fmt::moment($iso);
        if ($moment === null) {
            return Fmt::NONE;
        }
        $seconds = $moment->getTimestamp() - Carbon::now()->getTimestamp();
        if (abs($seconds) < self::MINUTE) {
            return 'همین حالا';
        }

        return $seconds > 0 ? 'در ' . self::span($seconds) : self::span(-$seconds) . ' قبل';
    }

    /** How long until a deadline, rounded up (the panel's timeLeft): "۱۲ روز مانده", "۵ ساعت مانده", "تمام شده". */
    public static function left(?string $iso): string
    {
        $moment = Fmt::moment($iso);
        $left = $moment === null ? 0 : $moment->getTimestamp() - Carbon::now()->getTimestamp();

        return match (true) {
            $left <= 0 => 'تمام شده',
            $left < self::DAY => Persian::number((int) ceil($left / self::HOUR)) . ' ساعت مانده',
            default => Persian::number((int) ceil($left / self::DAY)) . ' روز مانده',
        };
    }

    /** Whole days until a moment, a part of one counting as one; 0 once it passed. */
    public static function daysUntil(?string $iso): int
    {
        $moment = Fmt::moment($iso);

        return $moment === null ? 0 : max(0, (int) ceil(($moment->getTimestamp() - Carbon::now()->getTimestamp()) / self::DAY));
    }

    /**
     * A figure against the period before (the panel's Trend): "📈 +۱۲٫۵٪", "📉 −۳٪", "➖ ۰٪"; null when the period before
     * had none — NO_BASELINE, said once for all the figures by whoever shows them, not beside each.
     */
    public static function trend(int|float|string $value, int|float|string $previous): ?string
    {
        $was = (float) $previous;
        if ($was == 0.0) {
            return null;
        }
        $change = ((float) $value - $was) / abs($was) * 100;
        $shown = abs($change) >= 1000 ? 999.0 : (abs($change) >= 100 ? round(abs($change)) : abs($change));
        $percent = self::decimal($shown) . '٪';
        if ($percent === '۰٪') {
            return '➖ ۰٪';
        }

        return $change > 0 ? "📈 +{$percent}" : "📉 −{$percent}";
    }

    /** A figure of the panel's JSON — a count, or money as a decimal string — as it stands, "0" for none (plain text). */
    public static function figure(mixed $value): string
    {
        return is_numeric($value) ? (string) $value : '0';
    }

    /** A number with at most one decimal, in Persian digits: "۱۲٫۵", "۳". */
    public static function decimal(float $value): string
    {
        $text = number_format(round($value, 1), 1, '٫', '٬');

        return Persian::digits(str_ends_with($text, '٫0') ? substr($text, 0, -strlen('٫0')) : $text);
    }

    /**
     * A customer as the panel's UserIdentity names them (UserDirectory::presentRef()): their name, their @username
     * beside it; "—" for none.
     *
     * @param array<string, mixed>|null $ref
     */
    public static function person(?array $ref): string
    {
        if ($ref === null) {
            return Fmt::NONE;
        }
        $handle = is_string($ref['username'] ?? null) && $ref['username'] !== '' ? ' · ' . Fmt::ltr('@' . $ref['username']) : '';

        return Fmt::e(self::name($ref)) . $handle;
    }

    /**
     * A customer's name alone, as plain text for a button: their name, else their @username, Telegram id or email.
     *
     * @param array<string, mixed>|null $ref
     */
    public static function name(?array $ref): string
    {
        foreach (['name', 'username', 'telegram_id', 'email'] as $key) {
            $value = $ref[$key] ?? null;
            if ((is_string($value) && trim($value) !== '') || is_int($value)) {
                return $key === 'username' ? '@' . $value : (string) $value;
            }
        }

        return 'بی‌نام';
    }

    /**
     * A customer's account in a fact: their name and handle, their Telegram id to copy, their row's number.
     *
     * @param array<string, mixed>|null $ref
     */
    public static function account(?array $ref): string
    {
        if ($ref === null) {
            return Fmt::NONE;
        }
        $telegram = is_int($ref['telegram_id'] ?? null) ? ' · ' . Fmt::code((string) $ref['telegram_id']) : '';

        return self::person($ref) . $telegram . ' · ' . Fmt::id(is_int($ref['id'] ?? null) ? $ref['id'] : null);
    }

    /** A customer's standing, beside their name where the panel marks it: «🚫 مسدود» for a banned one, nothing else. */
    public static function banned(mixed $status): string
    {
        return $status === 'banned' ? ' · 🚫 مسدود' : '';
    }

    /** "⭐⭐⭐⭐" — a rating of 1 to 5. */
    public static function stars(mixed $rating): string
    {
        return str_repeat('⭐', max(1, min(5, is_numeric($rating) ? (int) $rating : 1)));
    }

    /**
     * Values as a line of bars, each its share of the largest (the panel's chart, in a chat); `$width` bars a line.
     *
     * @param list<float> $values
     * @return list<string>
     */
    public static function curve(array $values, int $width = 30): array
    {
        $most = $values === [] ? 0.0 : max($values);
        $bars = array_map(static fn(float $value): string => $most <= 0 ? self::BARS[0] : self::BARS[(int) round(max(0.0, $value) / $most * (count(self::BARS) - 1))], $values);

        return array_map(implode(...), array_chunk($bars, max(1, $width)));
    }

    /** A span of seconds in its largest unit, rounded down: "۵ دقیقه", "۳ روز", "۲ ماه". */
    private static function span(int $seconds): string
    {
        return match (true) {
            $seconds < self::HOUR => Persian::number(intdiv($seconds, self::MINUTE)) . ' دقیقه',
            $seconds < self::DAY => Persian::number(intdiv($seconds, self::HOUR)) . ' ساعت',
            $seconds < self::MONTH => Persian::number(intdiv($seconds, self::DAY)) . ' روز',
            $seconds < self::YEAR => Persian::number(min(11, intdiv($seconds, self::MONTH))) . ' ماه',
            default => Persian::number(intdiv($seconds, self::YEAR)) . ' سال',
        };
    }
}
