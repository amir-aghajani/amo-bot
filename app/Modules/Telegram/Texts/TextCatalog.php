<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Texts;

use App\Modules\Bots\CurrentBot;

/**
 * Describes every BotText: its group on the «متن‌های ربات» screen, its kind, title, where the customer meets it, its
 * default wording (Telegram HTML with `%variables%`) and the variables the code fills in. A variable means the same
 * thing in every text (VARIABLES). Each group is a method listing its texts through the kind helpers (message(),
 * part()…). Emoji keep one form per glyph: the U+FE0F form for symbols that need it (⛔️ 🛍️ 🗜️ ⚠️ ⚙️ ☎️ ♻️ ↩️ ✏️ ⬅️).
 * The keys are stored in the settings table: never rename one.
 *
 * @phpstan-type Draft array{TextKind, string, string, string, array<string, string|null>, list<string>}
 */
final class TextCatalog
{
    /** The screen's groups, in its order. */
    public const GROUPS = [
        'general' => 'عمومی',
        'gates' => 'شماره موبایل و عضویت در کانال',
        'shop' => 'خرید اشتراک',
        'checkout' => 'پرداخت و رسید',
        'delivery' => 'تحویل و نتیجه پرداخت',
        'services' => 'سرویس‌های من',
        'service' => 'صفحه سرویس',
        'rotation' => 'تغییر لینک',
        'support_actions' => 'کارهای پشتیبانی روی سرویس',
        'renewal' => 'تمدید',
        'extras' => 'حجم و زمان اضافه',
        'reminders' => 'یادآوری',
        'wallet' => 'کیف پول',
        'referral' => 'زیرمجموعه‌گیری',
        'agency' => 'نمایندگی',
        'account' => 'حساب وب‌سایت',
        'support' => 'پشتیبانی',
        'gift_codes' => 'کد هدیه',
        'wheel' => 'گردونه شانس',
        'test_accounts' => 'اکانت تست',
        'loyalty' => 'سطح وفاداری، کش‌بک و قرعه‌کشی',
    ];

    /** Every variable a text may offer: what it holds, and a value for the screen's preview. */
    public const VARIABLES = [
        'name' => ['نام مشتری در تلگرام', 'امیر'],
        'client' => ['نام کاربری سرویس روی پنل', 'amir_1'],
        'plan' => ['نام پلن', 'یک‌ماهه'],
        'server' => ['نام سرور (لوکیشن)', 'آلمان'],
        'duration' => ['مدت سرویس', '۳۰ روز'],
        'expires' => ['تاریخ پایان سرویس و روزهای مانده', '۲۷ آبان ۱۴۰۵ (۳۰ روز مانده)'],
        'traffic' => ['حجم سرویس', '۳۰ گیگابایت'],
        'remaining' => ['حجم باقی‌مانده', '۱۲ گیگابایت'],
        'used' => ['حجم مصرف‌شده', '۱۸ گیگابایت'],
        'subscription' => ['لینک اشتراک سرویس', 'https://sub.example.com/sub/amir1'],
        'status' => ['وضعیت سرویس (یکی از متن‌های وضعیت)', '✅ فعال'],
        'last_online' => ['آخرین اتصال', '۲۵ آبان ۱۴۰۵، ۱۸:۳۰'],
        'connection' => ['وضعیت اتصال (آنلاین یا آفلاین)', '🟢 آنلاین'],
        'category' => ['نام دسته', 'اقتصادی'],
        'description' => ['توضیح پلن در خط جدا، اگر پلن توضیح داشته باشد', "\nمناسب استفاده روزانه"],
        'devices' => ['تعداد دستگاه', '۲ دستگاه'],
        'price' => ['قیمت پلن', '۱۲۰٬۰۰۰ تومان'],
        'regular' => ['قیمت عادی پلن، بدون قیمت خرید اول یا قیمت نماینده', '۱۵۰٬۰۰۰ تومان'],
        'plan_note' => ['یادداشت پیش‌فاکتور پلن، از فرم پلن', 'این سرویس روی اپلیکیشن‌های Happ و v2rayNG تست شده است.'],
        'order' => ['شماره سفارش', '142'],
        'amount' => ['مبلغ', '۱۲۰٬۰۰۰ تومان'],
        'balance' => ['موجودی کیف پول', '۸۰٬۰۰۰ تومان'],
        'missing' => ['کسری موجودی', '۴۰٬۰۰۰ تومان'],
        'reason' => ['دلیل', 'پاسخی از درگاه نرسید'],
        'card' => ['شماره کارت', '6037-9977-0000-1119'],
        'holder' => ['نام صاحب کارت', 'سارا احمدی'],
        'instructions' => ['توضیح روش پرداخت، از صفحه روش‌های پرداخت', 'لطفا فقط از کارت‌های به نام خودتان واریز کنید.'],
        'outcome' => ['نتیجه تایید رسید (یکی از متن‌های نتیجه رسید)', 'اشتراک شما فعال و همین‌جا ارسال می‌شود'],
        'wait' => ['حداکثر زمان انتظار', '۳۰ دقیقه'],
        'note' => ['توضیح پشتیبانی (متن «توضیح پشتیبانی»)، یا خالی', "\nتوضیح پشتیبانی: سفارش تکراری بود"],
        'comment' => ['متنی که پشتیبانی نوشته است', 'سفارش تکراری بود'],
        'page' => ['شماره صفحه', '۱'],
        'pages' => ['تعداد صفحه‌ها', '۳'],
        'days' => ['تعداد روز', '۲'],
        'gift' => ['زمان و حجمی که اضافه شد', '۳ روز و ۱۰ گیگابایت'],
        'leftover' => ['توضیح حجم باقی‌مانده (یکی از دو متن حجم باقی‌مانده)، یا خالی', "\n\n📥 حجم باقی‌مانده دوره قبل هم به دوره جدید منتقل شد."],
        'expiring' => ['حجمی که با پایان دوره فعلی از بین می‌رود', '۸ گیگابایت'],
        'date' => ['تاریخ پایان دوره فعلی', '۲۷ آبان ۱۴۰۵'],
        'next' => ['حجم دوره جدید', '۳۰ گیگابایت'],
        'hint' => ['نکته تمدید خودکار (متن «نکته تمدید خودکار»)، یا خالی', "\n\n💡 با روشن کردن «تمدید خودکار» در صفحه سرویس، سرویس پیش از پایان از کیف پول تمدید می‌شود."],
        'percent' => ['درصد مصرف حجم', '۸۳٪'],
        'history' => ['آخرین تراکنش‌ها، هر کدام در یک خط', "➕ ۵۰٬۰۰۰ تومان · شارژ کیف پول · ۲۰ آبان ۱۴۰۵\n➖ ۱۲۰٬۰۰۰ تومان · خرید یک‌ماهه · ۱۸ آبان ۱۴۰۵"],
        'entry' => ['شرح تراکنش', 'شارژ کیف پول'],
        'when' => ['تاریخ تراکنش', '۲۰ آبان ۱۴۰۵'],
        'min' => ['حداقل مبلغ شارژ', '۱۰٬۰۰۰ تومان'],
        'contact' => ['راه تماس با پشتیبانی، از تنظیمات ربات', '@amo_support'],
        'title' => ['نام کانال', 'کانال ما'],
        'terms' => ['شرط‌های زیرمجموعه‌گیری: شرط پورسانت (یکی از دو متن شرط پورسانت)، پورسانت تمدید، و هدیه شروع و جایزه دعوت وقتی روشن‌اند', "دوستانتان را با لینک زیر به ربات دعوت کنید؛ از هر پرداخت موفق آن‌ها ۱۰٪ به عنوان پورسانت به کیف پول شما اضافه می‌شود.\n\n🎁 هدیه شروع: با اولین خرید هر دوستتان، ۲۰٬۰۰۰ تومان به کیف پول شما و ۱۰٬۰۰۰ تومان به کیف پول خود او اضافه می‌شود."],
        'link' => ['لینک دعوت مشتری', 'https://t.me/amo_shop_bot?start=ref_k7m2p9qa'],
        'referrals' => ['تعداد زیرمجموعه‌ها', '۱۲'],
        'earned' => ['جمع پورسانت‌های دریافتی', '۸۴٬۰۰۰ تومان'],
        'rate' => ['درصد پورسانت، از تنظیمات ربات', '۱۰٪'],
        'commission' => ['مبلغ پورسانت', '۱۲٬۰۰۰ تومان'],
        'paid' => ['مبلغی که زیرمجموعه پرداخت کرد', '۱۲۰٬۰۰۰ تومان'],
        'trigger' => ['زمان هدیه شروع: با عضویت یا با اولین خرید', 'با اولین خرید'],
        'you' => ['هدیه شروع معرف', '۲۰٬۰۰۰ تومان'],
        'friend' => ['هدیه شروع زیرمجموعه', '۱۰٬۰۰۰ تومان'],
        'invites' => ['هر چند زیرمجموعه خریدار یک جایزه', '۵'],
        'buyers' => ['تعداد زیرمجموعه‌هایی که خرید کرده‌اند', '۱۰'],
        'levels' => ['سطح‌های نمایندگی با قیمت هر گیگابایت هر کدام، هر کدام در یک خط', "• برنزی: هر گیگابایت ۴٬۰۰۰ تومان\n• طلایی: هر گیگابایت ۳٬۰۰۰ تومان"],
        'level' => ['سطح نمایندگی', 'طلایی'],
        'credit' => ['اعتبار خرید نماینده (تا چه اندازه موجودی‌اش می‌تواند منفی شود)', '۵۰۰٬۰۰۰ تومان'],
        'bot' => ['ربات نماینده', '@my_shop_bot'],
        'customers' => ['تعداد مشتری‌های ربات نماینده', '۱۲۰'],
        'sold' => ['تعداد سرویس‌های فروخته‌شده ربات نماینده', '۴۲'],
        'active' => ['تعداد سرویس‌های فعال', '۳۰'],
        'minutes' => ['چند دقیقه', '۱۰'],
        'login' => ['لینک ورود به پنل نمایندگی', 'https://shop.example.com/agent/login#code=Xq2v8LmP3tR9sKw4yZ7bHn1cDfGj5aQe'],
        'ticket' => ['شماره تیکت', '37'],
        'subject' => ['موضوع تیکت', 'قطعی اتصال سرور آلمان'],
        'answer' => ['متن پاسخ پشتیبانی', 'سلام، مشکل سرور برطرف شد؛ لطفا یک بار اتصال را قطع و وصل کنید.'],
        'picture' => ['اشاره به تصویر همراه پاسخ (متن «تصویر همراه پاسخ»)، یا خالی', "\n\n🖼️ این پاسخ یک تصویر هم دارد."],
        'service' => ['سرویسی که تیکت درباره آن است (متن «سرویس تیکت»)، یا خالی', "\n🔖 سرویس: <code>amir_1</code>"],
        'rating' => ['امتیاز مشتری به تیکت، از ۱ تا ۵', '۴'],
        'rated' => ['امتیاز مشتری به تیکت (متن «امتیاز تیکت»)، یا خالی', "\n⭐ امتیاز شما: ۴ از ۵"],
        'messages' => ['آخرین پیام‌های تیکت، هر کدام با نویسنده و زمانش (متن‌های «پیام مشتری» و «پیام پشتیبانی»)', "\n👤 <b>شما</b> · ۲۵ آبان ۱۴۰۵، ۱۸:۳۰\nسرویس من از دیشب وصل نمی‌شود.\n\n🎧 <b>پشتیبانی</b> · ۲۵ آبان ۱۴۰۵، ۱۹:۰۵\nسلام، مشکل سرور برطرف شد؛ لطفا یک بار اتصال را قطع و وصل کنید."],
        'message' => ['متن پیام', 'سرویس من از دیشب وصل نمی‌شود.'],
        'earlier' => ['تعداد پیام‌های قبلی‌ای که نشان داده نمی‌شود', '۳'],
        'reopens' => ['اینکه پیام، تیکت بسته را دوباره باز می‌کند (متن «باز شدن دوباره تیکت»)، یا خالی', "\n\n🔓 این تیکت بسته شده است؛ با فرستادن پیام دوباره باز می‌شود."],
        'number' => ['شماره تصویر در صفحه تیکت؛ دکمه «تصویر» با همین شماره آن را می‌فرستد', '۱'],
        'kept' => ['اینکه تصویر همراه پیام نگه داشته شد و با پیام بعدی می‌رود (متن «تصویر نگه داشته شد»)، یا خالی', "\n\n🖼️ تصویر شما نگه داشته شد و با پیام بعدی‌تان به تیکت فرستاده می‌شود."],
        'way' => ['روش ورود (تلگرام، گوگل یا ایمیل)', 'گوگل'],
        'extra' => ['حجم یا زمان اضافه', '۱۰ گیگابایت'],
        'extra_traffic' => ['حجم اضافه', '۱۰ گیگابایت'],
        'extra_time' => ['زمان اضافه', '۷ روز'],
        'unit_price' => ['قیمت هر گیگابایت یا هر روز', '۳٬۰۰۰ تومان'],
        'least' => ['کمترین مقدار دلخواه', '۱'],
        'most' => ['بیشترین مقدار دلخواه', '۱۰۰'],
        'typed' => ['متن مقدار دلخواه، یا خالی', "\n\n✏️ برای حجم دلخواه، عددی بین ۱ تا ۱۰۰ گیگابایت بنویسید؛ هر گیگابایت ۳٬۰۰۰ تومان."],
        'restart' => ['متن «زمان تمام‌شده»، یا خالی', "\n\n⏳ زمان این سرویس تمام شده است؛ روزهایی که می‌خرید از اولین اتصال بعدی شما شمرده می‌شود."],
        'discount' => ['تخفیف، با نام و درصدش', 'فروش ویژه نوروز ۲۰٪'],
        'prize' => ['جایزه گردونه، همان‌طور که گردونه نشانش می‌دهد', '۱۰ گیگ حجم'],
        'prizes' => ['جایزه‌های گردونه، هر کدام در یک خط (متن «جایزه گردونه»)', "🎁 ۱۰ گیگ حجم\n🎁 ۵۰ هزار تومان شارژ\n🎁 پوچ"],
        'cost' => ['هزینه و فاصله چرخش‌ها (یکی از دو متن «هزینه گردونه» و «گردونه رایگان»)', "\n\n🎁 چرخیدن رایگان است؛ هر ۲۴ ساعت یک بار می‌توانید بچرخانید."],
        'turn' => ['زمان چرخش بعدی (متن «چرخش بعدی»)، یا خالی وقتی همین حالا می‌شود چرخاند', "\n\n🕒 چرخش بعدی شما: ۲۵ آبان ۱۴۰۵، ۱۸:۳۰ (۵ ساعت دیگر)"],
        'every' => ['فاصله دو چرخش، از تنظیمات گردونه', '۲۴ ساعت'],
        'left' => ['زمان مانده تا چرخش بعدی', '۵ ساعت'],
        'given' => ['آنچه جایزه داد و کجا رفت (یکی از متن‌های «جایزه داده شد»)', '۵۰٬۰۰۰ تومان به کیف پول شما اضافه شد؛ موجودی: ۸۰٬۰۰۰ تومان.'],
        'refund' => ['برگشت هزینه چرخش (متن «برگشت هزینه چرخش»)، یا خالی', "\n💰 هزینه چرخش به کیف پول شما برگشت."],
        'servers' => ['سرورهایی که اکانت تست می‌دهند، هر کدام در یک خط (متن «سرور اکانت تست»)', "🌍 <b>آلمان</b>: ۵۰۰ مگابایت، ۱ روز (از اولین اتصال)\n🌍 <b>هلند</b>: ۱ گیگابایت، ۱۲ ساعت"],
        'hold' => ['اینکه مدت اکانت تست از اولین اتصال شمرده می‌شود (متن «از اولین اتصال»)، یا خالی', ' (از اولین اتصال)'],
        'next_test' => ['زمانی که اکانت تست بعدی ممکن می‌شود', '۲۷ آبان ۱۴۰۵، ۱۸:۳۰'],
        'loyalty_tier' => ['سطح وفاداری مشتری', 'طلایی'],
        'next_tier' => ['سطح وفاداری بعدی', 'الماس'],
        'to_next_tier' => ['مبلغ خریدی که تا سطح بعدی مانده', '۲۵۰٬۰۰۰ تومان'],
        'tier_climb' => ['فاصله تا سطح بعدی (متن «تا سطح بعدی»)، یا خالی در بالاترین سطح', ' — ۲۵۰٬۰۰۰ تومان تا سطح الماس'],
        'tier_discount' => ['درصد تخفیف سطح', '۵٪'],
        'tier_cashback' => ['درصد کش‌بک اضافه سطح', '۲٪'],
        'tier_perks' => ['مزیت‌های سطح، هر کدام در یک خط (متن‌های «تخفیف سطح» و «کش‌بک سطح»)، یا خالی', "\n🏷️ ۵٪ تخفیف روی خریدهای شما\n💸 ۲٪ کش‌بک اضافه از هر پرداخت"],
        'cashback_amount' => ['مبلغ کش‌بک', '۶٬۰۰۰ تومان'],
        'draw_prize' => ['جایزه قرعه‌کشی شبانه', '۵۰٬۰۰۰ تومان شارژ کیف پول'],
    ];

    /** What `%price%` means on a plan's button and its details: the customer's own price. */
    private const PLAN_PRICE = 'قیمت پلن برای این مشتری: قیمت خرید اول اگر هنوز سرویسی نخریده، یا قیمت نماینده اگر نماینده یا همکار است، و بعد از بیشترین تخفیف خودکار او (فروش ویژه، تخفیف اختصاصی یا سطح وفاداری)';

    /** What every text about one service is filled with (Notifications\ServiceCard::values()). */
    private const SERVICE = ['client', 'plan', 'server', 'duration', 'expires', 'traffic', 'remaining', 'subscription'];

    /** The groups only the main bot says: an agent's bot has no agency of its own (MainMenu::absent()). */
    private const MAIN_BOT_ONLY = ['agency'];

    /** And the texts of other groups only the main bot says: an agent's credit is on their wallet with the main bot. */
    private const MAIN_BOT_ONLY_TEXTS = [BotText::WalletCredit];

    /** @var array<string, TextSpec>|null */
    private static ?array $specs = null;

    public static function spec(BotText $text): TextSpec
    {
        return (self::$specs ??= self::build())[$text->value] ?? throw new \LogicException("The catalog does not describe the bot text \"{$text->value}\".");
    }

    /** @return array<string, string> The screen's groups the current bot says, in its order (GROUPS). */
    public static function groups(): array
    {
        return CurrentBot::isMain() ? self::GROUPS : array_diff_key(self::GROUPS, array_flip(self::MAIN_BOT_ONLY));
    }

    /** Whether the current bot ever says the text — what its «متن‌های ربات» screen lists and lets be reworded. */
    public static function says(BotText $text): bool
    {
        return array_key_exists(self::spec($text)->group, self::groups()) && (CurrentBot::isMain() || !in_array($text, self::MAIN_BOT_ONLY_TEXTS, true));
    }

    /** @return array<string, TextSpec> Every text's spec, by its key, each in the group whose method declares it. */
    private static function build(): array
    {
        $groups = [
            'general' => self::general(),
            'gates' => self::gates(),
            'shop' => self::shop(),
            'checkout' => self::checkout(),
            'delivery' => self::delivery(),
            'services' => self::services(),
            'service' => self::service(),
            'rotation' => self::rotation(),
            'support_actions' => self::supportActions(),
            'renewal' => self::renewal(),
            'extras' => self::extras(),
            'reminders' => self::reminders(),
            'wallet' => self::wallet(),
            'referral' => self::referral(),
            'agency' => self::agency(),
            'account' => self::account(),
            'support' => self::support(),
            'gift_codes' => self::giftCodes(),
            'wheel' => self::wheel(),
            'test_accounts' => self::testAccounts(),
            'loyalty' => self::loyalty(),
        ];

        $specs = [];
        foreach ($groups as $group => $drafts) {
            foreach ($drafts as $key => $draft) {
                $specs[$key] = new TextSpec($group, ...$draft);
            }
        }

        return $specs;
    }

    /** @return array<string, Draft> */
    private static function general(): array
    {
        return [
            BotText::Welcome->value => self::message('خوش‌آمدگویی', 'وقتی مشتری ربات را استارت می‌کند و در تلگرام نام دارد؛ منوی اصلی همراه آن می‌آید.', "سلام %name% 👋\nخوش آمدید! یکی از گزینه‌های زیر را انتخاب کنید.", self::vars('name')),
            BotText::WelcomeNameless->value => self::message('خوش‌آمدگویی بدون نام', 'همان خوش‌آمدگویی، برای حسابی که در تلگرام نامی ندارد.', "سلام 👋\nخوش آمدید! یکی از گزینه‌های زیر را انتخاب کنید."),
            BotText::MenuPrompt->value => self::message('همراه منوی اصلی', 'متنی که منوی اصلی همراه آن دوباره فرستاده می‌شود.', 'یکی از گزینه‌های زیر را انتخاب کنید:'),
            BotText::Unknown->value => self::message('پیام نامفهوم', 'وقتی مشتری چیزی می‌فرستد که ربات برایش پاسخی ندارد؛ منوی اصلی همراه آن می‌آید.', 'متوجه نشدم. لطفا از منوی زیر استفاده کنید.'),
            BotText::Error->value => self::popup('خطای ربات', 'وقتی در پاسخ به مشتری خطایی پیش می‌آید؛ به صورت پیام یا اعلان کوتاه.', 'مشکلی پیش آمد. لطفا کمی بعد دوباره تلاش کنید.'),
            BotText::Banned->value => self::popup('حساب مسدود', 'پاسخ ربات به هر پیام یا دکمه مشتری مسدودشده.', 'حساب شما مسدود شده است. اگر فکر می‌کنید اشتباهی رخ داده با پشتیبانی تماس بگیرید.'),
            BotText::BotOff->value => self::popup('ربات خاموش', 'وقتی ربات از تنظیمات ربات خاموش است؛ مشتری به جای هر پاسخی همین را می‌بیند.', '⛔️ ربات فعلا غیرفعال است. لطفا کمی بعد دوباره سر بزنید.'),
            BotText::Tutorial->value => self::message('آموزش', 'صفحه «آموزش» منوی اصلی؛ راهنمای اتصال و استفاده از سرویس‌ها را اینجا بنویسید.', '📚 <b>آموزش اتصال</b>

۱. یک برنامه اتصال نصب کنید که لینک اشتراک می‌گیرد.
۲. در «سرویس‌های من» سرویس خود را باز کنید و «لینک اشتراک» را بزنید؛ لینک با یک بار زدن کپی می‌شود.
۳. لینک را در برنامه اضافه کنید (معمولا با گزینه افزودن از کلیپ‌بورد) و وصل شوید.

اگر سوالی دارید یا وصل نشدید، از «پشتیبانی» تیکت بفرستید؛ پاسخ همین‌جا به شما می‌رسد.'),
            BotText::Cancel->value => self::button('دکمه انصراف', 'زیر پرسش تغییر لینک؛ کارت پیش‌نویس ارسال همگانی، که فقط مدیرهای ربات می‌بینند، هم همین دکمه را دارد.', '❌ انصراف'),
            BotText::Back->value => self::button('دکمه بازگشت', 'دکمه بازگشت زیر صفحه‌های ربات.', '⬅️ بازگشت'),
        ];
    }

    /** @return array<string, Draft> */
    private static function gates(): array
    {
        return [
            BotText::PhonePrompt->value => self::message('درخواست شماره موبایل', 'وقتی تایید شماره موبایل روشن است و مشتری هنوز شماره‌اش را نفرستاده.', '📱 برای استفاده از ربات باید شماره موبایل خود را تایید کنید.
روی دکمه زیر بزنید تا شماره‌تان از تلگرام ارسال شود؛ لازم نیست چیزی تایپ کنید.'),
            BotText::PhoneButton->value => self::button('دکمه ارسال شماره', 'دکمه‌ای که شماره تلگرام مشتری را می‌فرستد.', '📱 ارسال شماره موبایل'),
            BotText::PhoneNotYours->value => self::message('شماره متعلق به دیگری', 'وقتی مشتری شماره حساب دیگری را می‌فرستد.', 'این شماره متعلق به حساب تلگرام شما نیست. لطفا از دکمه زیر استفاده کنید تا شماره خودتان ارسال شود.'),
            BotText::PhoneVerified->value => self::message('تایید شماره', 'بعد از اینکه شماره مشتری تایید شد.', '✅ شماره شما تایید شد.'),
            BotText::JoinPrompt->value => self::message('درخواست عضویت', 'وقتی عضویت در کانال‌ها لازم است؛ دکمه هر کانال و «عضو شدم» زیر آن می‌آید.', '📢 برای استفاده از ربات ابتدا در کانال‌های زیر عضو شوید و بعد روی «عضو شدم» بزنید:'),
            BotText::JoinButton->value => self::button('دکمه «عضو شدم»', 'زیر درخواست عضویت، برای بررسی دوباره.', '✅ عضو شدم'),
            BotText::JoinChannel->value => self::button('دکمه کانال', 'زیر درخواست عضویت، یک دکمه برای هر کانالی که مشتری هنوز عضو آن نیست.', '📢 %title%', self::vars('title'), ['title']),
            BotText::JoinStillMissing->value => self::popup('هنوز عضو نیست', 'اعلان وقتی مشتری «عضو شدم» را می‌زند ولی هنوز عضو یکی از کانال‌ها نیست.', 'هنوز عضو «%title%» نشده‌اید.', self::vars('title')),
            BotText::JoinDone->value => self::popup('تایید عضویت', 'اعلان کوتاه بعد از اینکه عضویت مشتری تایید شد.', '✅ عضویت شما تایید شد.'),
        ];
    }

    /** @return array<string, Draft> */
    private static function shop(): array
    {
        return [
            BotText::CategoriesTitle->value => self::message('انتخاب دسته', 'بالای دکمه‌های دسته‌ها در «خرید اشتراک».', 'یک دسته انتخاب کنید:'),
            BotText::CategoryOther->value => self::button('دسته «سایر پلن‌ها»', 'نام دسته پلن‌هایی که دسته‌ای ندارند.', 'سایر پلن‌ها'),
            BotText::CategoryEmpty->value => self::message('دسته خالی', 'وقتی دسته‌ای باز می‌شود که پلن قابل فروشی ندارد.', 'در دسته «%category%» فعلا پلنی برای فروش نیست.', self::vars('category')),
            BotText::PlansTitle->value => self::message('انتخاب پلن', 'بالای دکمه‌های پلن‌ها.', 'یک پلن انتخاب کنید:'),
            BotText::PlansEmpty->value => self::message('پلنی نیست', 'وقتی هیچ پلنی برای فروش نیست.', 'در حال حاضر پلنی موجود نیست. لطفا بعدا مراجعه کنید.'),
            BotText::CategoryPlans->value => self::message('انتخاب پلن در دسته', 'بالای دکمه‌های پلن‌های یک دسته.', '<b>%category%</b>

یک پلن انتخاب کنید:', self::vars('category')),
            BotText::PlanButton->value => self::button('دکمه پلن', 'هر پلن در لیست پلن‌ها یک دکمه است.', '%plan% — %traffic% / %duration% — %price%', self::vars('plan', 'traffic', 'duration', 'price', traffic: 'حجم پلن', duration: 'مدت پلن', price: self::PLAN_PRICE), ['plan']),
            BotText::PlanDetails->value => self::message('جزئیات پلن', 'بعد از انتخاب پلن؛ «انتخاب سرور» یا «سروری در دسترس نیست» زیر آن می‌آید.', '<b>%plan%</b>%description%

🗜️ حجم سرویس: %traffic%
⏳ مدت: %duration%
📱 دستگاه: %devices%
💰 قیمت: <b>%price%</b>', self::vars('plan', 'description', 'traffic', 'duration', 'devices', 'price', traffic: 'حجم پلن', duration: 'مدت پلن', price: self::PLAN_PRICE)),
            BotText::PlanFirstPurchase->value => self::part('قیمت خرید اول', 'زیر جزئیات پلنی که قیمت خرید اول دارد، برای مشتری‌ای که هنوز سرویسی نخریده است؛ قیمت جزئیات همان قیمت خرید اول است.', '🎁 این قیمت ویژه اولین خرید شماست؛ قیمت عادی این پلن %regular% است.', self::vars('regular')),
            BotText::PlanAgentPrice->value => self::part('قیمت نماینده', 'زیر جزئیات پلنی که قیمت نماینده دارد، برای نماینده‌ها و مشتری‌های گروه‌های همکار؛ قیمت جزئیات همان قیمت نماینده است.', '🤝 این قیمت ویژه نمایندگان و همکاران است؛ قیمت عادی این پلن %regular% است.', self::vars('regular')),
            BotText::PlanSale->value => self::part('فروش ویژه پلن', 'زیر جزئیات پلنی که فروش ویژه‌ای روی آن است، وقتی تخفیف آن از تخفیف‌های خودکار دیگر مشتری بیشتر است؛ قیمت جزئیات بعد از این تخفیف است.', '🔥 %discount% — قیمت بدون این تخفیف: %regular%', self::vars('discount', 'regular', discount: 'نام فروش ویژه و درصد آن', regular: 'قیمت پلن برای این مشتری پیش از این تخفیف')),
            BotText::PlanPersonalDiscount->value => self::part('تخفیف اختصاصی مشتری', 'زیر جزئیات پلن برای مشتری‌ای که پشتیبانی برایش تخفیف اختصاصی گذاشته است، وقتی از تخفیف‌های خودکار دیگر بیشتر است؛ قیمت جزئیات بعد از این تخفیف است.', '🎯 %discount% — قیمت بدون این تخفیف: %regular%', self::vars('discount', 'regular', discount: 'تخفیف اختصاصی مشتری و درصد آن', regular: 'قیمت پلن برای این مشتری پیش از این تخفیف')),
            BotText::PlanTierDiscount->value => self::part('تخفیف سطح وفاداری', 'زیر جزئیات پلن برای مشتری‌ای که سطح وفاداری‌اش تخفیف دارد، وقتی از تخفیف‌های خودکار دیگر بیشتر است؛ قیمت جزئیات بعد از این تخفیف است.', '⭐ تخفیف %discount% — قیمت بدون این تخفیف: %regular%', self::vars('discount', 'regular', discount: 'سطح وفاداری مشتری و درصد تخفیف آن', regular: 'قیمت پلن برای این مشتری پیش از این تخفیف')),
            BotText::PlanPickServer->value => self::part('انتخاب سرور', 'زیر جزئیات پلن، بالای دکمه‌های سرورها.', 'سرور را انتخاب کنید:'),
            BotText::ServerButton->value => self::button('دکمه سرور', 'زیر جزئیات پلن و صفحه اکانت تست، یک دکمه برای هر سرور.', '🌍 %server%', self::vars('server'), ['server']),
            BotText::PlanNoServer->value => self::message('سروری در دسترس نیست', 'وقتی هیچ سرور این پلن فعلا قابل فروش نیست.', 'در حال حاضر سروری برای این پلن در دسترس نیست. لطفا بعدا دوباره تلاش کنید.'),
            BotText::PlanGone->value => self::message('پلن دیگر فروخته نمی‌شود', 'وقتی پلنی که مشتری انتخاب کرده دیگر فروخته نمی‌شود.', 'این پلن دیگر در دسترس نیست.'),
        ];
    }

    /** @return array<string, Draft> */
    private static function checkout(): array
    {
        return [
            BotText::Checkout->value => self::message('صفحه پرداخت', 'بعد از انتخاب سرور؛ دکمه روش‌های پرداخت زیر آن می‌آید. سفارش وقتی ثبت می‌شود که مشتری روش پرداخت را انتخاب کند.', '🧾 <b>خرید اشتراک</b>

پلن: %plan%
سرور: %server%
مبلغ: <b>%amount%</b>

روش پرداخت را انتخاب کنید:', self::vars('plan', 'server') + ['amount' => 'مبلغ سفارش']),
            BotText::TopupCheckout->value => self::message('صفحه پرداخت شارژ کیف پول', 'همان صفحه پرداخت، برای شارژ کیف پول.', '🧾 <b>شارژ کیف پول</b>

مبلغ: <b>%amount%</b>

روش پرداخت را انتخاب کنید:', ['amount' => 'مبلغ شارژ']),
            BotText::CheckoutNoGateway->value => self::message('روش پرداختی فعال نیست', 'وقتی هیچ روش پرداختی فعال نیست.', 'هیچ روش پرداختی فعال نیست. لطفا با پشتیبانی تماس بگیرید.'),
            BotText::CheckoutFree->value => self::button('دکمه دریافت رایگان', 'در صفحه پرداخت پلن رایگان (قیمت صفر)، به جای دکمه‌های روش پرداخت؛ سرویس بدون پرداخت تحویل می‌شود.', '🎁 دریافت رایگان'),
            BotText::CheckoutPlanNote->value => self::part('یادداشت پیش‌فاکتور پلن', 'زیر صفحه پرداخت پلنی که یادداشت پیش‌فاکتور دارد (فرم پلن)، چه خرید و چه تمدید، درست بالای دکمه‌های روش پرداخت.', '📌 %plan_note%', self::vars('plan_note')),
            BotText::CheckoutDiscount->value => self::part('خط تخفیف', 'زیر صفحه پرداخت خرید، تمدید، حجم یا زمان اضافه، یک خط برای هر تخفیفی که روی مبلغ آمده است: تخفیف خودکار (فروش ویژه، تخفیف اختصاصی یا سطح وفاداری) و کد تخفیف.', '🏷 %discount%: %amount% تخفیف', self::vars('discount', 'amount', discount: 'تخفیف، با نام و درصدش، یا کد تخفیف', amount: 'مبلغی که این تخفیف کم کرد')),
            BotText::CheckoutBeforeDiscount->value => self::part('مبلغ پیش از تخفیف', 'زیر خط‌های تخفیف صفحه پرداخت؛ مبلغ صفحه پرداخت بعد از همه تخفیف‌هاست.', 'مبلغ پیش از تخفیف: <s>%regular%</s>', self::vars('regular', regular: 'مبلغ پیش از تخفیف‌ها')),
            BotText::DiscountCodeButton->value => self::button('دکمه کد تخفیف', 'زیر صفحه پرداخت خرید، تمدید، حجم یا زمان اضافه؛ مشتری با آن کد تخفیفش را می‌فرستد.', '🏷 کد تخفیف دارم'),
            BotText::DiscountCodeRemove->value => self::button('دکمه حذف کد تخفیف', 'زیر صفحه پرداختی که کد تخفیف روی آن آمده است، به جای «کد تخفیف دارم».', '❌ حذف کد تخفیف'),
            BotText::DiscountCodeAsk->value => self::message('درخواست کد تخفیف', 'بعد از دکمه «کد تخفیف دارم»؛ پیام بعدی مشتری کد تخفیف اوست.', '🏷 کد تخفیف خود را بفرستید.

حروف کوچک و بزرگ فرقی ندارد.'),
            BotText::DiscountCodeRefused->value => self::message('کد تخفیف پذیرفته نشد', 'وقتی کدی که مشتری فرستاده برای این خرید معتبر نیست؛ ربات منتظر کد دیگری می‌ماند و دکمه «بازگشت» به صفحه پرداخت برمی‌گردد.', '⛔️ %reason%

کد دیگری بفرستید، یا به صفحه پرداخت برگردید.', self::vars('reason', reason: 'چرا کد پذیرفته نشد')),
            BotText::DiscountCodeDropped->value => self::part('کد تخفیف برداشته شد', 'زیر صفحه پرداخت، وقتی کد تخفیفی که مشتری برای همین خرید فرستاده بود دیگر معتبر نیست و برداشته شد.', '⚠️ کد تخفیف شما برداشته شد: %reason%', self::vars('reason', reason: 'چرا کد دیگر معتبر نیست')),
            BotText::DiscountCodeLost->value => self::message('کد تخفیف هنگام پرداخت', 'وقتی مشتری روش پرداخت را انتخاب می‌کند و کد تخفیفش همان لحظه دیگر معتبر نیست (مثلا ظرفیتش پر شد)؛ چیزی سفارش داده نمی‌شود و دکمه «بازگشت» صفحه پرداخت را بدون کد نشان می‌دهد.', '⛔️ کد تخفیف شما دیگر معتبر نیست: %reason%

چیزی پرداخت نشد؛ به صفحه پرداخت برگردید.', self::vars('reason', reason: 'چرا کد دیگر معتبر نیست')),
            BotText::OrderNotPending->value => self::message('سفارش دیگر باز نیست', 'وقتی مشتری سفارشی را پرداخت می‌کند که پرداخت یا لغو شده است.', 'این سفارش دیگر قابل پرداخت نیست.'),
            BotText::PayInsufficient->value => self::message('موجودی کافی نیست', 'وقتی مشتری با کیف پول پرداخت می‌کند و موجودی کم است؛ دکمه افزایش موجودی زیر آن می‌آید.', 'موجودی کیف پول کافی نیست.
موجودی: <b>%balance%</b>
مبلغ سفارش: <b>%amount%</b>
کمبود: <b>%missing%</b>', self::vars('balance', 'missing') + ['amount' => 'مبلغ سفارش']),
            BotText::PayFailed->value => self::message('پرداخت ناموفق', 'وقتی پرداخت انجام نمی‌شود.', 'پرداخت انجام نشد: %reason%', self::vars('reason')),
            BotText::CardInstructions->value => self::message('راهنمای کارت به کارت', 'بعد از انتخاب روش کارت به کارت؛ «درخواست ارسال رسید» پشت آن می‌آید.', 'لطفا مبلغ %amount% را به شماره کارت زیر واریز کنید:
<code>%card%</code>
به نام <b>%holder%</b>

%instructions%', self::vars('card', 'holder', 'instructions') + ['amount' => 'مبلغ سفارش'], ['card']),
            BotText::PayInstructionsSuffix->value => self::part('درخواست ارسال رسید', 'انتهای راهنمای کارت به کارت.', '

بعد از واریز، عکس رسید را همین‌جا بفرستید.'),
            BotText::ReceiptWaiting->value => self::message('منتظر رسید', 'وقتی ربات منتظر عکس رسید است و مشتری چیز دیگری می‌نویسد.', 'لطفا عکس رسید پرداخت را بفرستید، یا از منوی پایین گزینه دیگری را انتخاب کنید.'),
            BotText::ReceiptNotImage->value => self::message('رسید باید عکس باشد', 'وقتی مشتری به جای عکس رسید، فایل یا پیام دیگری می‌فرستد.', 'فقط عکس رسید پذیرفته می‌شود. لطفا اسکرین‌شات یا عکس رسید واریز را بفرستید.'),
            BotText::ReceiptReceived->value => self::message('دریافت رسید', 'بعد از دریافت رسید، وقتی رسید فقط دستی تایید می‌شود.', 'رسید دریافت شد ✅
بعد از تایید توسط پشتیبانی، %outcome%.', self::vars('outcome')),
            BotText::ReceiptReceivedTimed->value => self::message('دریافت رسید با مهلت', 'بعد از دریافت رسید، وقتی روش پرداخت مهلت تایید خودکار دارد.', 'رسید دریافت شد ✅
بعد از بررسی، %outcome%؛ حداکثر تا %wait% دیگر.', self::vars('outcome', 'wait')),
            BotText::ReceiptOutcomeSubscription->value => self::part('نتیجه رسید: اشتراک', 'مقدار %outcome% در پیام دریافت رسید و پیام «پرداخت انجام شد»، وقتی سفارش خرید اشتراک است.', 'اشتراک شما فعال و همین‌جا ارسال می‌شود'),
            BotText::ReceiptOutcomeRenewal->value => self::part('نتیجه رسید: تمدید', 'مقدار %outcome% در پیام دریافت رسید و پیام «پرداخت انجام شد»، وقتی سفارش تمدید سرویس است.', 'سرویس شما تمدید می‌شود و همین‌جا خبر می‌دهیم'),
            BotText::ReceiptOutcomeTopup->value => self::part('نتیجه رسید: شارژ کیف پول', 'مقدار %outcome% در پیام دریافت رسید و پیام «پرداخت انجام شد»، وقتی سفارش شارژ کیف پول است.', 'کیف پول شما شارژ می‌شود و همین‌جا خبر می‌دهیم'),
            BotText::ReceiptOutcomeExtraTraffic->value => self::part('نتیجه رسید: حجم اضافه', 'مقدار %outcome% در پیام دریافت رسید و پیام «پرداخت انجام شد»، وقتی سفارش خرید حجم اضافه برای سرویس است.', 'حجم به سرویس شما اضافه می‌شود و همین‌جا خبر می‌دهیم'),
            BotText::ReceiptOutcomeExtraTime->value => self::part('نتیجه رسید: زمان اضافه', 'مقدار %outcome% در پیام دریافت رسید و پیام «پرداخت انجام شد»، وقتی سفارش خرید زمان اضافه برای سرویس است.', 'زمان به سرویس شما اضافه می‌شود و همین‌جا خبر می‌دهیم'),
            BotText::PayProcessing->value => self::message('پرداخت انجام شد', 'وقتی پرداخت از کیف پول انجام شد ولی تحویل سفارش هنوز در جریان است؛ نتیجه‌اش بعدا همین‌جا فرستاده می‌شود.', '✅ پرداخت سفارش #%order% انجام شد؛ %outcome%.', self::vars('order', outcome: 'نتیجه پرداخت (یکی از متن‌های نتیجه رسید)')),
        ];
    }

    /** @return array<string, Draft> */
    private static function delivery(): array
    {
        return [
            BotText::PaySuccess->value => self::caption('تحویل سرویس', 'بعد از پرداخت موفق؛ همراه عکس کد QR لینک، وقتی ساخت آن روشن است.', '✅ سرویس با موفقیت ایجاد شد

👤 نام کاربری سرویس: <code>%client%</code>
🌿 نام سرویس: %plan%
‏🇺🇳 لوکیشن: %server%
⏳ مدت زمان: %duration%
🗜️ حجم سرویس: %traffic%

🔗 لینک اتصال:

<code>%subscription%</code>', self::vars(...self::SERVICE), ['subscription']),
            BotText::PayProvisionFailed->value => self::message('ساخت سرویس ناموفق', 'وقتی پرداخت انجام شد ولی ساخت سرویس روی سرور ناموفق بود.', 'پرداخت ثبت شد ولی ساخت اشتراک روی سرور ناموفق بود. پشتیبانی به‌زودی آن را فعال می‌کند؛ نیازی به پرداخت دوباره نیست.'),
            BotText::ReceiptRejected->value => self::message('رد رسید', 'وقتی پشتیبانی رسید را رد می‌کند؛ در پاسخ به همان رسید.', '❌ رسید شما برای سفارش #%order% تایید نشد.%note%

می‌توانید از منو دوباره خرید کنید یا با پشتیبانی در تماس باشید.', self::vars('order', 'note')),
            BotText::OrderCancelledByAdmin->value => self::message('لغو سفارش توسط پشتیبانی', 'وقتی پشتیبانی سفارشی را لغو می‌کند؛ در پاسخ به رسید، اگر فرستاده شده باشد.', '❌ سفارش #%order% توسط پشتیبانی لغو شد.%note%

اگر مبلغی واریز کرده‌اید، با پشتیبانی در تماس باشید.', self::vars('order', 'note')),
            BotText::PaymentRefunded->value => self::message('برگشت مبلغ به کیف پول', 'وقتی پشتیبانی مبلغ پرداخت را به کیف پول مشتری برمی‌گرداند.', '↩️ مبلغ %amount% بابت سفارش #%order% به کیف پول شما برگشت داده شد.%note%
موجودی: <b>%balance%</b>', self::vars('order', 'note', 'balance') + ['amount' => 'مبلغ برگشتی']),
            BotText::PaymentRefundedService->value => self::part('حذف سرویس خرید برگشتی', 'زیر پیام برگشت مبلغ، وقتی مبلغ یک خرید برگشت داده شد و سرویسی که با آن ساخته شده بود حذف شد.', '
🗑️ سرویس <code>%client%</code> که با این خرید ساخته شده بود حذف شد.', self::vars('client')),
            BotText::PaymentRefundedTakenBack->value => self::part('پس گرفتن تمدید یا حجم و زمان اضافه', 'زیر پیام برگشت مبلغ، وقتی مبلغ یک تمدید یا حجم و زمان اضافه برگشت داده شد و آنچه به سرویس داده بود پس گرفته شد.', '
↩️ زمان و حجمی که این سفارش به سرویس <code>%client%</code> داده بود پس گرفته شد.', self::vars('client')),
            BotText::TopupRefunded->value => self::message('برگشت شارژ کیف پول', 'وقتی پشتیبانی پرداخت یک شارژ کیف پول را برمی‌گرداند و مبلغ شارژ از کیف پول مشتری کم می‌شود.', '↩️ پرداخت %amount% برای شارژ کیف پول (سفارش #%order%) برگشت داده شد و این مبلغ از کیف پول شما کم شد.%note%
موجودی: <b>%balance%</b>', self::vars('order', 'note', 'balance') + ['amount' => 'مبلغ شارژ']),
            BotText::TopupRefundedUndelivered->value => self::message('برگشت شارژی که نرسیده بود', 'وقتی پشتیبانی پرداخت شارژ کیف پولی را برمی‌گرداند که هنوز به کیف پول مشتری نرسیده بود؛ چیزی از موجودی کم نمی‌شود.', '↩️ پرداخت %amount% برای شارژ کیف پول (سفارش #%order%) برگشت داده شد. این شارژ به کیف پول شما نرسیده بود و موجودی شما تغییری نکرد.%note%', self::vars('order', 'note') + ['amount' => 'مبلغ شارژ']),
            BotText::AdminNote->value => self::part('توضیح پشتیبانی', 'مقدار %note% در پیام‌های رد رسید، لغو، برگشت مبلغ و کارهای پشتیبانی روی سرویس؛ اگر پشتیبانی توضیحی ننوشته باشد، نمی‌آید.', '
توضیح پشتیبانی: %comment%', self::vars('comment'), ['comment']),
            BotText::PaymentReminder->value => self::message('یادآوری پرداخت', 'وقتی پشتیبانی یادآوری پرداخت می‌فرستد؛ دکمه‌های «ارسال رسید» و «پرداخت دوباره» زیر آن می‌آید.', '⏳ پرداخت سفارش #%order% (%amount%) هنوز کامل نشده است.
اگر واریز کرده‌اید، عکس رسید را بفرستید؛ وگرنه می‌توانید دوباره پرداخت کنید.', self::vars('order') + ['amount' => 'مبلغ پرداخت']),
            BotText::PaymentReminderRejected->value => self::message('یادآوری پرداخت بعد از رد رسید', 'همان یادآوری، برای پرداختی که رسیدش رد شده است؛ فقط دکمه «پرداخت دوباره» زیر آن می‌آید.', '⏳ پرداخت سفارش #%order% (%amount%) هنوز کامل نشده است.
رسید قبلی تایید نشد؛ می‌توانید دوباره پرداخت کنید.', self::vars('order') + ['amount' => 'مبلغ پرداخت']),
            BotText::PayAgain->value => self::button('دکمه پرداخت دوباره', 'زیر یادآوری پرداخت.', '🔄 پرداخت دوباره'),
            BotText::SendReceipt->value => self::button('دکمه ارسال رسید', 'زیر یادآوری پرداخت.', '📎 ارسال رسید'),
        ];
    }

    /** @return array<string, Draft> */
    private static function services(): array
    {
        return [
            BotText::SubscriptionsTitle->value => self::message('لیست سرویس‌ها', 'بالای دکمه‌های سرویس‌ها در «سرویس‌های من».', '🛍️ سرویس‌های فعال شما
برای دیدن جزئیات و مدیریت، روی نام سرویس بزنید.'),
            BotText::SubscriptionsPage->value => self::part('شماره صفحه', 'زیر لیست سرویس‌ها و لیست تیکت‌ها، وقتی بیش از یک صفحه است.', '
صفحه %page% از %pages%', self::vars('page', 'pages')),
            BotText::SubscriptionsEmpty->value => self::message('سرویسی نیست', 'وقتی مشتری سرویس فعالی ندارد.', 'شما هنوز سرویس فعالی ندارید.'),
            BotText::ServiceButton->value => self::button('دکمه سرویس', 'هر سرویس در لیست سرویس‌ها — و در انتخاب سرویس تیکت جدید — یک دکمه است.', '✨ %client% ✨', self::vars('client'), ['client']),
            BotText::PageNext->value => self::button('دکمه صفحه بعد', 'زیر لیست سرویس‌ها و لیست تیکت‌ها.', 'بعدی'),
            BotText::PagePrev->value => self::button('دکمه صفحه قبل', 'زیر لیست سرویس‌ها و لیست تیکت‌ها.', 'قبلی'),
        ];
    }

    /** @return array<string, Draft> */
    private static function service(): array
    {
        return [
            BotText::ServiceDetails->value => self::message('صفحه سرویس', 'وقتی مشتری یک سرویس را باز می‌کند؛ اطلاعات همان لحظه از پنل خوانده می‌شود.', '📊 وضعیت سرویس: %status%
👤 نام کاربری سرویس: <code>%client%</code>
🌿 نام سرویس: %plan%
‏🇺🇳 لوکیشن: %server%

🗜️ حجم سرویس: %traffic%
📤 حجم مصرفی: %used%
📥 حجم باقی‌مانده: %remaining%

📅 تاریخ اتمام: %expires%

📶 آخرین اتصال: %last_online%
📡 وضعیت اتصال: %connection%', self::vars('status', 'client', 'plan', 'server', 'traffic', 'used', 'remaining', 'expires', 'last_online', 'connection')),
            BotText::ServiceRotateHint->value => self::part('راهنمای تغییر لینک', 'زیر صفحه سرویس، وقتی سرور امکان تغییر لینک دارد.', '💡 برای قطع دسترسی دیگران به این سرویس، روی «تغییر لینک» بزنید.'),
            BotText::ServiceStale->value => self::part('پنل در دسترس نبود', 'زیر صفحه سرویس، وقتی پنل جواب نداد و اطلاعات مربوط به آخرین به‌روزرسانی است.', '⚠️ سرور در دسترس نبود؛ اطلاعات مربوط به آخرین به‌روزرسانی است.'),
            BotText::ServiceRemoval->value => self::part('حذف خودکار سرویس', 'زیر صفحه سرویسی که زمان یا حجمش تمام شده است و صفحه پرداخت تمدید آن، وقتی حذف خودکار سرویس‌های تمام‌شده روشن است: سرویس در این زمان از سرور و فروشگاه حذف می‌شود، مگر اینکه پیش از آن تمدید شود.', '🗑️ اگر این سرویس تا %date% تمدید نشود، حذف می‌شود.', self::vars('date', date: 'زمان حذف خودکار سرویس')),
            BotText::ServiceTestRemoval->value => self::part('حذف خودکار اکانت تست', 'زیر صفحه اکانت تست، وقتی حذف خودکار اکانت‌های تست روشن است: چند ساعت بعد از تمام شدن اکانت، یا برای اکانتی که هنوز وصل نشده چند روز بعد از ساخت آن (تنظیمات اکانت تست).', '🗑️ این اکانت تست %date% حذف می‌شود.', self::vars('date', date: 'زمان حذف خودکار اکانت تست')),
            BotText::ServiceStatusActive->value => self::part('وضعیت: فعال', 'مقدار %status% در صفحه سرویس.', '✅ فعال'),
            BotText::ServiceStatusDisabled->value => self::part('وضعیت: غیرفعال', 'مقدار %status% در صفحه سرویس.', '⛔️ غیرفعال'),
            BotText::ServiceStatusExpired->value => self::part('وضعیت: منقضی', 'مقدار %status% در صفحه سرویس.', '⌛ منقضی شده'),
            BotText::ServiceStatusDepleted->value => self::part('وضعیت: حجم تمام شده', 'مقدار %status% در صفحه سرویس.', '⚠️ حجم تمام شده'),
            BotText::ServiceStatusDeleted->value => self::part('وضعیت: حذف شده', 'مقدار %status% در صفحه سرویس.', '❌ حذف شده'),
            BotText::ServiceUnused->value => self::part('مصرف نشده', 'مقدار %used% وقتی سرویس هنوز مصرفی ندارد.', 'مصرف نشده'),
            BotText::ServiceNeverConnected->value => self::part('هنوز متصل نشده', 'مقدار %last_online% وقتی سرویس هنوز وصل نشده است.', 'هنوز متصل نشده'),
            BotText::ServiceOnline->value => self::part('آنلاین', 'مقدار %connection% وقتی سرویس همین حالا وصل است.', '🟢 آنلاین'),
            BotText::ServiceOffline->value => self::part('آفلاین', 'مقدار %connection% وقتی سرویس وصل نیست.', '⚪ آفلاین'),
            BotText::ServiceUnknown->value => self::part('نامشخص', 'وقتی پنل چیزی را نمی‌داند: آخرین اتصال، وضعیت اتصال، یا دلیل پرداخت ناموفق.', 'نامشخص'),
            BotText::ServiceNotFound->value => self::message('سرویس پیدا نشد', 'وقتی سرویسی که مشتری باز می‌کند دیگر وجود ندارد.', 'این سرویس پیدا نشد.'),
            BotText::ServiceInactive->value => self::popup('سرویس فعال نیست', 'وقتی کاری روی سرویسی خواسته می‌شود که فعال نیست.', 'این سرویس فعال نیست.'),
            BotText::ServiceRefreshed->value => self::popup('اطلاعات به‌روز شد', 'اعلان کوتاه بعد از «به‌روزرسانی اطلاعات».', '✅ اطلاعات به‌روز شد'),
            BotText::ServiceRefreshFailed->value => self::popup('اطلاعات به‌روز نشد', 'اعلان وقتی «به‌روزرسانی اطلاعات» به پنل نمی‌رسد؛ صفحه همان‌طور می‌ماند.', '⚠️ ارتباط با سرور برقرار نشد و اطلاعات به‌روز نشد؛ کمی بعد دوباره تلاش کنید.'),
            BotText::ServiceRefresh->value => self::button('دکمه به‌روزرسانی اطلاعات', 'در صفحه سرویس.', '🔄 به‌روزرسانی اطلاعات'),
            BotText::ServiceLink->value => self::button('دکمه لینک اشتراک', 'در صفحه سرویس، و زیر پیام «لینک جدید ارسال نشد».', '🔗 لینک اشتراک'),
            BotText::ServiceRenew->value => self::button('دکمه تمدید سرویس', 'در صفحه سرویس؛ صفحه پرداخت تمدید همان سرویس را باز می‌کند.', '♻️ تمدید سرویس'),
            BotText::ServiceReport->value => self::button('دکمه گزارش اختلال', 'در صفحه سرویس؛ تیکت پشتیبانی درباره همان سرویس باز می‌کند.', '⚠️ ارسال گزارش اختلال'),
            BotText::ServiceRotate->value => self::button('دکمه تغییر لینک', 'در صفحه سرویس، وقتی سرور امکان تغییر لینک دارد.', '⚙️ تغییر لینک'),
            BotText::ServiceBack->value => self::button('دکمه بازگشت به لیست سرویس‌ها', 'پایین صفحه سرویس.', '⬅️ بازگشت به لیست سرویس‌ها'),
            BotText::LinkRequested->value => self::caption('لینک اشتراک', 'بعد از دکمه «لینک اشتراک»؛ همراه عکس کد QR، وقتی ساخت آن روشن است.', '🔗 لینک اشتراک سرویس

👤 نام کاربری سرویس: <code>%client%</code>
🌿 نام سرویس: %plan%
‏🇺🇳 لوکیشن: %server%

<code>%subscription%</code>

💡 برای کپی، روی لینک بزنید.', self::vars(...self::SERVICE), ['subscription']),
        ];
    }

    /** @return array<string, Draft> */
    private static function rotation(): array
    {
        return [
            BotText::ServiceRotateConfirm->value => self::message('پرسش تغییر لینک', 'پیش از تغییر لینک، برای تایید مشتری؛ دکمه‌های تایید و انصراف زیر آن می‌آید.', '⚠️ با تغییر لینک، لینک فعلی از کار می‌افتد و هر دستگاهی که با آن متصل است قطع می‌شود؛ باید لینک جدید را در دستگاه‌های خود جایگزین کنید.

لینک سرویس «%client%» تغییر کند؟', self::vars('client')),
            BotText::ServiceRotateYes->value => self::button('دکمه تایید تغییر لینک', 'زیر پرسش تغییر لینک.', '✅ بله، تغییر بده'),
            BotText::ServiceRotateExpired->value => self::popup('تایید تغییر لینک گذشته', 'وقتی تایید تغییر لینک دوباره زده می‌شود، یا بعد از اینکه مشتری به بخش دیگری رفته است؛ لینک تغییر نمی‌کند.', 'این تایید دیگر معتبر نیست؛ برای تغییر لینک، از صفحه سرویس دوباره «تغییر لینک» را بزنید.'),
            BotText::ServiceRotated->value => self::popup('لینک تغییر کرد', 'اعلان کوتاه بعد از تغییر لینک.', '✅ لینک سرویس تغییر کرد'),
            BotText::ServiceRotateFailed->value => self::message('تغییر لینک ناموفق', 'وقتی پنل لینک را تغییر نمی‌دهد.', '❌ تغییر لینک انجام نشد؛ کمی بعد دوباره تلاش کنید یا با پشتیبانی در تماس باشید.'),
            BotText::ServiceRotatedUnsent->value => self::message('لینک جدید ارسال نشد', 'وقتی لینک تغییر کرد ولی پیام لینک جدید ارسال نشد؛ دکمه «لینک اشتراک» زیر آن می‌آید.', '⚠️ لینک سرویس تغییر کرد ولی ارسال آن ناموفق بود؛ لینک جدید را با دکمه زیر بگیرید.'),
            BotText::LinkRotated->value => self::caption('لینک جدید', 'بعد از تغییر لینک؛ همراه عکس کد QR، وقتی ساخت آن روشن است.', '🔄 لینک سرویس شما تغییر کرد

لینک قبلی دیگر کار نمی‌کند؛ لینک جدید را در همه دستگاه‌های خود جایگزین کنید.

👤 نام کاربری سرویس: <code>%client%</code>
🌿 نام سرویس: %plan%
‏🇺🇳 لوکیشن: %server%
📅 تاریخ اتمام: %expires%
🗜️ حجم سرویس: %traffic%

🔗 لینک اتصال:

<code>%subscription%</code>', self::vars(...self::SERVICE), ['subscription']),
        ];
    }

    /** @return array<string, Draft> */
    private static function supportActions(): array
    {
        return [
            BotText::ServiceDisabledBySupport->value => self::message('غیرفعال شدن سرویس', 'وقتی پشتیبانی سرویس را غیرفعال می‌کند.', '⛔️ سرویس <code>%client%</code> توسط پشتیبانی غیرفعال شد.%note%

برای پیگیری با پشتیبانی در تماس باشید.', self::vars('client', 'note')),
            BotText::ServiceEnabledBySupport->value => self::message('فعال شدن دوباره سرویس', 'وقتی پشتیبانی سرویس را دوباره فعال می‌کند.', '✅ سرویس <code>%client%</code> دوباره فعال شد.', self::vars('client')),
            BotText::ServiceMoved->value => self::caption('انتقال سرویس', 'وقتی پشتیبانی سرویس را به سرور دیگری منتقل می‌کند؛ همراه عکس کد QR لینک جدید، وقتی ساخت آن روشن است.', '🔄 سرویس شما به سرور دیگری منتقل شد

لینک جدید را در همه دستگاه‌های خود جایگزین کنید؛ زمان و حجم باقی‌مانده همان است.

👤 نام کاربری سرویس: <code>%client%</code>
🌿 نام سرویس: %plan%
‏🇺🇳 لوکیشن: %server%
📅 تاریخ اتمام: %expires%
📥 حجم باقی‌مانده: %traffic%

🔗 لینک اتصال:

<code>%subscription%</code>', self::vars(...self::SERVICE, server: 'نام سرور جدید', traffic: 'حجم سرویس روی سرور جدید (همان باقی‌مانده)'), ['subscription']),
            BotText::ServiceDeletedBySupport->value => self::message('حذف سرویس', 'وقتی پشتیبانی سرویسی را که هنوز فعال بود حذف می‌کند.', '🗑️ سرویس <code>%client%</code> توسط پشتیبانی حذف شد.%note%

برای پیگیری با پشتیبانی در تماس باشید.', self::vars('client', 'note')),
            BotText::ServiceGranted->value => self::message('افزودن زمان و حجم', 'وقتی پشتیبانی به سرویس زمان یا حجم اضافه می‌کند: از صفحه سرور، از «هدیه همگانی» یا برای همین یک سرویس از صفحه اشتراک‌ها.', '🎁 %gift% به سرویس <code>%client%</code> اضافه شد.%note%

📅 تاریخ اتمام: %expires%
📥 حجم باقی‌مانده: %remaining%', self::vars(...self::SERVICE) + self::vars('gift', 'note')),
        ];
    }

    /** @return array<string, Draft> */
    private static function renewal(): array
    {
        return [
            BotText::RenewChoose->value => self::message('انتخاب سرویس برای تمدید', 'بعد از دکمه «تمدید سرویس» منوی اصلی؛ دکمه سرویس‌هایی که می‌شود تمدید کرد، فعال یا منقضی، زیر آن می‌آید.', '♻️ تمدید سرویس
برای تمدید، روی نام سرویس بزنید.'),
            BotText::RenewNothing->value => self::message('سرویسی برای تمدید نیست', 'وقتی مشتری دکمه «تمدید سرویس» منوی اصلی را می‌زند و سرویسی ندارد که بشود تمدید کرد.', 'شما سرویسی برای تمدید ندارید.'),
            BotText::RenewCheckout->value => self::message('صفحه پرداخت تمدید', 'بعد از دکمه «تمدید سرویس» صفحه سرویس، یا انتخاب سرویس در «تمدید سرویس» منوی اصلی؛ دکمه روش‌های پرداخت زیر آن می‌آید. سفارش وقتی ثبت می‌شود که مشتری روش پرداخت را انتخاب کند.', '🧾 <b>تمدید سرویس</b>

👤 نام کاربری سرویس: <code>%client%</code>
🌿 نام سرویس: %plan%
⏳ مدت: %duration%
🗜️ حجم سرویس: %traffic%
💰 مبلغ: <b>%amount%</b>

بعد از تمدید:
📅 تاریخ اتمام: %expires%
📥 حجم باقی‌مانده: %remaining%%leftover%

روش پرداخت را انتخاب کنید:', self::vars(
                'client',
                'plan',
                duration: 'مدت تمدید (مدت پلن)',
                traffic: 'حجم تمدید (حجم پلن)',
                amount: 'مبلغ تمدید',
                expires: 'تاریخ پایان سرویس بعد از تمدید',
                remaining: 'حجم باقی‌مانده بعد از تمدید',
                leftover: 'متن «حجم دوره فعلی»، وقتی حجم باقی‌مانده به دوره جدید نمی‌رود؛ یا خالی',
            )),
            BotText::RenewUnavailable->value => self::popup('تمدید ممکن نیست', 'اعلان وقتی مشتری سرویسی را تمدید می‌کند که الان نمی‌شود: پشتیبانی آن را غیرفعال کرده، پلنش دیگر وجود ندارد یا تمدید فعلا در دسترس نیست.', 'تمدید این سرویس در حال حاضر ممکن نیست؛ لطفا با پشتیبانی در تماس باشید.'),
            BotText::RenewUnderWay->value => self::popup('تمدید در جریان است', 'اعلان وقتی برای سرویس یک تمدید در جریان است: رسیدش پیش پشتیبانی است، یا پرداخت شده و در حال انجام است.', 'برای این سرویس یک تمدید در حال بررسی یا انجام است؛ نتیجه همین‌جا اعلام می‌شود و نیازی به پرداخت دوباره نیست.'),
            BotText::ServiceAutoRenewOn->value => self::button('دکمه تمدید خودکار: روشن', 'در صفحه سرویس، وقتی تمدید خودکار روشن است.', '🔁 تمدید خودکار: روشن'),
            BotText::ServiceAutoRenewOff->value => self::button('دکمه تمدید خودکار: خاموش', 'در صفحه سرویس، وقتی تمدید خودکار خاموش است.', '🔁 تمدید خودکار: خاموش'),
            BotText::AutoRenewEnabled->value => self::popup('روشن شدن تمدید خودکار', 'اعلان وقتی مشتری تمدید خودکار را روشن می‌کند.', '✅ تمدید خودکار روشن شد: %days% روز پیش از پایان سرویس، %amount% از کیف پول کسر و سرویس تمدید می‌شود.', ['days' => 'چند روز پیش از پایان سرویس', 'amount' => 'هزینه تمدید']),
            BotText::AutoRenewDisabled->value => self::popup('خاموش شدن تمدید خودکار', 'اعلان کوتاه وقتی مشتری تمدید خودکار را خاموش می‌کند.', 'تمدید خودکار خاموش شد.'),
            BotText::AutoRenewUnavailable->value => self::popup('تمدید خودکار در دسترس نیست', 'اعلان وقتی تمدید خودکار برای سرویس ممکن نیست.', 'تمدید خودکار برای این سرویس در دسترس نیست.'),
            BotText::AutoRenewed->value => self::message('تمدید خودکار انجام شد', 'بعد از تمدید خودکار از کیف پول.', '♻️ سرویس <code>%client%</code> خودکار تمدید شد.

💰 %amount% از کیف پول کسر شد؛ موجودی فعلی: %balance%
🌿 نام سرویس: %plan%
📅 تاریخ اتمام: %expires%
📥 حجم باقی‌مانده: %remaining%%leftover%', self::vars(...self::SERVICE) + self::vars('balance', 'leftover') + ['amount' => 'مبلغ کسرشده از کیف پول']),
            BotText::AutoRenewShort->value => self::message('موجودی کافی برای تمدید خودکار نیست', 'یک بار در هر دوره تمدید، وقتی موجودی کیف پول کم است؛ دکمه افزایش موجودی زیر آن می‌آید.', '⚠️ تمدید خودکار سرویس <code>%client%</code> انجام نشد: موجودی کیف پول کافی نیست.

💰 هزینه تمدید: %amount%
💳 موجودی کیف پول: %balance%
📅 پایان سرویس: %expires%

کیف پول را شارژ کنید تا سرویس پیش از پایان، خودکار تمدید شود.', self::vars('client', 'balance', 'expires') + ['amount' => 'هزینه تمدید']),
            BotText::Renewed->value => self::message('تمدید انجام شد', 'بعد از تمدید سرویس از ربات — همان لحظه وقتی از کیف پول پرداخت شد، یا بعد از تایید رسید —، و وقتی تمدیدی که پرداخت شده بود بعدا تحویل می‌شود.', '♻️ سرویس <code>%client%</code> تمدید شد.

🌿 نام سرویس: %plan%
📅 تاریخ اتمام: %expires%
📥 حجم باقی‌مانده: %remaining%%leftover%', self::vars(...self::SERVICE) + self::vars('leftover')),
            BotText::RenewalLeftoverUntil->value => self::part('حجم دوره فعلی', 'مقدار %leftover% در پیام‌های تمدید و خطی زیر صفحه سرویس، وقتی حجم باقی‌مانده به دوره جدید نمی‌رود.', '

⏳ %expiring% از حجم باقی‌مانده مال دوره فعلی است و فقط تا %date% قابل استفاده است؛ از آن روز دوره جدید با %next% شروع می‌شود.', self::vars('expiring', 'date', 'next')),
            BotText::RenewalLeftoverCarried->value => self::part('انتقال حجم باقی‌مانده', 'مقدار %leftover% در پیام‌های تمدید، وقتی حجم باقی‌مانده به دوره جدید منتقل شد.', '

📥 حجم باقی‌مانده دوره قبل هم به دوره جدید منتقل شد.'),
            BotText::RenewFailed->value => self::message('تمدید ناموفق', 'وقتی تمدید پرداخت شد ولی روی سرور انجام نشد.', 'پرداخت تمدید سرویس <code>%client%</code> ثبت شد ولی تمدید روی سرور انجام نشد. پشتیبانی به‌زودی آن را انجام می‌دهد؛ نیازی به پرداخت دوباره نیست.', self::vars('client')),
        ];
    }

    /** @return array<string, Draft> */
    private static function extras(): array
    {
        return [
            BotText::ServiceExtraTraffic->value => self::button('دکمه حجم اضافه', 'در صفحه سرویس، و در صفحه پرداخت تمدیدی که از فهرست «♻️ تمدید سرویس» باز شده، وقتی فروشگاه برای این سرویس حجم اضافه می‌فروشد.', '🔋 حجم اضافه'),
            BotText::ServiceExtraTime->value => self::button('دکمه زمان اضافه', 'در صفحه سرویس، و در صفحه پرداخت تمدیدی که از فهرست «♻️ تمدید سرویس» باز شده، وقتی فروشگاه برای این سرویس زمان اضافه می‌فروشد.', '⏳ زمان اضافه'),
            BotText::ExtraTrafficMenu->value => self::message('صفحه حجم اضافه', 'بعد از دکمه «حجم اضافه» صفحه سرویس؛ دکمه حجم‌های پیشنهادی، هر کدام با قیمتش، زیر آن می‌آید. سفارش وقتی ثبت می‌شود که مشتری روش پرداخت را انتخاب کند.', '🔋 <b>خرید حجم اضافه</b>

👤 نام کاربری سرویس: <code>%client%</code>
📥 حجم باقی‌مانده: %remaining%
📅 تاریخ اتمام: %expires%%typed%', self::vars('client', 'remaining', 'expires', typed: 'متن «حجم دلخواه»، وقتی مشتری می‌تواند حجم دلخواه بنویسد؛ یا خالی')),
            BotText::ExtraTrafficTyped->value => self::part('حجم دلخواه', 'مقدار %typed% در صفحه حجم اضافه، وقتی قیمت هر گیگابایت تعیین شده و مشتری می‌تواند حجم دلخواه بنویسد.', '

✏️ برای حجم دلخواه، عددی بین %least% تا %most% گیگابایت بنویسید؛ هر گیگابایت %unit_price%.', self::vars(least: 'کمترین حجم دلخواه', most: 'بیشترین حجم دلخواه', unit_price: 'قیمت هر گیگابایت')),
            BotText::ExtraTimeMenu->value => self::message('صفحه زمان اضافه', 'بعد از دکمه «زمان اضافه» صفحه سرویس؛ دکمه زمان‌های پیشنهادی، هر کدام با قیمتش، زیر آن می‌آید. سفارش وقتی ثبت می‌شود که مشتری روش پرداخت را انتخاب کند.', '⏳ <b>خرید زمان اضافه</b>

👤 نام کاربری سرویس: <code>%client%</code>
📅 تاریخ اتمام: %expires%
📥 حجم باقی‌مانده: %remaining%%restart%%typed%', self::vars('client', 'expires', 'remaining', 'restart', typed: 'متن «زمان دلخواه»، وقتی مشتری می‌تواند تعداد روز دلخواه بنویسد؛ یا خالی')),
            BotText::ExtraTimeRestart->value => self::part('زمان تمام‌شده', 'مقدار %restart% در صفحه زمان اضافه، وقتی زمان سرویس تمام شده است.', '

⏳ زمان این سرویس تمام شده است؛ روزهایی که می‌خرید از اولین اتصال بعدی شما شمرده می‌شود.'),
            BotText::ExtraTimeTyped->value => self::part('زمان دلخواه', 'مقدار %typed% در صفحه زمان اضافه، وقتی قیمت هر روز تعیین شده و مشتری می‌تواند تعداد روز دلخواه بنویسد.', '

✏️ برای زمان دلخواه، تعداد روز را بین %least% تا %most% بنویسید؛ هر روز %unit_price%.', self::vars(least: 'کمترین تعداد روز دلخواه', most: 'بیشترین تعداد روز دلخواه', unit_price: 'قیمت هر روز')),
            BotText::ExtraOption->value => self::button('دکمه مقدار پیشنهادی', 'دکمه‌های صفحه حجم اضافه و صفحه زمان اضافه: هر حجم یا زمان پیشنهادی با قیمتش.', '%extra% · %amount%', self::vars('extra', amount: 'قیمت آن')),
            BotText::ExtraInvalid->value => self::message('مقدار دلخواه نامعتبر', 'وقتی مشتری در صفحه حجم یا زمان اضافه عددی می‌نویسد که فروخته نمی‌شود.', 'این مقدار فروخته نمی‌شود؛ عددی بین %least% تا %most% بنویسید.', self::vars('least', 'most')),
            BotText::ExtraTrafficCheckout->value => self::message('صفحه پرداخت حجم اضافه', 'بعد از انتخاب یا نوشتن حجم در صفحه حجم اضافه؛ دکمه روش‌های پرداخت زیر آن می‌آید.', '🧾 <b>خرید حجم اضافه</b>

👤 نام کاربری سرویس: <code>%client%</code>
🔋 حجم اضافه: %extra_traffic%
💰 مبلغ: <b>%amount%</b>

بعد از خرید:
📥 حجم باقی‌مانده: %remaining%
📅 تاریخ اتمام: %expires%

روش پرداخت را انتخاب کنید:', self::vars('client', 'extra_traffic', amount: 'مبلغ خرید', remaining: 'حجم باقی‌مانده بعد از خرید', expires: 'تاریخ پایان سرویس')),
            BotText::ExtraTimeCheckout->value => self::message('صفحه پرداخت زمان اضافه', 'بعد از انتخاب یا نوشتن تعداد روز در صفحه زمان اضافه؛ دکمه روش‌های پرداخت زیر آن می‌آید.', '🧾 <b>خرید زمان اضافه</b>

👤 نام کاربری سرویس: <code>%client%</code>
⏳ زمان اضافه: %extra_time%
💰 مبلغ: <b>%amount%</b>

بعد از خرید:
📅 تاریخ اتمام: %expires%
📥 حجم باقی‌مانده: %remaining%

روش پرداخت را انتخاب کنید:', self::vars('client', 'extra_time', amount: 'مبلغ خرید', expires: 'تاریخ پایان سرویس بعد از خرید', remaining: 'حجم باقی‌مانده')),
            BotText::ExtraUnavailable->value => self::popup('خرید حجم یا زمان ممکن نیست', 'اعلان وقتی مشتری برای سرویسی حجم یا زمان اضافه می‌خرد که الان نمی‌شود: پشتیبانی آن را غیرفعال کرده، تمام شده، یا فروشگاه دیگر برایش نمی‌فروشد — و همین به‌صورت پیام، وقتی مقداری را نوشته است.', 'خرید حجم یا زمان اضافه برای این سرویس در حال حاضر ممکن نیست.'),
            BotText::ExtraTrafficAdded->value => self::message('حجم اضافه شد', 'بعد از خرید حجم اضافه — همان لحظه وقتی از کیف پول پرداخت شد، یا بعد از تایید رسید —، و وقتی خریدی که پرداخت شده بود بعدا تحویل می‌شود.', '✅ %extra_traffic% به سرویس <code>%client%</code> اضافه شد.

📥 حجم باقی‌مانده: %remaining%
📅 تاریخ اتمام: %expires%', self::vars(...self::SERVICE) + self::vars('extra_traffic')),
            BotText::ExtraTimeAdded->value => self::message('زمان اضافه شد', 'بعد از خرید زمان اضافه — همان لحظه وقتی از کیف پول پرداخت شد، یا بعد از تایید رسید —، و وقتی خریدی که پرداخت شده بود بعدا تحویل می‌شود.', '✅ %extra_time% به زمان سرویس <code>%client%</code> اضافه شد.

📅 تاریخ اتمام: %expires%
📥 حجم باقی‌مانده: %remaining%', self::vars(...self::SERVICE) + self::vars('extra_time')),
            BotText::ExtraFailed->value => self::message('خرید حجم یا زمان ناموفق', 'وقتی خرید حجم یا زمان اضافه پرداخت شد ولی روی سرور انجام نشد.', 'پرداخت شما ثبت شد ولی %extra% به سرویس <code>%client%</code> اضافه نشد. پشتیبانی به‌زودی آن را انجام می‌دهد؛ نیازی به پرداخت دوباره نیست.', self::vars('client', 'extra')),
        ];
    }

    /** @return array<string, Draft> */
    private static function reminders(): array
    {
        return [
            BotText::ExpiryReminder->value => self::message('یادآوری پایان سرویس', 'چند روز پیش از پایان سرویس، وقتی این یادآوری روشن است؛ دکمه «مشاهده سرویس» زیر آن می‌آید.', '⏳ سرویس <code>%client%</code> رو به پایان است.

📅 تاریخ اتمام: %expires%
📥 حجم باقی‌مانده: %remaining%

برای اینکه سرویس قطع نشود، پیش از پایان تمدیدش کنید.%hint%', self::vars(...self::SERVICE) + self::vars('hint')),
            BotText::TrafficReminder->value => self::message('یادآوری پایان حجم', 'وقتی بیشتر حجم سرویس مصرف شده، وقتی این یادآوری روشن است؛ دکمه «مشاهده سرویس» زیر آن می‌آید.', '⚠️ %percent% از حجم سرویس <code>%client%</code> مصرف شده است.

📥 حجم باقی‌مانده: %remaining% از %traffic%
📅 تاریخ اتمام: %expires%

برای اینکه سرویس قطع نشود، پیش از تمام شدن حجم تمدیدش کنید.', self::vars(...self::SERVICE) + self::vars('percent')),
            BotText::ReminderAutoRenewHint->value => self::part('نکته تمدید خودکار', 'مقدار %hint% در یادآوری پایان سرویس، وقتی سرویس می‌تواند خودکار تمدید شود و تمدید خودکارش خاموش است.', '

💡 با روشن کردن «تمدید خودکار» در صفحه سرویس، سرویس پیش از پایان از کیف پول تمدید می‌شود.'),
            BotText::ReminderOpenService->value => self::button('دکمه مشاهده سرویس', 'زیر پیام‌های یادآوری، زیر پیام تمدید یا حجم و زمان اضافه وقتی از ربات با کیف پول پرداخت شد، و زیر اکانت تست تحویل‌شده یا پیام ارسال‌نشدن آن.', '📊 مشاهده سرویس'),
        ];
    }

    /** @return array<string, Draft> */
    private static function wallet(): array
    {
        return [
            BotText::WalletBalance->value => self::message('موجودی کیف پول', 'بالای صفحه کیف پول؛ تراکنش‌ها و دکمه افزایش موجودی زیر آن می‌آید.', '💳 موجودی کیف پول: <b>%balance%</b>', self::vars('balance')),
            BotText::WalletHistory->value => self::part('آخرین تراکنش‌ها', 'زیر موجودی در صفحه کیف پول.', '

📜 آخرین تراکنش‌ها:
%history%', self::vars('history'), ['history']),
            BotText::WalletHistoryCredit->value => self::part('تراکنش واریز', 'یک خط از آخرین تراکنش‌ها، برای مبلغی که به کیف پول اضافه شد.', '➕ %amount% · %entry% · %when%', self::vars('amount', 'entry', 'when')),
            BotText::WalletHistoryDebit->value => self::part('تراکنش برداشت', 'یک خط از آخرین تراکنش‌ها، برای مبلغی که از کیف پول کم شد.', '➖ %amount% · %entry% · %when%', self::vars('amount', 'entry', 'when')),
            BotText::WalletNoHistory->value => self::part('تراکنشی نیست', 'زیر موجودی، وقتی مشتری هنوز تراکنشی ندارد.', '

هنوز تراکنشی ندارید.'),
            BotText::WalletTopup->value => self::button('دکمه افزایش موجودی', 'در صفحه کیف پول، زیر «موجودی کافی نیست» و زیر پیام کمبود موجودی تمدید خودکار.', '➕ افزایش موجودی'),
            BotText::WalletCharged->value => self::message('شارژ کیف پول', 'بعد از شارژ موفق کیف پول.', '✅ کیف پول شما شارژ شد.
موجودی جدید: <b>%balance%</b>', self::vars('balance')),
            BotText::TopupPick->value => self::message('انتخاب مبلغ شارژ', 'بعد از «افزایش موجودی»؛ دکمه مبلغ‌ها و «مبلغ دلخواه» زیر آن می‌آید.', 'مبلغ شارژ را انتخاب کنید، یا مبلغ دلخواه خود را بفرستید (حداقل %min%):', self::vars('min')),
            BotText::TopupCustom->value => self::button('دکمه مبلغ دلخواه', 'زیر انتخاب مبلغ شارژ.', '✏️ مبلغ دلخواه'),
            BotText::TopupAsk->value => self::message('پرسش مبلغ دلخواه', 'بعد از «مبلغ دلخواه».', 'مبلغ شارژ را به تومان بفرستید (حداقل %min%):', self::vars('min')),
            BotText::TopupInvalid->value => self::message('مبلغ نامعتبر', 'وقتی مبلغی که مشتری فرستاده عدد درستی نیست یا از حداقل کمتر است.', 'مبلغ نامعتبر است. یک عدد به تومان بفرستید، حداقل %min%.', self::vars('min')),
            BotText::TopupFailed->value => self::message('شارژ ناموفق', 'وقتی پرداخت انجام شد ولی شارژ کیف پول انجام نشد.', 'پرداخت ثبت شد ولی شارژ کیف پول انجام نشد. پشتیبانی به‌زودی درستش می‌کند؛ نیازی به پرداخت دوباره نیست.'),
            BotText::WalletCredit->value => self::part('اعتبار نمایندگی', 'زیر موجودی در صفحه کیف پول، برای نماینده‌ای که اعتبار خرید دارد.', '
🏦 اعتبار خرید نمایندگی: %credit%', self::vars('credit')),
        ];
    }

    /** @return array<string, Draft> */
    private static function referral(): array
    {
        return [
            BotText::ReferralScreen->value => self::message('زیرمجموعه‌گیری', 'صفحه «زیرمجموعه‌گیری»: لینک دعوت مشتری، شرط پورسانت و آمار او؛ دکمه ارسال لینک زیر آن می‌آید.', '👥 <b>زیرمجموعه‌گیری</b>

%terms%

🔗 لینک دعوت شما:
<code>%link%</code>

👤 تعداد زیرمجموعه‌ها: %referrals%
💰 پورسانت دریافتی: %earned%', self::vars('terms', 'link', 'referrals', 'earned'), ['link']),
            BotText::ReferralTermsEvery->value => self::part('شرط پورسانت: هر پرداخت', 'مقدار %terms% در صفحه زیرمجموعه‌گیری، وقتی هر پرداخت زیرمجموعه پورسانت دارد.', 'دوستانتان را با لینک زیر به ربات دعوت کنید؛ از هر پرداخت موفق آن‌ها %rate% به عنوان پورسانت به کیف پول شما اضافه می‌شود.', self::vars('rate')),
            BotText::ReferralTermsFirst->value => self::part('شرط پورسانت: اولین پرداخت', 'مقدار %terms% در صفحه زیرمجموعه‌گیری، وقتی فقط اولین پرداخت هر زیرمجموعه پورسانت دارد.', 'دوستانتان را با لینک زیر به ربات دعوت کنید؛ از اولین پرداخت موفق هر کدام از آن‌ها %rate% به عنوان پورسانت به کیف پول شما اضافه می‌شود.', self::vars('rate')),
            BotText::ReferralTermsRenewal->value => self::part('پورسانت تمدید', 'زیر شرط پورسانت در صفحه زیرمجموعه‌گیری، وقتی هر پرداخت پورسانت دارد و درصد پورسانت تمدید سرویس با درصد پورسانت فرق دارد.', 'پورسانت تمدید سرویس‌ها %rate% است.', self::vars('rate', rate: 'درصد پورسانت تمدید، از تنظیمات ربات')),
            BotText::ReferralTermsNoRenewal->value => self::part('تمدید بدون پورسانت', 'زیر شرط پورسانت در صفحه زیرمجموعه‌گیری، وقتی هر پرداخت پورسانت دارد ولی تمدید سرویس پورسانت ندارد.', 'تمدید سرویس‌ها پورسانت ندارد.'),
            BotText::ReferralTermsGiftBoth->value => self::part('هدیه شروع: هر دو', 'در صفحه زیرمجموعه‌گیری، وقتی هدیه شروع هم به معرف و هم به زیرمجموعه داده می‌شود.', '
🎁 هدیه شروع: %trigger% هر دوستتان، %you% به کیف پول شما و %friend% به کیف پول خود او اضافه می‌شود.', self::vars('trigger', 'you', 'friend')),
            BotText::ReferralTermsGiftYou->value => self::part('هدیه شروع: معرف', 'در صفحه زیرمجموعه‌گیری، وقتی هدیه شروع فقط به معرف داده می‌شود.', '
🎁 هدیه شروع: %trigger% هر دوستتان، %you% به کیف پول شما اضافه می‌شود.', self::vars('trigger', 'you')),
            BotText::ReferralTermsGiftFriend->value => self::part('هدیه شروع: زیرمجموعه', 'در صفحه زیرمجموعه‌گیری، وقتی هدیه شروع فقط به زیرمجموعه داده می‌شود.', '
🎁 هدیه شروع: دوستانتان %trigger% خود، %friend% به کیف پولشان هدیه می‌گیرند.', self::vars('trigger', 'friend')),
            BotText::ReferralTermsReward->value => self::part('جایزه دعوت', 'در صفحه زیرمجموعه‌گیری، وقتی جایزه دعوت روشن است: به ازای هر چند زیرمجموعه‌ای که خرید کنند، یک سرویس رایگان.', '
🏆 جایزه دعوت: به ازای هر %invites% دوستی که خرید کنند، یک سرویس «%plan%» رایگان می‌گیرید.', self::vars('invites', 'plan', plan: 'پلن جایزه دعوت')),
            BotText::ReferralShare->value => self::button('دکمه ارسال لینک', 'زیر صفحه زیرمجموعه‌گیری؛ پنجره اشتراک‌گذاری تلگرام را با لینک دعوت باز می‌کند.', '📤 ارسال لینک برای دوستان'),
            BotText::ReferralOff->value => self::message('زیرمجموعه‌گیری خاموش', 'صفحه «زیرمجموعه‌گیری»، وقتی زیرمجموعه‌گیری در تنظیمات ربات خاموش است.', 'زیرمجموعه‌گیری فعلا فعال نیست.'),
            BotText::ReferralJoined->value => self::message('زیرمجموعه جدید', 'به معرف، وقتی کسی اولین بار با لینک دعوت او وارد ربات می‌شود.', '🎉 یک زیرمجموعه جدید با لینک دعوت شما وارد ربات شد.', ['name' => 'نام زیرمجموعه جدید']),
            BotText::ReferralCommission->value => self::message('دریافت پورسانت', 'به معرف، وقتی پرداخت یکی از زیرمجموعه‌هایش پورسانت دارد و به کیف پول او اضافه می‌شود.', '💰 %commission% پورسانت زیرمجموعه به کیف پول شما اضافه شد.
موجودی جدید: <b>%balance%</b>', self::vars('commission', 'paid', 'balance')),
            BotText::ReferralStartGiftReferrer->value => self::message('هدیه شروع معرف', 'به معرف، وقتی هدیه شروع یک زیرمجموعه (با عضویت یا اولین خرید او، به انتخاب تنظیمات ربات) به کیف پولش اضافه می‌شود.', '🎁 %amount% هدیه شروع بابت زیرمجموعه جدیدتان به کیف پول شما اضافه شد.
موجودی جدید: <b>%balance%</b>', self::vars('amount', 'name', 'balance', amount: 'هدیه شروع معرف', name: 'نام زیرمجموعه')),
            BotText::ReferralStartGiftInvitee->value => self::message('هدیه شروع زیرمجموعه', 'به زیرمجموعه، وقتی هدیه شروع او (با عضویت یا اولین خرید، به انتخاب تنظیمات ربات) به کیف پولش اضافه می‌شود.', '🎁 %amount% هدیه شروع به کیف پول شما اضافه شد؛ به خاطر عضویت با لینک دعوت دوستتان.
موجودی جدید: <b>%balance%</b>', self::vars('amount', 'balance', amount: 'هدیه شروع زیرمجموعه')),
            BotText::ReferralRewarded->value => self::message('جایزه دعوت', 'به معرف، وقتی زیرمجموعه‌های خریدارش به تعداد جایزه دعوت می‌رسند و یک سرویس رایگان به او داده می‌شود؛ خود سرویس جدا می‌آید.', '🏆 جایزه دعوت: %buyers% نفر از دوستانی که دعوت کردید خرید کرده‌اند و یک سرویس «%plan%» رایگان به شما هدیه داده شد.', self::vars('buyers', 'plan', plan: 'پلن جایزه دعوت')),
        ];
    }

    /** @return array<string, Draft> */
    private static function agency(): array
    {
        $group = 'agency';
        $price = ['price' => 'قیمت هر گیگابایت برای نماینده'];
        $left = ['traffic' => 'حجم باقی‌مانده ربات نماینده'];

        return [
            BotText::AgencyOff->value => self::message('نمایندگی خاموش', 'صفحه «نمایندگی»، وقتی نمایندگی در تنظیمات ربات خاموش است.', 'نمایندگی فعلا فعال نیست.'),
            BotText::AgencyTerms->value => self::message('معرفی نمایندگی', 'صفحه «نمایندگی» برای کسی که هنوز نماینده نیست؛ دکمه «درخواست نمایندگی» زیر آن می‌آید.', '🤝 <b>نمایندگی</b>

نماینده‌ها ربات فروش خودشان را دارند: پلن‌ها، قیمت‌ها، روش‌های پرداخت و مشتری‌هایش مال خودشان است و سرویس‌ها روی سرورهای ما ساخته می‌شوند. حجمی که ربات می‌فروشد را از همین‌جا می‌خرند.

قیمت هر گیگابایت در هر سطح:
%levels%

برای شروع درخواست بدهید؛ پشتیبانی آن را بررسی می‌کند و نتیجه همین‌جا اعلام می‌شود.', self::vars('levels')),
            BotText::AgencyLevelLine->value => self::part('سطح نمایندگی در معرفی', 'یک خط از %levels% در معرفی نمایندگی، برای هر سطح.', '• %level%: هر گیگابایت %price%', self::vars('level') + ['price' => 'قیمت هر گیگابایت این سطح']),
            BotText::AgencyNoLevels->value => self::part('هنوز سطحی نیست', 'مقدار %levels% در معرفی نمایندگی، وقتی هنوز سطحی ساخته نشده است.', 'به‌زودی اعلام می‌شود'),
            BotText::AgencyApply->value => self::button('دکمه درخواست نمایندگی', 'زیر معرفی نمایندگی.', '📝 درخواست نمایندگی'),
            BotText::AgencyAskNote->value => self::message('پرسش توضیح درخواست', 'بعد از «درخواست نمایندگی»؛ آنچه مشتری می‌نویسد همراه درخواست به پشتیبانی می‌رسد.', 'درباره خودتان و فروشتان چند خط بنویسید (مثلا کانال یا تعداد مشتری‌ها)؛ پشتیبانی آن را همراه درخواست می‌بیند.'),
            BotText::AgencyNoteInvalid->value => self::message('توضیح نامعتبر', 'وقتی مشتری به جای متن چیز دیگری می‌فرستد یا متنش بلند است.', 'لطفا توضیح را به صورت متن و حداکثر ۵۰۰ کاراکتر بفرستید.'),
            BotText::AgencyRequested->value => self::message('ثبت درخواست', 'بعد از اینکه درخواست نمایندگی ثبت شد.', '✅ درخواست نمایندگی شما ثبت شد. نتیجه بررسی همین‌جا به شما اعلام می‌شود.'),
            BotText::AgencyPending->value => self::message('درخواست در حال بررسی', 'صفحه «نمایندگی»، وقتی درخواست مشتری هنوز بررسی نشده است.', '⏳ درخواست نمایندگی شما در حال بررسی است. نتیجه همین‌جا به شما اعلام می‌شود.'),
            BotText::AgencyApproved->value => self::message('تایید نمایندگی', 'وقتی پشتیبانی درخواست نمایندگی را تایید می‌کند.', '🎉 درخواست نمایندگی شما تایید شد!

🏅 سطح: %level%
💾 قیمت هر گیگابایت: %price%
🏦 اعتبار خرید: %credit%

از «نمایندگی» در منو ربات خودتان را وصل کنید، حجم بخرید و وارد پنل فروشگاهتان شوید.', self::vars('level', 'credit') + $price),
            BotText::AgencyRejected->value => self::message('رد درخواست نمایندگی', 'وقتی پشتیبانی درخواست نمایندگی را رد می‌کند.', '❌ درخواست نمایندگی شما تایید نشد.%note%', self::vars('note')),
            BotText::AgencyChanged->value => self::message('تغییر نمایندگی', 'وقتی پشتیبانی سطح یا اعتبار نماینده را تغییر می‌دهد.', '🔄 نمایندگی شما به‌روز شد.

🏅 سطح: %level%
💾 قیمت هر گیگابایت: %price%
🏦 اعتبار خرید: %credit%', self::vars('level', 'credit') + $price),
            BotText::AgencyRevoked->value => self::message('لغو نمایندگی', 'وقتی پشتیبانی نمایندگی را لغو می‌کند.', '⛔️ نمایندگی شما لغو شد.%note%

ربات فروش شما خاموش شد و ورود به پنل آن دیگر ممکن نیست.', self::vars('note')),
            BotText::AgencyPanel->value => self::message('حساب نمایندگی', 'صفحه «نمایندگی» برای نماینده؛ دکمه‌های خرید حجم، ربات من، ورود به پنل و افزایش موجودی زیر آن می‌آید.', '🤝 <b>حساب نمایندگی</b>

🏅 سطح: %level% (هر گیگابایت %price%)
💾 حجم باقی‌مانده: %traffic%
💳 موجودی کیف پول: %balance%
🏦 اعتبار خرید: %credit%

🤖 ربات شما: %bot%
👥 مشتری‌ها: %customers%
📦 سرویس‌های فروخته‌شده: %sold%
✅ سرویس‌های فعال: %active%', self::vars('level', 'balance', 'credit', 'bot', 'customers', 'sold', 'active') + $price + $left),
            BotText::AgencyBuyTraffic->value => self::button('دکمه خرید حجم', 'در حساب نمایندگی، و زیر خبر کم آمدن حجم.', '💾 خرید حجم'),
            BotText::AgencyMyBot->value => self::button('دکمه ربات من', 'در حساب نمایندگی.', '🤖 ربات من'),
            BotText::AgencyPanelLogin->value => self::button('دکمه ورود به پنل', 'در حساب نمایندگی و زیر لینک ورود.', '🔐 ورود به پنل'),
            BotText::AgencyTraffic->value => self::message('خرید حجم', 'بعد از «خرید حجم»؛ دکمه حجم‌های پیشنهادی زیر آن می‌آید.', '💾 <b>خرید حجم</b>

قیمت هر گیگابایت: %price%
حجم باقی‌مانده ربات: %traffic%

یکی از حجم‌ها را بزنید، یا تعداد گیگابایت را بفرستید (حداقل %min%).', $price + $left + ['min' => 'کمترین خرید حجم، به گیگابایت']),
            BotText::AgencyTrafficButton->value => self::button('دکمه حجم پیشنهادی', 'زیر صفحه خرید حجم، یکی برای هر حجم پیشنهادی.', '%traffic% · %amount%', ['traffic' => 'حجم', 'amount' => 'قیمت این حجم']),
            BotText::AgencyTrafficInvalid->value => self::message('حجم نامعتبر', 'وقتی عددی که نماینده برای حجم فرستاده درست نیست.', 'تعداد گیگابایت را به عدد بفرستید؛ حداقل %min%.', ['min' => 'کمترین خرید حجم، به گیگابایت']),
            BotText::AgencyTrafficCheckout->value => self::message('صفحه پرداخت خرید حجم', 'همان صفحه پرداخت، برای خرید حجم نمایندگی.', '🧾 <b>خرید حجم نمایندگی</b>

حجم: <b>%traffic%</b>
مبلغ: <b>%amount%</b>

روش پرداخت را انتخاب کنید:', ['traffic' => 'حجمی که خریده می‌شود', 'amount' => 'مبلغ']),
            BotText::AgencyTrafficAdded->value => self::message('حجم اضافه شد', 'بعد از پرداخت موفق خرید حجم.', '✅ %traffic% به حجم ربات شما اضافه شد.
حجم باقی‌مانده: <b>%remaining%</b>', ['traffic' => 'حجمی که خریده شد', 'remaining' => 'حجم باقی‌مانده ربات نماینده']),
            BotText::AgencyTrafficFailed->value => self::message('خرید حجم ناموفق', 'وقتی پرداخت انجام شد ولی حجم اضافه نشد.', 'پرداخت ثبت شد ولی افزودن حجم انجام نشد؛ پشتیبانی به‌زودی آن را انجام می‌دهد.'),
            BotText::AgencyTrafficShort->value => self::message('حجم ربات کم است', 'به نماینده، وقتی ربات او سفارشی را به خاطر کم بودن حجم تحویل نداد؛ دکمه خرید حجم زیر آن می‌آید.', '⚠️ حجم ربات شما برای تحویل سفارش #%order% کافی نیست.
حجم باقی‌مانده: %traffic%

حجم بخرید و سپس در پنل، تحویل سفارش را دوباره امتحان کنید.', self::vars('order') + $left),
            BotText::ReceiptOutcomeTraffic->value => self::part('نتیجه رسید: خرید حجم', 'مقدار %outcome% در پیام دریافت رسید و پیام «پرداخت انجام شد»، وقتی سفارش خرید حجم نمایندگی است.', 'حجم به ربات شما اضافه می‌شود و همین‌جا خبر می‌دهیم'),
            BotText::AgencyNoBot->value => self::part('ربات هنوز وصل نشده', 'مقدار %bot% در حساب نمایندگی، وقتی نماینده هنوز رباتش را وصل نکرده است.', 'هنوز وصل نشده'),
            BotText::AgencyBotNone->value => self::message('وصل کردن ربات', 'بعد از «ربات من»، وقتی نماینده هنوز رباتش را وصل نکرده است.', '🤖 <b>ربات من</b>

۱. در @BotFather با دستور /newbot یک ربات بسازید.
۲. توکنی را که @BotFather می‌دهد همین‌جا بفرستید.

ربات شما همین حالا راه می‌افتد؛ پلن‌ها، قیمت‌ها، روش‌های پرداخت و متن‌هایش را در پنل خودتان می‌سازید.'),
            BotText::AgencyBotInfo->value => self::message('ربات من', 'بعد از «ربات من»، وقتی ربات نماینده وصل است.', '🤖 ربات شما: %bot%
%status%

اگر توکن ربات را در @BotFather عوض کردید، توکن تازه را همین‌جا بفرستید.', self::vars('bot') + ['status' => 'وضعیت ربات (یکی از دو متن وضعیت ربات)']),
            BotText::AgencyBotRunning->value => self::part('وضعیت ربات: فعال', 'مقدار %status% در صفحه ربات من، وقتی ربات بدون مشکل کار می‌کند.', '✅ فعال است و به مشتری‌ها جواب می‌دهد.'),
            BotText::AgencyBotProblem->value => self::part('وضعیت ربات: مشکل', 'در صفحه ربات من و حساب نمایندگی، وقتی چیزی جلوی کار ربات را گرفته است.', '⚠️ %reason%', ['reason' => 'آنچه جلوی کار ربات را گرفته']),
            BotText::AgencyBotSaved->value => self::message('ربات وصل شد', 'بعد از اینکه نماینده توکن درست را فرستاد.', '✅ ربات %bot% وصل شد و از همین حالا به مشتری‌ها جواب می‌دهد.

حالا وارد پنل شوید و پلن‌ها و روش‌های پرداخت ربات را بسازید.', self::vars('bot')),
            BotText::AgencyBotRefused->value => self::message('توکن پذیرفته نشد', 'وقتی توکنی که نماینده فرستاده پذیرفته نمی‌شود.', '⚠️ %reason%', ['reason' => 'چرا توکن پذیرفته نشد']),
            BotText::AgencyLogin->value => self::message('لینک ورود به پنل', 'بعد از «ورود به پنل»؛ لینکی که یک بار و برای چند دقیقه کار می‌کند.', '🔐 <b>ورود به پنل نمایندگی</b>

این لینک فقط یک بار و تا %minutes% دقیقه کار می‌کند؛ آن را به کسی ندهید:
%login%', self::vars('minutes', 'login'), ['login']),
        ];
    }

    /** @return array<string, Draft> */
    private static function account(): array
    {
        return [
            BotText::TwoFactorDisabled->value => self::message('خاموش شدن ورود دو مرحله‌ای', 'وقتی پشتیبانی ورود دو مرحله‌ای حساب مشتری در وب‌سایت را خاموش می‌کند (مثلا گوشی‌اش گم شده است).', '🔐 ورود دو مرحله‌ای حساب شما در وب‌سایت توسط پشتیبانی خاموش شد.

از این پس برای ورود با ایمیل، رمز عبور کافی است؛ هر وقت خواستید می‌توانید آن را دوباره از حساب‌تان در وب‌سایت روشن کنید.'),
            BotText::TwoFactorTurnedOn->value => self::message('روشن کردن ورود دو مرحله‌ای', 'وقتی مشتری ورود دو مرحله‌ای حسابش در وب‌سایت را روشن می‌کند.', '🔐 ورود دو مرحله‌ای حساب شما در وب‌سایت روشن شد؛ از این پس برای ورود با ایمیل، کد برنامه احراز هویت هم خواسته می‌شود.

اگر این کار شما نبود، هر چه زودتر با پشتیبانی در تماس باشید.'),
            BotText::TwoFactorTurnedOff->value => self::message('خاموش کردن ورود دو مرحله‌ای', 'وقتی مشتری خودش ورود دو مرحله‌ای حسابش در وب‌سایت را خاموش می‌کند.', '🔐 ورود دو مرحله‌ای حساب شما در وب‌سایت خاموش شد.

اگر این کار شما نبود، رمز عبور حساب‌تان دست کس دیگری است؛ هر چه زودتر آن را عوض کنید و با پشتیبانی در تماس باشید.'),
            BotText::WayInAdded->value => self::message('روش ورود تازه', 'وقتی روش ورود تازه‌ای (تلگرام، گوگل یا ایمیل) به حساب مشتری در وب‌سایت اضافه می‌شود.', '🔐 ورود با %way% به حساب شما در وب‌سایت اضافه شد.

اگر این کار شما نبود، هر چه زودتر با پشتیبانی در تماس باشید.', self::vars('way'), ['way']),
            BotText::WayInRemoved->value => self::message('برداشتن روش ورود', 'وقتی یکی از روش‌های ورود حساب مشتری در وب‌سایت برداشته می‌شود.', '🔐 ورود با %way% از حساب شما در وب‌سایت برداشته شد.

اگر این کار شما نبود، هر چه زودتر با پشتیبانی در تماس باشید.', self::vars('way'), ['way']),
            BotText::PasswordChanged->value => self::message('رمز عبور تازه', 'وقتی رمز عبور حساب مشتری در وب‌سایت گذاشته یا عوض می‌شود — از حسابش، یا با «فراموشی رمز عبور».', '🔐 رمز عبور تازه‌ای برای حساب شما در وب‌سایت گذاشته شد.

اگر این کار شما نبود، هر چه زودتر با پشتیبانی در تماس باشید.'),
            BotText::AccountMerged->value => self::message('یکی شدن دو حساب', 'وقتی مشتری حساب دیگری از خودش را با حسابش در وب‌سایت یکی می‌کند.', '🔗 حساب دیگری از شما با این حساب یکی شد؛ سرویس‌ها، سفارش‌ها و کیف پول هر دو از این پس در یک حساب است.

اگر این کار شما نبود، هر چه زودتر با پشتیبانی در تماس باشید.'),
            BotText::SecondStepLocked->value => self::message('کد اشتباه ورود دو مرحله‌ای', 'وقتی کد ورود دو مرحله‌ای حساب مشتری در وب‌سایت آن‌قدر اشتباه وارد می‌شود که ورود با ایمیل مدتی بسته می‌ماند؛ روزی یک بار.', '⚠️ کسی رمز عبور حساب شما در وب‌سایت را درست وارد کرد، ولی کد ورود دو مرحله‌ای را چند بار اشتباه زد؛ ورود با ایمیل و رمز عبور مدتی بسته ماند.

اگر این شما نبودید، رمز عبورتان دست کس دیگری است؛ هر چه زودتر آن را عوض کنید.'),
            BotText::EmailCodesFailed->value => self::message('کد اشتباه ایمیل', 'وقتی کدی که برای ایمیل حساب مشتری در وب‌سایت فرستاده می‌شود (بازیابی رمز عبور یا افزودن ایمیل) آن‌قدر اشتباه وارد می‌شود که تا مدتی کد تازه‌ای به آن ایمیل نمی‌رود؛ روزی یک بار.', '⚠️ کدی که برای ایمیل حساب شما در وب‌سایت فرستاده شد چند بار اشتباه وارد شد؛ تا مدتی کد تازه‌ای به این ایمیل فرستاده نمی‌شود.

اگر این شما نبودید، کسی می‌خواهد با ایمیل شما وارد حساب‌تان شود؛ برای امنیت بیشتر، ورود دو مرحله‌ای را در حساب‌تان روشن کنید.'),
        ];
    }

    /** @return array<string, Draft> */
    private static function support(): array
    {
        return [
            BotText::SupportContact->value => self::message('پشتیبانی', 'صفحه «پشتیبانی»؛ راه تماس از تنظیمات ربات می‌آید.', 'نیاز به کمک دارید؟ با ما در تماس باشید: %contact%', self::vars('contact'), ['contact']),
            BotText::SupportUnavailable->value => self::message('پشتیبانی بدون راه تماس', 'صفحه «پشتیبانی»، وقتی راه تماسی در تنظیمات ربات نیست؛ دکمه‌های «تیکت جدید» و «تیکت‌های من» زیر آن می‌آید.', 'نیاز به کمک دارید؟ با دکمه‌های زیر تیکت پشتیبانی ثبت کنید یا تیکت‌های قبلی خود را ببینید؛ پاسخ پشتیبانی همین‌جا به شما می‌رسد.'),
            BotText::TicketNew->value => self::button('دکمه تیکت جدید', 'در صفحه «پشتیبانی»، و زیر لیست تیکت‌ها وقتی مشتری هنوز تیکتی ندارد.', '📨 تیکت جدید'),
            BotText::TicketMine->value => self::button('دکمه تیکت‌های من', 'در صفحه «پشتیبانی».', '🗂️ تیکت‌های من'),
            BotText::TicketPickService->value => self::message('انتخاب سرویس تیکت', 'بعد از «تیکت جدید»، وقتی مشتری سرویس فعالی دارد؛ دکمه هر سرویس و «بدون سرویس مشخص» زیر آن می‌آید.', '📨 <b>تیکت جدید</b>

تیکت شما درباره کدام سرویس است؟'),
            BotText::TicketNoService->value => self::button('دکمه بدون سرویس مشخص', 'زیر انتخاب سرویس تیکت جدید.', 'بدون سرویس مشخص'),
            BotText::TicketAsk->value => self::message('نوشتن تیکت جدید', 'بعد از انتخاب سرویس — یا «تیکت جدید»، وقتی مشتری سرویسی ندارد —؛ پیام بعدی مشتری تیکت را باز می‌کند و خط اول آن موضوع تیکت می‌شود.', '📨 <b>تیکت جدید</b>%service%

مشکل یا سوال خود را در یک پیام بنویسید؛ خط اول پیام، موضوع تیکت می‌شود.
اگر تصویری دارید، آن را همراه توضیح (کپشن) بفرستید.', self::vars('service')),
            BotText::TicketReportAsk->value => self::message('گزارش اختلال سرویس', 'بعد از «ارسال گزارش اختلال» در صفحه سرویس؛ پیام بعدی مشتری تیکتی درباره همان سرویس باز می‌کند و خط اول آن موضوع تیکت می‌شود.', '⚠️ <b>گزارش اختلال</b>
🔖 سرویس: <code>%client%</code>

مشکل این سرویس را در یک پیام بنویسید: از کی، روی چه دستگاهی و با کدام اینترنت وصل نمی‌شود؛ خط اول پیام، موضوع تیکت می‌شود.
اگر تصویری از خطا دارید، آن را همراه توضیح (کپشن) بفرستید.', self::vars('client')),
            BotText::TicketService->value => self::part('سرویس تیکت', 'مقدار %service% در نوشتن تیکت جدید و صفحه تیکت، وقتی تیکت درباره یکی از سرویس‌های مشتری است.', '
🔖 سرویس: <code>%client%</code>', self::vars('client')),
            BotText::TicketPictureNeedsWords->value => self::message('تصویر بدون توضیح', 'وقتی مشتری برای تیکت تصویری بدون توضیح (کپشن) می‌فرستد؛ تصویر نگه داشته می‌شود و پیام متنی بعدی همراه آن فرستاده می‌شود.', '🖼️ تصویر رسید؛ حالا توضیح آن را در یک پیام بنویسید تا همراه تصویر فرستاده شود.'),
            BotText::TicketTextOnly->value => self::message('فقط متن یا تصویر', 'وقتی مشتری برای تیکت چیزی جز متن یا تصویر می‌فرستد: فایل، ویس، ویدیو، استیکر و مانند آن.', 'برای تیکت فقط متن یا تصویر فرستاده می‌شود؛ پیام خود را بنویسید، یا تصویر را همراه توضیح بفرستید.'),
            BotText::TicketTooShort->value => self::message('پیام کوتاه', 'وقتی پیامی که تیکت جدید را باز می‌کند کوتاه‌تر از آن است که موضوع تیکت شود (کمتر از سه حرف)؛ تصویری که همراهش بود نگه داشته می‌شود.', 'پیام شما خیلی کوتاه است؛ لطفا مشکل یا سوال خود را کمی بیشتر توضیح دهید.%kept%', self::vars('kept')),
            BotText::TicketRefused->value => self::message('پیام پذیرفته نشد', 'وقتی پیام تیکت پذیرفته نمی‌شود: خیلی طولانی است، تیکت به سقف پیام‌ها رسیده، یا مشتری در مدت کوتاهی تیکت یا پیام زیادی فرستاده است؛ تصویری که همراهش بود نگه داشته می‌شود.', '⚠️ %reason%%kept%', self::vars('reason', 'kept', reason: 'چرا پیام پذیرفته نشد'), ['reason']),
            BotText::TicketOpened->value => self::message('ثبت تیکت', 'بعد از اینکه تیکت جدید باز شد؛ دکمه «مشاهده تیکت» زیر آن می‌آید. پیام‌های بعدی مشتری به همین تیکت اضافه می‌شود، تا وقتی به بخش دیگری از ربات برود.', '✅ تیکت #%ticket% ثبت شد.
📌 موضوع: %subject%

پاسخ پشتیبانی همین‌جا برایتان فرستاده می‌شود. اگر توضیح دیگری دارید، همین حالا بفرستید تا به این تیکت اضافه شود.', self::vars('ticket', 'subject')),
            BotText::TicketMessageSent->value => self::message('ثبت پیام تیکت', 'بعد از هر پیام مشتری به تیکتش؛ دکمه «مشاهده تیکت» زیر آن می‌آید.', '✅ پیام شما به تیکت #%ticket% اضافه شد.', self::vars('ticket')),
            BotText::TicketView->value => self::button('دکمه مشاهده تیکت', 'زیر ثبت تیکت و ثبت پیام، و زیر پاسخ پشتیبانی و بسته شدن تیکت.', '🗂️ مشاهده تیکت'),
            BotText::TicketsTitle->value => self::message('لیست تیکت‌ها', 'بالای دکمه‌های تیکت‌ها در «تیکت‌های من»؛ تیکتی که تازه‌تر پیام گرفته بالاتر است.', '🗂️ <b>تیکت‌های شما</b>
برای دیدن گفتگو و پاسخ دادن، روی تیکت بزنید.

🟡 در انتظار پاسخ · 🟢 پاسخ داده شده · 🔒 بسته'),
            BotText::TicketsEmpty->value => self::message('تیکتی نیست', 'وقتی مشتری هنوز تیکتی ندارد؛ دکمه «تیکت جدید» زیر آن می‌آید.', 'شما هنوز تیکتی ندارید. برای پرسیدن سوال یا گزارش مشکل، تیکت جدید بفرستید.'),
            BotText::TicketButtonOpen->value => self::button('دکمه تیکت در انتظار پاسخ', 'در لیست تیکت‌ها، برای تیکتی که منتظر پاسخ پشتیبانی است.', '🟡 #%ticket% · %subject%', self::vars('ticket', 'subject'), ['ticket']),
            BotText::TicketButtonAnswered->value => self::button('دکمه تیکت پاسخ‌داده‌شده', 'در لیست تیکت‌ها، برای تیکتی که پشتیبانی به آن پاسخ داده است.', '🟢 #%ticket% · %subject%', self::vars('ticket', 'subject'), ['ticket']),
            BotText::TicketButtonClosed->value => self::button('دکمه تیکت بسته', 'در لیست تیکت‌ها، برای تیکت بسته.', '🔒 #%ticket% · %subject%', self::vars('ticket', 'subject'), ['ticket']),
            BotText::TicketScreen->value => self::message('صفحه تیکت', 'وقتی مشتری یک تیکت را باز می‌کند؛ آخرین پیام‌های گفتگو در آن است و دکمه‌های پاسخ، بستن یا امتیاز زیر آن می‌آید.', '🎫 <b>تیکت #%ticket%</b>
📌 موضوع: %subject%
📊 وضعیت: %status%%service%%rated%
%messages%', self::vars('ticket', 'subject', 'status', 'service', 'rated', 'messages', status: 'وضعیت تیکت (یکی از متن‌های وضعیت تیکت)'), ['messages']),
            BotText::TicketStatusOpen->value => self::part('وضعیت تیکت: در انتظار پاسخ', 'مقدار %status% در صفحه تیکت، وقتی تیکت منتظر پاسخ پشتیبانی است.', '🟡 در انتظار پاسخ پشتیبانی'),
            BotText::TicketStatusAnswered->value => self::part('وضعیت تیکت: پاسخ داده شده', 'مقدار %status% در صفحه تیکت، وقتی پشتیبانی به تیکت پاسخ داده است.', '🟢 پاسخ داده شده'),
            BotText::TicketStatusClosed->value => self::part('وضعیت تیکت: بسته', 'مقدار %status% در صفحه تیکت، وقتی تیکت بسته است.', '🔒 بسته شده'),
            BotText::TicketRating->value => self::part('امتیاز تیکت', 'مقدار %rated% در صفحه تیکت، وقتی مشتری به تیکت بسته امتیاز داده است.', '
⭐ امتیاز شما: %rating% از ۵', self::vars('rating')),
            BotText::TicketEarlier->value => self::part('پیام‌های قبلی', 'بالای گفتگوی صفحه تیکت، وقتی پیام‌های قدیمی‌تری هست که نشان داده نمی‌شود.', '
⋯ %earlier% پیام قبلی', self::vars('earlier')),
            BotText::TicketFromCustomer->value => self::part('پیام مشتری', 'هر پیام مشتری در گفتگوی صفحه تیکت.', '
👤 <b>شما</b> · %when%
%message%%picture%', self::vars('when', 'message', 'picture', when: 'زمان پیام', picture: 'اشاره به تصویر پیام (متن «تصویر پیام»)، یا خالی'), ['message']),
            BotText::TicketFromSupport->value => self::part('پیام پشتیبانی', 'هر پیام پشتیبانی در گفتگوی صفحه تیکت.', '
🎧 <b>پشتیبانی</b> · %when%
%message%%picture%', self::vars('when', 'message', 'picture', when: 'زمان پیام', picture: 'اشاره به تصویر پیام (متن «تصویر پیام»)، یا خالی'), ['message']),
            BotText::TicketMessagePicture->value => self::part('تصویر پیام', 'مقدار %picture% در پیام‌های صفحه تیکت، وقتی پیام تصویری دارد؛ دکمه «تصویر» با همین شماره زیر صفحه، خود تصویر را می‌فرستد.', '
🖼️ همراه تصویر %number%', self::vars('number')),
            BotText::TicketPictureRemoved->value => self::part('تصویر پیام دیگر نیست', 'مقدار %picture% در پیام‌های صفحه تیکت، وقتی تصویری که همراه پیام بود دیگر نگه داشته نمی‌شود (تصویرهای آپلودشده تیکت بسته، ۳۰ روز بعد از بسته شدن پاک می‌شوند).', '
🖼️ تصویر این پیام دیگر نگه داشته نمی‌شود.'),
            BotText::TicketPicture->value => self::button('دکمه تصویر', 'زیر صفحه تیکت، یکی برای هر پیام نمایش‌داده‌شده‌ای که تصویر دارد؛ تصویر را جدا می‌فرستد.', '🖼️ تصویر %number%', self::vars('number')),
            BotText::TicketPictureCaption->value => self::message('توضیح تصویر تیکت', 'زیر تصویری که دکمه «تصویر» صفحه تیکت می‌فرستد (توضیح بلندتر از هزار حرف، تصویر را بی‌توضیح می‌فرستد).', '🖼️ تصویر پیام %when% — تیکت #%ticket%', self::vars('when', 'ticket', when: 'زمان پیامی که تصویر همراه آن بود')),
            BotText::TicketPictureGone->value => self::popup('تصویر در دسترس نیست', 'وقتی تصویری که مشتری با دکمه «تصویر» می‌خواهد دیگر نیست: تلگرام آن را نمی‌دهد، یا فایلش پاک شده است.', 'این تصویر دیگر در دسترس نیست.'),
            BotText::TicketPictureKept->value => self::part('تصویر نگه داشته شد', 'مقدار %kept% در «پیام کوتاه» و «پیام پذیرفته نشد»، وقتی همراه پیام تصویری هم بود: تصویر نگه داشته می‌شود و با پیام بعدی مشتری می‌رود.', '

🖼️ تصویر شما نگه داشته شد و با پیام بعدی‌تان به تیکت فرستاده می‌شود.'),
            BotText::TicketNotFound->value => self::message('تیکت پیدا نشد', 'وقتی تیکتی که مشتری باز می‌کند پیدا نمی‌شود.', 'این تیکت پیدا نشد.'),
            BotText::TicketReply->value => self::button('دکمه پاسخ', 'در صفحه تیکت و زیر پاسخ پشتیبانی.', '✍️ پاسخ'),
            BotText::TicketReplyAsk->value => self::message('نوشتن پاسخ', 'بعد از «پاسخ»؛ پیام بعدی مشتری به تیکت اضافه می‌شود.', '✍️ پیام خود برای تیکت #%ticket% را بنویسید؛ اگر تصویری دارید، آن را همراه توضیح (کپشن) بفرستید.%reopens%', self::vars('ticket', 'reopens')),
            BotText::TicketReplyReopens->value => self::part('باز شدن دوباره تیکت', 'مقدار %reopens% در نوشتن پاسخ، وقتی تیکت بسته است.', '

🔓 این تیکت بسته شده است؛ با فرستادن پیام دوباره باز می‌شود.'),
            BotText::TicketClose->value => self::button('دکمه بستن تیکت', 'در صفحه تیکتی که هنوز باز است.', '🔒 بستن تیکت'),
            BotText::TicketClosedByCustomer->value => self::popup('تیکت بسته شد', 'اعلان کوتاه بعد از اینکه مشتری تیکت را می‌بندد.', '🔒 تیکت بسته شد.'),
            BotText::TicketAlreadyClosed->value => self::popup('تیکت پیش‌تر بسته شده', 'وقتی مشتری تیکتی را می‌بندد که همان لحظه بسته شده است.', 'این تیکت پیش‌تر بسته شده است.'),
            BotText::TicketRate->value => self::button('دکمه امتیاز', 'در صفحه تیکت بسته‌ای که هنوز امتیاز نگرفته، و زیر خبر بسته شدن تیکت.', '⭐ امتیاز'),
            BotText::TicketRateAsk->value => self::message('امتیاز به تیکت', 'بعد از «امتیاز»؛ دکمه‌های ۱ تا ۵ زیر آن می‌آید.', '⭐ از پاسخ‌گویی پشتیبانی در تیکت #%ticket% چقدر راضی بودید؟ از ۱ تا ۵ امتیاز دهید.', self::vars('ticket')),
            BotText::TicketStar->value => self::button('دکمه امتیاز ۱ تا ۵', 'زیر «امتیاز به تیکت»، یک دکمه برای هر امتیاز.', '%rating% ⭐', self::vars(rating: 'امتیازی که این دکمه می‌دهد، از ۱ تا ۵'), ['rating']),
            BotText::TicketRatedThanks->value => self::popup('ثبت امتیاز', 'اعلان کوتاه بعد از ثبت امتیاز.', '⭐ امتیاز شما ثبت شد؛ ممنون!'),
            BotText::TicketRateUnavailable->value => self::popup('امتیاز فقط برای تیکت بسته', 'وقتی مشتری به تیکتی امتیاز می‌دهد که همان لحظه دوباره باز شده است.', 'امتیاز را پس از بسته شدن تیکت می‌توانید ثبت کنید.'),
            BotText::TicketAnswered->value => self::message('پاسخ پشتیبانی به تیکت', 'وقتی پشتیبانی — از پنل یا گروه گزارش‌ها — به تیکت مشتری پاسخ می‌دهد؛ دکمه‌های «پاسخ» و «مشاهده تیکت» زیر آن می‌آید، و مشتری بدون تلگرام آن را با ایمیل می‌گیرد. پاسخی که تصویر دارد همراه تصویرش فرستاده می‌شود و این متن توضیح تصویر است، تا وقتی در توضیح یک تصویر جا شود.', '💬 <b>پاسخ پشتیبانی به تیکت #%ticket%</b>
📌 %subject%

%answer%%picture%', self::vars('ticket', 'subject', 'answer', 'picture'), ['answer']),
            BotText::TicketAnswerPicture->value => self::part('تصویر همراه پاسخ', 'مقدار %picture% در پاسخ پشتیبانی به تیکت، وقتی پاسخ تصویری هم دارد.', '

🖼️ این پاسخ یک تصویر هم دارد.'),
            BotText::TicketClosed->value => self::message('بسته شدن تیکت', 'وقتی پشتیبانی تیکت مشتری را می‌بندد؛ دکمه‌های «امتیاز» و «مشاهده تیکت» زیر آن می‌آید.', '🔒 تیکت #%ticket% («%subject%») توسط پشتیبانی بسته شد.

اگر هنوز سوالی دارید، به همین تیکت پیام بدهید تا دوباره باز شود.', self::vars('ticket', 'subject')),
        ];
    }

    /** @return array<string, Draft> */
    private static function giftCodes(): array
    {
        return [
            BotText::GiftCodeAsk->value => self::message('کد هدیه', 'صفحه «کد هدیه» منوی اصلی؛ پیام بعدی مشتری کد هدیه‌ای است که دارد.', '🎁 <b>کد هدیه</b>

اگر کد هدیه دارید، همین‌جا بفرستید تا اعتبار یا سرویسش برای شما فعال شود.'),
            BotText::GiftCodeOff->value => self::message('کد هدیه خاموش', 'صفحه «کد هدیه»، وقتی کد هدیه در تنظیمات خاموش است.', 'کد هدیه فعلا فعال نیست.'),
            BotText::GiftCodeWallet->value => self::message('شارژ با کد هدیه', 'وقتی کد هدیه‌ای که مبلغ دارد پذیرفته می‌شود.', '🎉 کد هدیه پذیرفته شد: %amount% به کیف پول شما اضافه شد.
💰 موجودی: %balance%', self::vars('amount', 'balance', amount: 'مبلغ کد هدیه')),
            BotText::GiftCodePlan->value => self::message('سرویس با کد هدیه', 'وقتی کد هدیه‌ای که سرویس رایگان دارد پذیرفته می‌شود؛ خود سرویس جدا و پیش از این پیام فرستاده می‌شود.', '🎉 کد هدیه پذیرفته شد: سرویس «%plan%» برای شما ساخته شد و همین‌جا فرستاده شد.', self::vars('plan')),
            BotText::GiftCodePlanPending->value => self::message('سرویس کد هدیه در راه', 'وقتی کد هدیه پذیرفته شد ولی ساخت سرویسش روی سرور ناموفق بود؛ پشتیبانی آن را تحویل می‌دهد.', '🎉 کد هدیه پذیرفته شد: سرویس «%plan%» (سفارش #%order%) برای شما ثبت شد و به‌زودی همین‌جا فرستاده می‌شود.', self::vars('plan', 'order')),
            BotText::GiftCodeUnknown->value => self::message('کد پیدا نشد', 'وقتی کدی که مشتری فرستاده در فروشگاه نیست؛ می‌تواند کد دیگری بفرستد.', '❌ این کد پیدا نشد؛ آن را دوباره بررسی کنید و بفرستید.'),
            BotText::GiftCodeTaken->value => self::message('کد استفاده‌شده', 'وقتی کد را کس دیگری پیش‌تر استفاده کرده است.', '❌ این کد پیش‌تر استفاده شده است.'),
            BotText::GiftCodeYours->value => self::message('کد استفاده‌شده توسط خود مشتری', 'وقتی مشتری کدی را دوباره می‌فرستد که خودش پیش‌تر استفاده کرده است.', 'این کد را پیش‌تر خودتان استفاده کرده‌اید.'),
            BotText::GiftCodeExpired->value => self::message('کد منقضی', 'وقتی مهلت استفاده از کد تمام شده است.', '⌛ مهلت استفاده از این کد تمام شده است.'),
            BotText::GiftCodeUnavailable->value => self::message('کد فعلا قابل استفاده نیست', 'وقتی جایزه کد همین الان داده نمی‌شود (مثلا سروری سرویس هدیه را تحویل نمی‌دهد)؛ کد برای مشتری می‌ماند.', '⚠️ این کد الان قابل استفاده نیست: %reason%
کد هنوز معتبر است؛ کمی بعد دوباره بفرستید.', self::vars('reason')),
            BotText::GiftCodeWait->value => self::message('کد اشتباه زیاد', 'وقتی مشتری در یک ساعت کد اشتباه زیادی فرستاده است.', '⏳ کد اشتباه زیادی فرستاده‌اید؛ %minutes% دقیقه دیگر دوباره امتحان کنید.', self::vars('minutes')),
        ];
    }

    /** @return array<string, Draft> */
    private static function wheel(): array
    {
        return [
            BotText::WheelScreen->value => self::message('گردونه شانس', 'صفحه «گردونه شانس» منوی اصلی؛ دکمه «بچرخان» زیر آن می‌آید، یا وقتی نوبت نرسیده زمان چرخش بعدی در آن است.', '🎡 <b>گردونه شانس</b>

گردونه را بچرخانید و یکی از این جایزه‌ها را ببرید:
%prizes%%cost%%turn%', self::vars('prizes', 'cost', 'turn')),
            BotText::WheelPrizeLine->value => self::part('جایزه گردونه', 'مقدار %prizes% در صفحه گردونه شانس، یک خط برای هر جایزه.', '🎁 %prize%', self::vars('prize')),
            BotText::WheelCost->value => self::part('هزینه گردونه', 'مقدار %cost% در صفحه گردونه شانس، وقتی هر چرخش هزینه دارد.', '

💰 هزینه هر چرخش %amount% از کیف پول است و هر %every% یک بار می‌توانید بچرخانید.', self::vars('amount', 'every', amount: 'هزینه هر چرخش')),
            BotText::WheelFree->value => self::part('گردونه رایگان', 'مقدار %cost% در صفحه گردونه شانس، وقتی چرخش رایگان است.', '

🎁 چرخیدن رایگان است؛ هر %every% یک بار می‌توانید بچرخانید.', self::vars('every')),
            BotText::WheelNext->value => self::part('چرخش بعدی', 'مقدار %turn% در صفحه گردونه شانس و نتیجه چرخش، وقتی نوبت چرخش بعدی مشتری هنوز نرسیده.', '

🕒 چرخش بعدی شما: %when% (%left% دیگر)', self::vars('when', 'left', when: 'زمان چرخش بعدی')),
            BotText::WheelSpinButton->value => self::button('دکمه بچرخان', 'زیر صفحه گردونه شانس، وقتی نوبت مشتری است.', '🎡 بچرخان'),
            BotText::WheelOff->value => self::message('گردونه خاموش', 'صفحه «گردونه شانس»، وقتی گردونه در تنظیمات خاموش است.', 'گردونه شانس فعلا فعال نیست.'),
            BotText::WheelBuyersOnly->value => self::message('گردونه فقط برای خریداران', 'صفحه «گردونه شانس»، وقتی گردونه فقط برای مشتری‌هایی است که سرویس خریده‌اند و این مشتری هنوز نخریده است.', '🎡 گردونه شانس ویژه مشتری‌هایی است که دست‌کم یک سرویس خریده‌اند؛ بعد از اولین خرید، شما هم می‌توانید بچرخانید.'),
            BotText::WheelEmpty->value => self::message('گردونه بدون جایزه', 'وقتی گردونه جایزه‌ای ندارد، یا هیچ‌کدام از جایزه‌هایش الان به این مشتری نمی‌رسد (مثلا همه حجم یا زمان‌اند و او سرویس فعالی ندارد).', 'گردونه شانس فعلا جایزه‌ای برای شما ندارد؛ کمی بعد سر بزنید.'),
            BotText::WheelTooSoon->value => self::popup('هنوز نوبت نیست', 'اعلان وقتی مشتری پیش از رسیدن نوبتش «بچرخان» را می‌زند.', 'هنوز نوبت چرخش بعدی نرسیده؛ %left% دیگر.', self::vars('left')),
            BotText::WheelShort->value => self::message('موجودی کم برای چرخش', 'وقتی کیف پول مشتری هزینه چرخش را ندارد؛ دکمه افزایش موجودی زیر آن می‌آید.', '💰 موجودی کیف پول برای چرخش کافی نیست: هزینه هر چرخش %amount% است و %missing% کم دارید.', self::vars('amount', 'missing', amount: 'هزینه هر چرخش')),
            BotText::WheelSlice->value => self::part('خانه گردونه', 'بالای نتیجه چرخش، یک خط برای هر جایزه گردونه که گردونه رویش نایستاد.', '▫️ %prize%', self::vars('prize')),
            BotText::WheelSliceCurrent->value => self::part('خانه زیر نشانگر', 'بالای نتیجه چرخش، خط جایزه‌ای که گردونه رویش ایستاد.', '👉 <b>%prize%</b>', self::vars('prize')),
            BotText::WheelWon->value => self::message('برنده گردونه', 'وقتی گردونه روی جایزه‌ای می‌ایستد و جایزه داده می‌شود.', '🎉 <b>بردی: %prize%</b>

%given%%turn%', self::vars('prize', 'given', 'turn')),
            BotText::WheelGivenWallet->value => self::part('جایزه به کیف پول', 'مقدار %given%، وقتی جایزه شارژ کیف پول است.', '%amount% به کیف پول شما اضافه شد؛ موجودی: %balance%.', self::vars('amount', 'balance', amount: 'مبلغ جایزه')),
            BotText::WheelGivenService->value => self::part('جایزه به سرویس', 'مقدار %given%، وقتی جایزه حجم یا زمان است.', '%extra% به سرویس <code>%client%</code> اضافه شد.', self::vars('extra', 'client', extra: 'حجم یا زمانی که جایزه داد')),
            BotText::WheelGivenPlan->value => self::part('جایزه سرویس', 'مقدار %given%، وقتی جایزه یک سرویس است؛ خود سرویس جدا و پیش از این پیام فرستاده می‌شود.', 'سرویس «%plan%» برای شما ساخته شد و همین‌جا فرستاده شد.', self::vars('plan')),
            BotText::WheelGivenPlanPending->value => self::part('جایزه سرویس در راه', 'مقدار %given%، وقتی ساخت سرویس جایزه روی سرور ناموفق بود؛ پشتیبانی آن را تحویل می‌دهد.', 'سرویس «%plan%» (سفارش #%order%) برای شما ثبت شد و به‌زودی همین‌جا فرستاده می‌شود.', self::vars('plan', 'order')),
            BotText::WheelNothing->value => self::message('پوچ', 'وقتی گردونه روی جایزه «پوچ» می‌ایستد.', '😕 <b>پوچ!</b>

این بار جایزه‌ای نبود؛ دفعه بعد شانس با شماست.%turn%', self::vars('turn')),
            BotText::WheelFailed->value => self::message('جایزه داده نشد', 'وقتی گردونه روی جایزه‌ای ایستاد ولی جایزه همان لحظه داده نشد (مثلا سرور در دسترس نبود)؛ این چرخش حساب نمی‌شود.', '⚠️ گردونه روی «%prize%» ایستاد، ولی جایزه الان داده نشد: %reason%%refund%

این چرخش حساب نشد؛ می‌توانید دوباره بچرخانید.', self::vars('prize', 'reason', 'refund')),
            BotText::WheelRefunded->value => self::part('برگشت هزینه چرخش', 'مقدار %refund%، وقتی چرخشی که جایزه‌اش داده نشد هزینه داشت.', '
💰 هزینه چرخش به کیف پول شما برگشت.'),
        ];
    }

    /** @return array<string, Draft> */
    private static function testAccounts(): array
    {
        return [
            BotText::TestScreen->value => self::message('اکانت تست', 'صفحه «اکانت تست» منوی اصلی (و لینک ?start=usertest و دستور /free)؛ یک دکمه برای هر سرور زیر آن می‌آید.', '🧪 <b>اکانت تست رایگان</b>

پیش از خرید، سرویس ما را رایگان امتحان کنید. یکی از سرورهای زیر را انتخاب کنید:

%servers%', self::vars('servers')),
            BotText::TestServerLine->value => self::part('سرور اکانت تست', 'مقدار %servers% در صفحه اکانت تست، یک خط برای هر سرور.', '🌍 <b>%server%</b>: %traffic%، %duration%%hold%', self::vars('server', 'traffic', 'duration', 'hold', traffic: 'حجم اکانت تست این سرور', duration: 'مدت اکانت تست این سرور')),
            BotText::TestOnHold->value => self::part('از اولین اتصال', 'مقدار %hold% در خط سرور، وقتی مدت اکانت تست آن سرور از اولین اتصال شمرده می‌شود.', ' (از اولین اتصال)'),
            BotText::TestOff->value => self::message('اکانت تست خاموش', 'صفحه «اکانت تست»، وقتی اکانت تست در تنظیمات خاموش است.', 'اکانت تست فعلا فعال نیست.'),
            BotText::TestNewcomersOnly->value => self::message('اکانت تست فقط برای کاربران جدید', 'وقتی اکانت تست فقط برای کسانی است که هنوز سرویسی نخریده‌اند و این مشتری خریده است؛ دکمه «خرید اشتراک» زیر آن می‌آید.', '🧪 اکانت تست ویژه کاربرانی است که هنوز سرویسی نخریده‌اند. برای ادامه، یکی از پلن‌ها را انتخاب کنید.'),
            BotText::TestUsed->value => self::message('اکانت تست گرفته شده', 'وقتی مشتری همه اکانت‌های تستی را که به او می‌رسد گرفته است و دیگر اکانت تستی به او نمی‌رسد؛ دکمه «خرید اشتراک» زیر آن می‌آید.', '🧪 اکانت تست خود را پیش‌تر گرفته‌اید. اگر از سرویس راضی بودید، یکی از پلن‌ها را انتخاب کنید.'),
            BotText::TestUsedUntil->value => self::message('اکانت تست بعدی', 'وقتی سهمیه اکانت تست مشتری در این دوره تمام شده است؛ زمانی که اکانت تست بعدی ممکن می‌شود در آن است و دکمه «خرید اشتراک» زیر آن می‌آید.', '🧪 سهمیه اکانت تست شما فعلا تمام شده است؛ اکانت تست بعدی از %next_test% ممکن می‌شود.
تا آن موقع می‌توانید یکی از پلن‌ها را انتخاب کنید.', self::vars('next_test')),
            BotText::TestDailyLimit->value => self::message('سقف روزانه اکانت تست', 'وقتی فروشگاه امروز به اندازه سقف روزانه اکانت تست داده است؛ دکمه «خرید اشتراک» زیر آن می‌آید.', '⏳ ظرفیت اکانت تست امروز تمام شده است؛ فردا دوباره سر بزنید یا یکی از پلن‌ها را انتخاب کنید.'),
            BotText::TestNoServer->value => self::message('سروری برای اکانت تست نیست', 'وقتی هیچ سروری الان اکانت تست نمی‌دهد: هیچ‌کدام برای تست روشن نیست یا جای کلاینت تازه ندارد، یا در ربات نماینده حجم باقی‌مانده نماینده به اندازه اکانت تست نیست. وقتی مشتری سروری را بزند که دیگر اکانت تست نمی‌دهد هم، اگر سرور دیگری نمانده باشد همین پیام می‌آید و اگر مانده باشد صفحه اکانت تست با همان سرورها.', 'فعلا سروری برای اکانت تست در دسترس نیست؛ کمی بعد دوباره سر بزنید.'),
            BotText::TestBuyButton->value => self::button('دکمه خرید اشتراک', 'زیر پیام‌هایی که می‌گویند اکانت تست به مشتری نمی‌رسد؛ لیست پلن‌ها را باز می‌کند.', '🔐 خرید اشتراک'),
            BotText::TestDelivered->value => self::caption('تحویل اکانت تست', 'بعد از ساخت اکانت تست؛ همراه عکس کد QR لینک، وقتی ساخت آن روشن است، و دکمه «مشاهده سرویس» زیر آن.', '🧪 <b>اکانت تست رایگان شما آماده است</b>

👤 نام کاربری سرویس: <code>%client%</code>
‏🇺🇳 لوکیشن: %server%
🗜️ حجم سرویس: %traffic%
📅 تاریخ اتمام: %expires%

🔗 لینک اتصال:

<code>%subscription%</code>

💡 برای کپی، روی لینک بزنید.', self::vars(...self::SERVICE), ['subscription']),
            BotText::TestUnsent->value => self::message('اکانت تست ارسال نشد', 'وقتی اکانت تست ساخته شد ولی پیام تحویل آن ارسال نشد؛ اکانت از سهمیه مشتری کم شده است و دکمه «مشاهده سرویس» زیر آن می‌آید.', '⚠️ اکانت تست شما ساخته شد ولی ارسال آن ناموفق بود؛ آن را با دکمه زیر ببینید.'),
            BotText::TestFailed->value => self::message('ساخت اکانت تست ناموفق', 'وقتی ساخت اکانت تست روی سرور ناموفق بود؛ چیزی از سهمیه مشتری کم نمی‌شود.', '⚠️ ساخت اکانت تست الان ممکن نشد؛ کمی بعد دوباره امتحان کنید.'),
            BotText::TestPlanName->value => self::part('نام سرویس اکانت تست', 'مقدار %plan% برای اکانت تست، که پلنی ندارد: در صفحه سرویس و پیام‌های لینک آن.', 'اکانت تست'),
        ];
    }

    /** @return array<string, Draft> */
    private static function loyalty(): array
    {
        return [
            BotText::LoyaltyTierLine->value => self::part('سطح وفاداری در کیف پول', 'زیر موجودی در صفحه کیف پول، برای مشتری‌ای که روی یکی از سطح‌های وفاداری است؛ بدون سطح، این خط نمی‌آید.', '

🏅 سطح شما: <b>%loyalty_tier%</b>%tier_climb%', self::vars('loyalty_tier', 'tier_climb', 'tier_discount', 'tier_cashback')),
            BotText::LoyaltyTierClimb->value => self::part('تا سطح بعدی', 'مقدار %tier_climb% کنار سطح مشتری، تا وقتی سطح بالاتری هست.', ' — %to_next_tier% تا سطح %next_tier%', self::vars('to_next_tier', 'next_tier')),
            BotText::LoyaltyNoTier->value => self::part('هنوز بدون سطح', 'زیر موجودی در صفحه کیف پول، برای مشتری‌ای که هنوز به اولین سطح وفاداری نرسیده است.', '

🏅 با %to_next_tier% خرید دیگر به سطح <b>%next_tier%</b> می‌رسید.', self::vars('to_next_tier', 'next_tier')),
            BotText::LoyaltyTierUp->value => self::message('رسیدن به سطح بالاتر', 'وقتی تحویل یک خرید، مشتری را به سطح وفاداری بالاتری می‌رساند.', '🎉 تبریک! سطح وفاداری شما به <b>%loyalty_tier%</b> رسید.
%tier_perks%', self::vars('loyalty_tier', 'tier_perks', 'tier_discount', 'tier_cashback'), ['loyalty_tier']),
            BotText::LoyaltyPerkDiscount->value => self::part('تخفیف سطح', 'یک خط از %tier_perks%، وقتی سطح تخفیف دارد.', '🏷️ %tier_discount% تخفیف روی خریدهای شما', self::vars('tier_discount')),
            BotText::LoyaltyPerkCashback->value => self::part('کش‌بک سطح', 'یک خط از %tier_perks%، وقتی سطح کش‌بک اضافه دارد.', '💸 %tier_cashback% کش‌بک اضافه از هر پرداخت', self::vars('tier_cashback')),
            BotText::CashbackEarned->value => self::message('کش‌بک', 'وقتی سفارش تحویل شد و بخشی از مبلغش به عنوان کش‌بک به کیف پول مشتری برگشت.', '💸 کش‌بک سفارش #%order%: <b>%cashback_amount%</b> به کیف پول شما اضافه شد.
موجودی کیف پول: <b>%balance%</b>', self::vars('order', 'cashback_amount', 'balance'), ['cashback_amount']),
            BotText::CashbackTakenBack->value => self::part('کسر کش‌بک از مبلغ برگشتی', 'زیر پیام برگشت مبلغ به کیف پول، وقتی کش‌بک آن سفارش از مبلغ برگشتی کم شد.', '
💸 کش‌بکی که این سفارش گرفته بود (%cashback_amount%) از مبلغ برگشتی کم شد.', self::vars('cashback_amount')),
            BotText::LotteryWon->value => self::message('برنده قرعه‌کشی شبانه', 'وقتی مشتری در قرعه‌کشی شبانه خریداران برنده می‌شود و جایزه‌اش داده می‌شود.', '🎉 تبریک! شما برنده قرعه‌کشی شبانه خریداران شدید.
🎁 جایزه شما: <b>%draw_prize%</b>', self::vars('draw_prize'), ['draw_prize']),
        ];
    }

    /**
     * A text sent as a message (Telegram HTML).
     *
     * @param array<string, string|null> $variables vars()
     * @param list<string> $required The variables the wording must keep
     * @return Draft
     */
    private static function message(string $title, string $description, string $default, array $variables = [], array $required = []): array
    {
        return [TextKind::Message, $title, $description, $default, $variables, $required];
    }

    /**
     * The caption of a service's QR card (Telegram HTML).
     *
     * @param array<string, string|null> $variables
     * @param list<string> $required
     * @return Draft
     */
    private static function caption(string $title, string $description, string $default, array $variables = [], array $required = []): array
    {
        return [TextKind::Caption, $title, $description, $default, $variables, $required];
    }

    /**
     * A popup or toast answering a tap (plain text).
     *
     * @param array<string, string|null> $variables
     * @param list<string> $required
     * @return Draft
     */
    private static function popup(string $title, string $description, string $default, array $variables = [], array $required = []): array
    {
        return [TextKind::Popup, $title, $description, $default, $variables, $required];
    }

    /**
     * A button's label (plain text, one line).
     *
     * @param array<string, string|null> $variables
     * @param list<string> $required
     * @return Draft
     */
    private static function button(string $title, string $description, string $default, array $variables = [], array $required = []): array
    {
        return [TextKind::Button, $title, $description, $default, $variables, $required];
    }

    /**
     * A piece another text takes in as a variable, or a hint under a screen; the admin may empty it.
     *
     * @param array<string, string|null> $variables
     * @param list<string> $required
     * @return Draft
     */
    private static function part(string $title, string $description, string $default, array $variables = [], array $required = []): array
    {
        return [TextKind::Part, $title, $description, $default, $variables, $required];
    }

    /**
     * The variables a text offers, in the order the screen shows them, each meaning what VARIABLES says — or what a
     * named argument says for this text (`self::vars('plan', 'traffic', traffic: 'حجم پلن')`).
     *
     * @return array<string, string|null>
     */
    private static function vars(string ...$names): array
    {
        $variables = [];
        foreach ($names as $key => $name) {
            if (is_int($key)) {
                $variables[$name] = null;
            } else {
                $variables[$key] = $name;
            }
        }

        return $variables;
    }
}
