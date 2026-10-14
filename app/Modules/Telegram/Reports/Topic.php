<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports;

use App\Modules\Bots\CurrentBot;

/**
 * The report group's topics, one per subject. The value is stored (`report_topics.topic`, `report_messages.topic`, the
 * setting `reports.topic.<value>`), so it never changes.
 */
enum Topic: string
{
    case Purchases = 'purchases';
    case Renewals = 'renewals';
    case Wallet = 'wallet';
    case Receipts = 'receipts';
    case Users = 'users';
    case Errors = 'errors';
    case Agency = 'agency';
    case Tickets = 'tickets';
    case Reviews = 'reviews';

    /** The topic's name in the group, and its switch's label on the screen. */
    public function title(): string
    {
        return match ($this) {
            self::Purchases => 'خریدها',
            self::Renewals => 'تمدیدها',
            self::Wallet => 'شارژ کیف پول',
            self::Receipts => 'رسیدها',
            self::Users => 'کاربران جدید',
            self::Errors => 'خطاها',
            self::Agency => 'نمایندگی',
            self::Tickets => 'تیکت‌ها',
            self::Reviews => 'نظرات',
        };
    }

    /**
     * What the topic carries in the current bot's group: its first message there and its switch's hint. Servers' news
     * goes to the main bot's group only.
     */
    public function about(): string
    {
        return match ($this) {
            self::Purchases => 'هر سرویسی که فروخته و تحویل داده می‌شود: مشتری، پلن، لوکیشن، مبلغ و روش پرداخت؛ هر سرویسی که مشتری با فرستادن لینکش به حساب خودش اضافه می‌کند؛ هر سرویسی که به مشتری دیگری منتقل می‌شود؛ و نتیجه قرعه‌کشی شبانه خریداران.',
            self::Renewals => 'هر سرویسی که تمدید می‌شود، با مبلغ و تاریخ پایان جدید؛ و هر حجم، زمان یا کاربر اضافه‌ای که مشتری برای سرویسش می‌خرد.',
            self::Wallet => 'هر بار که کیف پول مشتری شارژ می‌شود، با مبلغ و موجودی جدید؛ و هر انتقال موجودی از کیف پول مشتری به مشتری دیگر، با موجودی هر دو.',
            self::Receipts => 'تصویر هر رسید کارت به کارت که مشتری می‌فرستد، با دکمه‌های تایید و رد برای مدیرهای ربات؛ و بعد نتیجه بررسی آن: تایید، رد یا لغو؛ و هر بار که پشتیبانی رسیدهای در انتظار بررسی را یکجا رد می‌کند.',
            self::Users => 'هر کسی که اولین بار ربات را استارت می‌کند، با معرفش اگر با لینک دعوت آمده باشد.',
            self::Errors => 'سفارش‌های پرداخت‌شده‌ای که تحویلشان ناموفق بود، با دکمه تلاش دوباره برای مدیرهای ربات'
                . (CurrentBot::isMain() ? '؛ سرورهایی که پنلشان جواب نمی‌دهد یا دوباره جواب می‌دهد؛ و شروع و پایان انتقال سرویس‌های یک سرور به سرور دیگر.' : '.'),
            self::Agency => 'هر درخواست نمایندگی، با دکمه‌های تایید (با انتخاب سطح) و رد برای مدیرهای ربات؛ و بعد نتیجه بررسی آن؛ و هر بار که فروش ربات یک نماینده متوقف یا دوباره باز می‌شود.',
            self::Tickets => 'هر تیکتی که مشتری باز می‌کند و پیام‌های بعدی آن، و پاسخ‌هایی که از پنل داده می‌شود؛ مدیرهای ربات با ریپلای روی پیام‌های هر تیکت همین‌جا پاسخ می‌دهند و با دکمه «بستن تیکت» آن را می‌بندند.',
            self::Reviews => 'هر نظری که در وب‌سایت فروشگاه نوشته می‌شود، با امتیاز و متنش؛ نظر در صفحه «نظرات» پنل تایید یا رد می‌شود و تا تایید نشود در وب‌سایت دیده نمی‌شود.',
        };
    }

    /**
     * Its reports' place in the queue, lowest first: receipts and failed deliveries (a customer waits on them) before
     * everything; reviews (anyone can write many) after everything, so they never hold the shop's news up.
     */
    public function priority(): int
    {
        return match ($this) {
            self::Receipts, self::Errors => 0,
            self::Reviews => 2,
            default => 1,
        };
    }

    /** The topic icon's colour — one of the six Telegram allows (createForumTopic); shown when no custom icon fits. */
    public function color(): int
    {
        return match ($this) {
            self::Purchases => 0x8EEE98, // green
            self::Renewals => 0x6FB9F0, // blue
            self::Wallet => 0xFFD67E, // yellow
            self::Receipts => 0xCB86DB, // violet
            self::Users => 0xFF93B2, // pink
            self::Errors => 0xFB6F5F, // red
            self::Agency => 0x6FB9F0, // blue
            self::Tickets => 0xFFD67E, // yellow
            self::Reviews => 0xCB86DB, // violet
        };
    }

    /**
     * Emoji for the topic's icon, preferred first: the first one Telegram offers as a topic icon
     * (getForumTopicIconStickers) is used. Compared without the emoji variation selector, which the
     * sticker list may or may not carry.
     *
     * @return list<string>
     */
    public function icons(): array
    {
        return match ($this) {
            self::Purchases => ['🛒', '🛍', '💎'],
            self::Renewals => ['🔄', '♻', '🔁', '📆', '⏳'],
            self::Wallet => ['💰', '💸', '🪙', '💳'],
            self::Receipts => ['🧾', '📝', '🖨', '📁'],
            self::Users => ['👤', '👥', '👋', '🗣'],
            self::Errors => ['⚠', '❗', '‼', '🔥'],
            self::Agency => ['🤝', '💼', '🏆', '⭐'],
            self::Tickets => ['💬', '🎟', '🗣', '✉'],
            self::Reviews => ['⭐', '🌟', '👍', '❤'],
        };
    }
}
