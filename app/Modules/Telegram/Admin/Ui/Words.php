<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Ui;

/**
 * The management's fixed words: what moves between screens, what a button does, how a state is said — one word for
 * each, so every screen says it the same way. A screen never types these again; it takes them from here (and its
 * emoji for a section from Icons).
 */
final class Words
{
    // Navigation.

    /** The first of every screen's path: the management itself. */
    public const ROOT = 'مدیریت';

    public const BACK = '⬅️ بازگشت';
    public const HOME = '🏠 خانه';
    public const REFRESH = '🔄 تازه‌سازی';
    public const HELP = '❔ راهنما';
    public const SEARCH = '🔎 جستجو';
    public const CLEAR_SEARCH = '✖️ پاک کردن جستجو';

    /** A list's other pages, in reading order: «‹ قبلی» on the right, «بعدی ›» on the left. */
    public const PREVIOUS = '‹ قبلی';
    public const NEXT = 'بعدی ›';

    // Actions.

    public const EDIT = '✏️ ویرایش';
    public const CREATE = '✔️ ساختن';
    public const SAVE = '💾 ذخیره';
    public const DELETE = '🗑 حذف';

    /** The one way out of a question, a confirmation or a wizard. */
    public const CANCEL = '✖️ انصراف';

    public const TURN_ON = '▶️ روشن کردن';
    public const TURN_OFF = '⏸ خاموش کردن';

    /** A row's switch (a plan, a method, a code…): the actions that pair with ACTIVE / INACTIVE, as TURN_ON/OFF with ON/OFF. */
    public const ACTIVATE = '▶️ فعال کردن';
    public const DEACTIVATE = '⏸ غیرفعال کردن';

    public const UP = '🔼';
    public const DOWN = '🔽';

    /** A question's way to leave its field empty, and to put it back as it comes. */
    public const CLEAR = '🗑 خالی کردن';
    public const RESET = '↩️ پیش‌فرض';

    /** A wizard's ways at a step's question (Prompt::ways()): back to the step before, and on past this one unanswered. */
    public const PREVIOUS_STEP = '⬅️ گام قبل';
    public const SKIP = '⏭ رد شدن';

    public const SETTINGS = '⚙️ تنظیمات';

    // Chips: a state, said the same way everywhere.

    public const ON = '🟢 روشن';
    public const OFF = '⏸ خاموش';
    public const ACTIVE = '🟢 فعال';
    public const INACTIVE = '⏸ غیرفعال';
    public const PENDING = '🟡 در انتظار';
    public const FAILED = '🔴 خطا';
    public const REJECTED = '🔴 ردشده';

    /** What is said for a value there is none of. */
    public const NONE = '—';

    /** The mark of the choice in force among a picker's (a plan, a group, a setting's value). */
    public const PICKED = '✅ ';

    /** The mark of an item left unticked among a multi-pick's (tick()); a ticked one has PICKED's. */
    public const UNTICKED = '◻️ ';

    /** A multi-pick's ways to tick every item of the page shown, and to untick them all. */
    public const TICK_PAGE = '☑️ همه این صفحه';
    public const TICK_NONE = '◻️ هیچ‌کدام';

    /** The mark of the filter in force among a list's chips. */
    public const CHIP = '● ';

    // A question's lines.

    public const CURRENT = 'مقدار فعلی';
    public const EXAMPLE = 'مثال';
    public const LIMITS = 'محدوده';
    public const TYPE_HERE = 'پاسخ را همین‌جا بنویسید و بفرستید.';
    public const SEND_HERE = 'پاسخ را همین‌جا بفرستید.';
    public const AGAIN = 'دوباره بفرستید، یا انصراف دهید.';

    /** A question's refusal of what is no number, and of what is no amount of Toman. */
    public const NOT_NUMBER = 'یک عدد بنویسید.';
    public const NOT_TOMAN = 'مبلغ را به تومان بنویسید، مثل ۵۰۰۰۰ یا «۵۰ هزار تومان».';

    // The home and what moves around it.

    public const WAITING = '🔔 در انتظار';
    public const NOTHING_WAITING = '✅ کاری در انتظار نیست.';
    public const NOTHING_FOUND = 'چیزی پیدا نشد.';

    /** Said over the home when a button's screen is gone or its data unreadable (a window of an older version). */
    public const OLD_BUTTON = 'این دکمه قدیمی است؛ از همین‌جا ادامه دهید.';

    /** «✏️ حداقل مبلغ شارژ»: a button that asks a field's new value. */
    public static function edit(string $label): string
    {
        return '✏️ ' . $label;
    }

    /** «✅ » or «◻️ »: the mark before a multi-pick's item, ticked or not. */
    public static function tick(bool $on): string
    {
        return $on ? self::PICKED : self::UNTICKED;
    }

    /** «➕ افزودن پلن». */
    public static function add(string $noun): string
    {
        return '➕ افزودن ' . $noun;
    }

    /**
     * «✔️ بله، ثبت شود»: a confirmation's yes — under the action's own icon when it destroys or changes something for
     * good («🗑 بله، حذف شود», «✂️ بله، قطع شود»), which Admin::confirm() also reads to draw it red.
     */
    public static function yes(string $what, string $icon = '✔️'): string
    {
        return $icon . ' بله، ' . $what;
    }

    /** «📋 کپی آدرس»: a button that copies a value. */
    public static function copy(string $what): string
    {
        return '📋 کپی ' . $what;
    }

    /**
     * «▶️ روشن کردن «label»» or «⏸ خاموش کردن «label»»: a switch's button, saying the state a tap sets — a label
     * that quotes something itself («روش «تمدید پلن فعلی»») unquoted.
     */
    public static function turn(bool $on, string $label, int $most = Button::LABEL_MOST): string
    {
        $verb = $on ? self::TURN_ON : self::TURN_OFF;
        if (str_contains($label, '«')) {
            return $verb . ' ' . Fmt::cut($label, max(8, $most - mb_strlen($verb) - 1));
        }

        return $verb . ' «' . Fmt::cut($label, max(8, $most - mb_strlen($verb) - 3)) . '»';
    }

    /** «صفحه ۲ از ۵». */
    public static function page(int $page, int $pages): string
    {
        return 'صفحه ' . Fmt::number($page) . ' از ' . Fmt::number($pages);
    }

    /** «گام ۲ از ۶»: where a wizard is. */
    public static function step(int $n, int $of): string
    {
        return 'گام ' . Fmt::number($n) . ' از ' . Fmt::number($of);
    }
}
