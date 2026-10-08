<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports;

use App\Modules\Bots\CurrentBot;

/**
 * The report group's topics: one per subject, each made by the bot when the group is connected (ReportGroup) and
 * switched on or off by the admin (ReportSettings). The value is the key everywhere — `report_topics.topic`,
 * `report_messages.topic`, the setting `reports.topic.<value>`, the screen's `report_<value>` switch.
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
     * What comes to it in the current bot's group (ShopReports): the topic's first message there, and its switch's hint on
     * the screen. A server's panel that stops answering is the main bot's news alone — an agent's group hears of its own
     * failed deliveries, not of the servers (nor of the agency, a topic it does not have: ReportSettings::topics()).
     */
    public function about(): string
    {
        return match ($this) {
            self::Purchases => 'هر سرویسی که فروخته و تحویل داده می‌شود: مشتری، پلن، لوکیشن، مبلغ و روش پرداخت.',
            self::Renewals => 'هر سرویسی که تمدید می‌شود، با مبلغ و تاریخ پایان جدید.',
            self::Wallet => 'هر بار که کیف پول مشتری شارژ می‌شود، با مبلغ و موجودی جدید.',
            self::Receipts => 'تصویر هر رسید کارت به کارت که مشتری می‌فرستد، با دکمه‌های تایید و رد برای مدیرهای ربات؛ و بعد نتیجه بررسی آن: تایید، رد یا لغو.',
            self::Users => 'هر کسی که اولین بار ربات را استارت می‌کند، با معرفش اگر با لینک دعوت آمده باشد.',
            self::Errors => 'سفارش‌های پرداخت‌شده‌ای که تحویلشان ناموفق بود، با دکمه تلاش دوباره برای مدیرهای ربات'
                . (CurrentBot::isMain() ? '؛ و سرورهایی که پنلشان جواب نمی‌دهد یا دوباره جواب می‌دهد.' : '.'),
            self::Agency => 'هر درخواست نمایندگی، با دکمه‌های تایید (با انتخاب سطح) و رد برای مدیرهای ربات؛ و بعد نتیجه بررسی آن.',
            self::Tickets => 'هر تیکتی که مشتری باز می‌کند و پیام‌های بعدی آن، و پاسخ‌هایی که از پنل داده می‌شود؛ مدیرهای ربات با ریپلای روی پیام‌های هر تیکت همین‌جا پاسخ می‌دهند و با دکمه «بستن تیکت» آن را می‌بندند.',
            self::Reviews => 'هر نظری که در وب‌سایت فروشگاه نوشته می‌شود، با امتیاز و متنش؛ نظر در صفحه «نظرات» پنل تایید یا رد می‌شود و تا تایید نشود در وب‌سایت دیده نمی‌شود.',
        };
    }

    /**
     * Where its reports stand in the queue (ReportSender sends the lowest first, each in the order they came): a receipt
     * waiting for review and a paid order that was not delivered — a customer waits on support for them — go before
     * everything else, whatever else floods the group; a review — anyone on the website writes one, and nobody waits on
     * it — after everything else, so however many come, none holds the shop's own news up.
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
