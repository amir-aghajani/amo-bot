<?php

declare(strict_types=1);

namespace App\Modules\Telegram;

use App\Modules\Subscriptions\Services\ServiceTerms;
use App\Support\Money;
use App\Support\Persian;
use App\Support\Traffic;

/**
 * The bot's words that are not the admin's to reword: the units and phrases the formatters build values
 * from (a quota, a term, «۳ روز مانده»), the main menu's default labels (the keyboards editor rewords
 * those) and what the bot says to its own admins (/broadcast, /emoji). Everything it says to a customer is a
 * BotText (Texts\TextCatalog), sent through Texts\BotTexts — which escapes what goes into it. Placeholders are
 * `%name%`, filled with fill(), which fills and nothing else: an admin's message built here escapes its own values.
 * Emoji keep one form per glyph: the emoji-presentation form (with U+FE0F) for the symbols that need
 * it (⛔️ 🛍️ 🗜️ ⚠️ ⚙️ ☎️ ♻️ ↩️ ✏️ ⬅️), the bare code point for those that are emoji by themselves.
 */
final class Messages
{
    /** The word for "no limit": a quota of 0, a term of 0, no device cap. */
    public const UNLIMITED = 'نامحدود';

    // Units — quotas, terms and device counts are worded through traffic(), bytes(), duration(), devices().
    private const UNIT_DAYS = 'روز';
    private const UNIT_GB = 'گیگابایت';
    private const UNIT_MB = 'مگابایت';
    /** After a device count: «۲ دستگاه». */
    private const PLAN_DEVICES = 'دستگاه';

    // When a service ends (expiry()): the clock starts at the first connection, so until then there is no date.
    private const EXPIRY_PENDING = '⏳ در انتظار اولین اتصال (%days% روز)';
    private const EXPIRY_DAYS_LEFT = '%days% روز مانده';
    private const EXPIRY_LAST_DAY = 'کمتر از یک روز مانده';
    private const EXPIRY_ENDED = 'تمام شده';

    // A wallet below zero (an agent's credit in use), and an agent without credit (balance(), credit()).
    private const DEBT = '%amount% بدهی';
    private const NO_CREDIT = 'ندارد';

    // The main menu's default labels (the keyboards editor rewords them): a reply-keyboard tap sends the label
    // as a message, so these are also routes (MainMenu).
    public const MENU_BUY = '🔐 خرید اشتراک';
    public const MENU_SERVICES = '🛍️ سرویس‌های من';
    public const MENU_RENEW = '♻️ تمدید سرویس';
    public const MENU_TUTORIAL = '📚 آموزش';
    public const MENU_WALLET = '💰 کیف پول + شارژ';
    public const MENU_AFFILIATES = '👥 زیرمجموعه‌گیری';
    public const MENU_SUPPORT = '☎️ پشتیبانی';
    public const MENU_AGENCY = '🤝 نمایندگی';

    // /broadcast (admins only): a message copied or forwarded to an audience, with live progress and its controls.
    public const BROADCAST_ASK = '📣 پیامی را که می‌خواهید برای کاربران ارسال شود بفرستید — متن، عکس، ویدیو یا هر پیام دیگری. برای فوروارد، پیام کانال را همین‌جا فوروارد کنید.';
    public const BROADCAST_DRAFT = '📣 <b>ارسال همگانی</b> — پیش‌نویس

📝 پیام: %content%
↪️ روش: %mode%
👥 مخاطب: %audience% — <b>%count%</b> نفر%blocked%
📌 پین: %pin%
🔘 دکمه‌ها: %buttons%';
    public const BROADCAST_DRAFT_BLOCKED = ' (%count% نفرشان ربات را مسدود کرده‌اند)';
    public const BROADCAST_MODE_COPY = 'کپی، از طرف ربات';
    public const BROADCAST_MODE_FORWARD = 'فوروارد، با نام فرستنده اصلی';
    public const BROADCAST_ON = 'روشن';
    public const BROADCAST_OFF = 'خاموش';
    public const BROADCAST_NO_BUTTONS = 'ندارد';
    public const BROADCAST_BUTTON_COUNT = '%count% دکمه';
    public const BROADCAST_FORWARD_NO_BUTTONS = 'پیام فورواردی دکمه نمی‌گیرد';
    public const BROADCAST_BTN_MODE = '↪️ روش: %mode%';
    public const BROADCAST_BTN_COPY = 'کپی';
    public const BROADCAST_BTN_FORWARD = 'فوروارد';
    public const BROADCAST_BTN_AUDIENCE = '👥 مخاطب';
    public const BROADCAST_BTN_PIN = '📌 پین: %state%';
    public const BROADCAST_BTN_BUTTONS = '🔘 دکمه‌ها';
    public const BROADCAST_SEND = '✅ ارسال به %count% نفر';
    /** What the admin's message is, on the draft card — by the update's field that carries it. */
    public const BROADCAST_CONTENT = [
        'text' => 'متن',
        'photo' => 'عکس',
        'video' => 'ویدیو',
        'animation' => 'گیف',
        'document' => 'فایل',
        'audio' => 'آهنگ',
        'voice' => 'پیام صوتی',
        'video_note' => 'ویدیو مسیج',
        'sticker' => 'استیکر',
        'poll' => 'نظرسنجی',
    ];
    public const BROADCAST_CONTENT_OTHER = 'پیام';
    public const BROADCAST_AUDIENCE_ASK = '👥 پیام برای چه کسانی ارسال شود؟';
    public const BROADCAST_AUDIENCE_OPTION = '%label% (%count%)';
    public const BROADCAST_AUDIENCE_GONE = 'این مخاطب دیگر وجود ندارد (گروه یا سرورش حذف شده یا مشتری فعالی ندارد)؛ مخاطب دیگری انتخاب کنید.';
    public const BROADCAST_GROUP_ASK = '👥 برای کدام گروه؟';
    public const BROADCAST_SERVER_ASK = '👥 مشتری‌های کدام سرور؟ (کسانی که سرویس فعال روی آن دارند)';
    public const BROADCAST_NO_GROUPS = 'هنوز گروهی ساخته نشده است؛ در پنل، صفحه «کاربران»، بخش «گروه‌ها».';
    public const BROADCAST_NO_SERVERS = 'هیچ سروری نیست که مشتری‌های این ربات سرویس فعالی روی آن داشته باشند.';
    public const BROADCAST_EMPTY_AUDIENCE = 'این مخاطب کسی را ندارد؛ مخاطب دیگری انتخاب کنید.';
    public const BROADCAST_BUTTONS_ASK = '🔘 دکمه‌های زیر پیام را بفرستید: هر ردیف در یک خط، دکمه‌های یک ردیف جدا با «|»، و هر دکمه «متن - لینک»:

<code>عضویت در کانال - https://t.me/mychannel</code>
<code>سایت - https://example.com | پشتیبانی - https://t.me/support</code>';
    public const BROADCAST_BUTTONS_INVALID = '⚠️ «%line%» درست نیست: هر دکمه «متن - لینک» است و لینک با http://، https:// یا tg:// شروع می‌شود (متن حداکثر %max% کاراکتر).';
    public const BROADCAST_BUTTONS_TOO_MANY = '⚠️ حداکثر %rows% ردیف و در هر ردیف %per_row% دکمه.';
    public const BROADCAST_BUTTONS_CLEAR = '🗑️ حذف دکمه‌ها';
    public const BROADCAST_CANCELLED = 'ارسال همگانی لغو شد.';
    public const BROADCAST_EXPIRED = 'این پیش‌نویس دیگر معتبر نیست؛ دوباره /broadcast را بزنید.';
    public const BROADCAST_PROGRESS = '📣 <b>ارسال همگانی #%id%</b> — %status%
👥 %audience%%pin%

✅ رسید: %sent%
🚫 ربات را مسدود کرده‌اند: %blocked%
⚠️ نرسید: %failed%
⏳ %done% از %total%';
    public const BROADCAST_PROGRESS_PINNED = ' · 📌 پین‌شده';
    public const UNPIN_PROGRESS = '📌 <b>لغو پین ارسال #%source%</b> — %status%

✅ برداشته شد: %sent%
⚠️ نشد: %failed%
⏳ %done% از %total%';
    public const BROADCAST_PAUSE = '⏸️ توقف';
    public const BROADCAST_RESUME = '▶️ ادامه';
    public const BROADCAST_STOP = '✖️ لغو ارسال';
    public const BROADCAST_UNPIN = '📌 لغو پین';
    public const BROADCAST_PAUSED = 'ارسال متوقف شد؛ هر وقت خواستید «ادامه» را بزنید.';
    public const BROADCAST_RESUMED = 'ارسال ادامه پیدا کرد.';
    public const BROADCAST_STOPPED = 'ارسال لغو شد؛ کسانی که گرفته‌اند همان را دارند.';
    public const BROADCAST_UNPINNING = 'لغو پین شروع شد.';
    public const BROADCAST_UNPIN_NOT_PINNED = 'این ارسال پین نشده بود.';
    public const BROADCAST_UNPIN_OPEN = 'اول ارسال باید تمام یا لغو شود.';
    public const BROADCAST_UNPIN_NOTHING = 'پینی برای برداشتن نمانده، یا برداشتنش در حال انجام است.';
    public const BROADCAST_UNCHANGED = 'این ارسال دیگر این کار را نمی‌پذیرد.';
    public const BROADCAST_DONE = '📣 ارسال همگانی #%id% تمام شد.
✅ رسید: %sent%
🚫 ربات را مسدود کرده‌اند: %blocked%
⚠️ نرسید: %failed%';

    // /emoji (admins only): premium emoji for the bot's texts, shown to the bot in a message.
    public const EMOJI_ASK = '✨ پیامی را که ایموجی پرمیوم دارد بفرستید — همان‌طور که می‌خواهید مشتری ببیند، با قالب‌بندی و متغیرها. ایموجی‌ها برای پنل ذخیره می‌شوند و متن آماده برای «متن‌های ربات» را برمی‌گردانم.';
    public const EMOJI_NONE = 'این پیام ایموجی پرمیوم ندارد. پیامی بفرستید که دست‌کم یک ایموجی پرمیوم داشته باشد، یا برای خروج یکی از دکمه‌های منو را بزنید.';
    public const EMOJI_SAVED = '✅ %count% ایموجی پرمیوم ذخیره شد (%new% تازه). در پنل، هنگام ویرایش «متن‌های ربات»، از دکمه «ایموجی پرمیوم» در دسترس است.';
    public const EMOJI_WORKS = 'پیام بالا را ربات خودش فرستاده و ایموجی‌ها پرمیوم مانده‌اند؛ ربات می‌تواند در پیام‌هایش از آن‌ها استفاده کند.';
    public const EMOJI_BLOCKED = '⚠️ تلگرام ایموجی‌های پرمیوم را از پیام ربات برداشت و جایشان ایموجی معمولی گذاشت. ربات فقط وقتی ایموجی پرمیوم می‌فرستد که حسابی که ربات را در @BotFather ساخته اشتراک تلگرام پرمیوم داشته باشد؛ تا آن موقع مشتری‌ها ایموجی معمولی می‌بینند.';
    public const EMOJI_TEMPLATE = 'متن آماده برای «متن‌های ربات» (با لمس کپی می‌شود):';
    public const EMOJI_TEMPLATE_LONG = 'متن این پیام برای نشان دادن در این‌جا بلند است؛ ایموجی‌هایش در پنل هست.';

    /** A quota: "۳۰ گیگابایت", "۵۱۲ مگابایت" under a gigabyte, "نامحدود" for 0. */
    public static function traffic(int $bytes): string
    {
        return $bytes <= 0 ? self::UNLIMITED : self::bytes($bytes);
    }

    /** An amount of traffic: "۱٫۵ گیگابایت", "۵۱۲ مگابایت" under a gigabyte (0 is "۰ مگابایت" — say "unlimited" or "unused" yourself). */
    public static function bytes(int $bytes): string
    {
        $bytes = max(0, $bytes);

        return $bytes >= Traffic::GIGABYTE
            ? self::amount($bytes / Traffic::GIGABYTE) . ' ' . self::UNIT_GB
            : self::amount($bytes / Traffic::MEGABYTE) . ' ' . self::UNIT_MB;
    }

    /** A quantity with up to two decimals, trailing zeros dropped, in Persian: "۳۰", "۱٫۵", "۱٬۰۲۴". */
    private static function amount(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 2, '.', ','), '0'), '.');

        return Persian::digits(strtr($text, ['.' => '٫', ',' => '٬']));
    }

    /** A share, whole percents rounded down: "۸۲٪". */
    public static function percent(int $part, int $whole): string
    {
        return Persian::digits($whole > 0 ? intdiv($part * 100, $whole) : 0) . '٪';
    }

    /** What a grant or an extension gave a service: "۳ روز و ۱۰ گیگابایت", "۳ روز" or "۱۰ گیگابایت". */
    public static function gift(int $days, int $bytes): string
    {
        return implode(' و ', array_filter([$days > 0 ? self::duration($days) : '', $bytes > 0 ? self::bytes($bytes) : '']));
    }

    /** "۳۰ روز", "نامحدود" for 0. */
    public static function duration(int $days): string
    {
        return $days > 0 ? Persian::digits($days) . ' ' . self::UNIT_DAYS : self::UNLIMITED;
    }

    /** A number of devices: "۲ دستگاه", "نامحدود" for 0. */
    public static function devices(int $count): string
    {
        return $count > 0 ? Persian::digits($count) . ' ' . self::PLAN_DEVICES : self::UNLIMITED;
    }

    /** A wallet's balance: "۸۰٬۰۰۰ تومان", or "۲۰٬۰۰۰ تومان بدهی" below zero (an agent's credit in use). */
    public static function balance(string $balance): string
    {
        return Money::compare($balance, 0) < 0 ? self::fill(self::DEBT, ['amount' => Money::format(Money::subtract(0, $balance))]) : Money::format($balance);
    }

    /** An agent's credit: "۵۰۰٬۰۰۰ تومان", "ندارد" for none. */
    public static function credit(string $limit): string
    {
        return Money::isPositive($limit) ? Money::format($limit) : self::NO_CREDIT;
    }

    /**
     * When a service ends: "۲۷ آذر ۱۴۰۵ (۹۰ روز مانده)" with a deadline, "⏳ در انتظار اولین اتصال (۹۰ روز)"
     * while the panel has not started the clock, "نامحدود" for no term at all.
     */
    public static function expiry(?\DateTimeInterface $at, int $days): string
    {
        if ($at === null) {
            return $days > 0 ? self::fill(self::EXPIRY_PENDING, ['days' => Persian::digits($days)]) : self::UNLIMITED;
        }

        $left = $at->getTimestamp() - now()->getTimestamp();
        $note = match (true) {
            $left <= 0 => self::EXPIRY_ENDED,
            $left < ServiceTerms::DAY => self::EXPIRY_LAST_DAY,
            default => self::fill(self::EXPIRY_DAYS_LEFT, ['days' => Persian::digits((int) ceil($left / ServiceTerms::DAY))]),
        };

        return Persian::date($at) . ' (' . $note . ')';
    }

    /**
     * The template with its `%variables%` filled, in one pass: a filled-in value is never read again for
     * variables (a plan named "x %balance%" stays that).
     *
     * @param array<string, scalar> $replace
     */
    public static function fill(string $template, array $replace = []): string
    {
        $pairs = [];
        foreach ($replace as $key => $value) {
            $pairs['%' . $key . '%'] = (string) $value;
        }

        return strtr($template, $pairs);
    }
}
