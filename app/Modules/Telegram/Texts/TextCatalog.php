<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Texts;

use App\Modules\Bots\CurrentBot;

/**
 * What every BotText is: its group on the «متن‌های ربات» screen, its kind, the Persian title and the line
 * saying where the customer meets it, the shop's own wording (Telegram HTML, `%variables%`), and the
 * variables the code fills in it — each variable means one thing everywhere (VARIABLES). Emoji keep one
 * form per glyph: the emoji-presentation form (with U+FE0F) for the symbols that need it (⛔️ 🛍️ 🗜️ ⚠️ ⚙️
 * ☎️ ♻️ ↩️ ✏️ ⬅️), the bare code point for those that are emoji by themselves. A group only the main bot says
 * (the agency's) is not an agent's shop's (groups(), says()).
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
        'reminders' => 'یادآوری',
        'wallet' => 'کیف پول',
        'referral' => 'زیرمجموعه‌گیری',
        'agency' => 'نمایندگی',
        'account' => 'حساب وب‌سایت',
        'support' => 'پشتیبانی',
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
        'terms' => ['شرط پورسانت (یکی از دو متن شرط پورسانت)', 'دوستانتان را با لینک زیر به ربات دعوت کنید؛ از هر پرداخت موفق آن‌ها ۱۰٪ به عنوان پورسانت به کیف پول شما اضافه می‌شود.'],
        'link' => ['لینک دعوت مشتری', 'https://t.me/amo_shop_bot?start=ref_k7m2p9qa'],
        'referrals' => ['تعداد زیرمجموعه‌ها', '۱۲'],
        'earned' => ['جمع پورسانت‌های دریافتی', '۸۴٬۰۰۰ تومان'],
        'rate' => ['درصد پورسانت، از تنظیمات ربات', '۱۰٪'],
        'commission' => ['مبلغ پورسانت', '۱۲٬۰۰۰ تومان'],
        'paid' => ['مبلغی که زیرمجموعه پرداخت کرد', '۱۲۰٬۰۰۰ تومان'],
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
    ];

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

    /** @return array<string, TextSpec> */
    private static function build(): array
    {
        return self::general() + self::gates() + self::shop() + self::checkout() + self::delivery() + self::services() + self::service()
            + self::rotation() + self::supportActions() + self::renewal() + self::reminders() + self::wallet() + self::referral() + self::agency() + self::account()
            + self::support();
    }

    /** @return array<string, TextSpec> */
    private static function general(): array
    {
        $group = 'general';

        return [
            BotText::Welcome->value => new TextSpec($group, TextKind::Message, 'خوش‌آمدگویی', 'وقتی مشتری ربات را استارت می‌کند و در تلگرام نام دارد؛ منوی اصلی همراه آن می‌آید.', "سلام %name% 👋\nخوش آمدید! یکی از گزینه‌های زیر را انتخاب کنید.", self::vars('name')),
            BotText::WelcomeNameless->value => new TextSpec($group, TextKind::Message, 'خوش‌آمدگویی بدون نام', 'همان خوش‌آمدگویی، برای حسابی که در تلگرام نامی ندارد.', "سلام 👋\nخوش آمدید! یکی از گزینه‌های زیر را انتخاب کنید."),
            BotText::MenuPrompt->value => new TextSpec($group, TextKind::Message, 'همراه منوی اصلی', 'متنی که منوی اصلی همراه آن دوباره فرستاده می‌شود.', 'یکی از گزینه‌های زیر را انتخاب کنید:'),
            BotText::Unknown->value => new TextSpec($group, TextKind::Message, 'پیام نامفهوم', 'وقتی مشتری چیزی می‌فرستد که ربات برایش پاسخی ندارد؛ منوی اصلی همراه آن می‌آید.', 'متوجه نشدم. لطفا از منوی زیر استفاده کنید.'),
            BotText::Error->value => new TextSpec($group, TextKind::Popup, 'خطای ربات', 'وقتی در پاسخ به مشتری خطایی پیش می‌آید؛ به صورت پیام یا اعلان کوتاه.', 'مشکلی پیش آمد. لطفا کمی بعد دوباره تلاش کنید.'),
            BotText::Banned->value => new TextSpec($group, TextKind::Popup, 'حساب مسدود', 'پاسخ ربات به هر پیام یا دکمه مشتری مسدودشده.', 'حساب شما مسدود شده است. اگر فکر می‌کنید اشتباهی رخ داده با پشتیبانی تماس بگیرید.'),
            BotText::BotOff->value => new TextSpec($group, TextKind::Popup, 'ربات خاموش', 'وقتی ربات از تنظیمات ربات خاموش است؛ مشتری به جای هر پاسخی همین را می‌بیند.', '⛔️ ربات فعلا غیرفعال است. لطفا کمی بعد دوباره سر بزنید.'),
            BotText::Tutorial->value => new TextSpec($group, TextKind::Message, 'آموزش', 'صفحه «آموزش» منوی اصلی؛ راهنمای اتصال و استفاده از سرویس‌ها را اینجا بنویسید.', '📚 <b>آموزش اتصال</b>

۱. یک برنامه اتصال نصب کنید که لینک اشتراک می‌گیرد.
۲. در «سرویس‌های من» سرویس خود را باز کنید و «لینک اشتراک» را بزنید؛ لینک با یک بار زدن کپی می‌شود.
۳. لینک را در برنامه اضافه کنید (معمولا با گزینه افزودن از کلیپ‌بورد) و وصل شوید.

اگر سوالی دارید یا وصل نشدید، از «پشتیبانی» تیکت بفرستید؛ پاسخ همین‌جا به شما می‌رسد.'),
            BotText::Cancel->value => new TextSpec($group, TextKind::Button, 'دکمه انصراف', 'زیر پرسش تغییر لینک؛ کارت پیش‌نویس ارسال همگانی، که فقط مدیرهای ربات می‌بینند، هم همین دکمه را دارد.', '❌ انصراف'),
            BotText::Back->value => new TextSpec($group, TextKind::Button, 'دکمه بازگشت', 'دکمه بازگشت زیر صفحه‌های ربات.', '⬅️ بازگشت'),
        ];
    }

    /** @return array<string, TextSpec> */
    private static function gates(): array
    {
        $group = 'gates';

        return [
            BotText::PhonePrompt->value => new TextSpec($group, TextKind::Message, 'درخواست شماره موبایل', 'وقتی تایید شماره موبایل روشن است و مشتری هنوز شماره‌اش را نفرستاده.', '📱 برای استفاده از ربات باید شماره موبایل خود را تایید کنید.
روی دکمه زیر بزنید تا شماره‌تان از تلگرام ارسال شود؛ لازم نیست چیزی تایپ کنید.'),
            BotText::PhoneButton->value => new TextSpec($group, TextKind::Button, 'دکمه ارسال شماره', 'دکمه‌ای که شماره تلگرام مشتری را می‌فرستد.', '📱 ارسال شماره موبایل'),
            BotText::PhoneNotYours->value => new TextSpec($group, TextKind::Message, 'شماره متعلق به دیگری', 'وقتی مشتری شماره حساب دیگری را می‌فرستد.', 'این شماره متعلق به حساب تلگرام شما نیست. لطفا از دکمه زیر استفاده کنید تا شماره خودتان ارسال شود.'),
            BotText::PhoneVerified->value => new TextSpec($group, TextKind::Message, 'تایید شماره', 'بعد از اینکه شماره مشتری تایید شد.', '✅ شماره شما تایید شد.'),
            BotText::JoinPrompt->value => new TextSpec($group, TextKind::Message, 'درخواست عضویت', 'وقتی عضویت در کانال‌ها لازم است؛ دکمه هر کانال و «عضو شدم» زیر آن می‌آید.', '📢 برای استفاده از ربات ابتدا در کانال‌های زیر عضو شوید و بعد روی «عضو شدم» بزنید:'),
            BotText::JoinButton->value => new TextSpec($group, TextKind::Button, 'دکمه «عضو شدم»', 'زیر درخواست عضویت، برای بررسی دوباره.', '✅ عضو شدم'),
            BotText::JoinChannel->value => new TextSpec($group, TextKind::Button, 'دکمه کانال', 'زیر درخواست عضویت، یک دکمه برای هر کانالی که مشتری هنوز عضو آن نیست.', '📢 %title%', self::vars('title'), ['title']),
            BotText::JoinStillMissing->value => new TextSpec($group, TextKind::Popup, 'هنوز عضو نیست', 'اعلان وقتی مشتری «عضو شدم» را می‌زند ولی هنوز عضو یکی از کانال‌ها نیست.', 'هنوز عضو «%title%» نشده‌اید.', self::vars('title')),
            BotText::JoinDone->value => new TextSpec($group, TextKind::Popup, 'تایید عضویت', 'اعلان کوتاه بعد از اینکه عضویت مشتری تایید شد.', '✅ عضویت شما تایید شد.'),
        ];
    }

    /** @return array<string, TextSpec> */
    private static function shop(): array
    {
        $group = 'shop';

        return [
            BotText::CategoriesTitle->value => new TextSpec($group, TextKind::Message, 'انتخاب دسته', 'بالای دکمه‌های دسته‌ها در «خرید اشتراک».', 'یک دسته انتخاب کنید:'),
            BotText::CategoryOther->value => new TextSpec($group, TextKind::Button, 'دسته «سایر پلن‌ها»', 'نام دسته پلن‌هایی که دسته‌ای ندارند.', 'سایر پلن‌ها'),
            BotText::CategoryEmpty->value => new TextSpec($group, TextKind::Message, 'دسته خالی', 'وقتی دسته‌ای باز می‌شود که پلن قابل فروشی ندارد.', 'در دسته «%category%» فعلا پلنی برای فروش نیست.', self::vars('category')),
            BotText::PlansTitle->value => new TextSpec($group, TextKind::Message, 'انتخاب پلن', 'بالای دکمه‌های پلن‌ها.', 'یک پلن انتخاب کنید:'),
            BotText::PlansEmpty->value => new TextSpec($group, TextKind::Message, 'پلنی نیست', 'وقتی هیچ پلنی برای فروش نیست.', 'در حال حاضر پلنی موجود نیست. لطفا بعدا مراجعه کنید.'),
            BotText::CategoryPlans->value => new TextSpec($group, TextKind::Message, 'انتخاب پلن در دسته', 'بالای دکمه‌های پلن‌های یک دسته.', '<b>%category%</b>

یک پلن انتخاب کنید:', self::vars('category')),
            BotText::PlanButton->value => new TextSpec($group, TextKind::Button, 'دکمه پلن', 'هر پلن در لیست پلن‌ها یک دکمه است.', '%plan% — %traffic% / %duration% — %price%', self::vars('plan', 'traffic', 'duration', 'price', traffic: 'حجم پلن', duration: 'مدت پلن'), ['plan']),
            BotText::PlanDetails->value => new TextSpec($group, TextKind::Message, 'جزئیات پلن', 'بعد از انتخاب پلن؛ «انتخاب سرور» یا «سروری در دسترس نیست» زیر آن می‌آید.', '<b>%plan%</b>%description%

🗜️ حجم سرویس: %traffic%
⏳ مدت: %duration%
📱 دستگاه: %devices%
💰 قیمت: <b>%price%</b>', self::vars('plan', 'description', 'traffic', 'duration', 'devices', 'price', traffic: 'حجم پلن', duration: 'مدت پلن')),
            BotText::PlanPickServer->value => new TextSpec($group, TextKind::Part, 'انتخاب سرور', 'زیر جزئیات پلن، بالای دکمه‌های سرورها.', 'سرور را انتخاب کنید:'),
            BotText::ServerButton->value => new TextSpec($group, TextKind::Button, 'دکمه سرور', 'زیر جزئیات پلن، یک دکمه برای هر سرور.', '🌍 %server%', self::vars('server'), ['server']),
            BotText::PlanNoServer->value => new TextSpec($group, TextKind::Message, 'سروری در دسترس نیست', 'وقتی هیچ سرور این پلن فعلا قابل فروش نیست.', 'در حال حاضر سروری برای این پلن در دسترس نیست. لطفا بعدا دوباره تلاش کنید.'),
            BotText::PlanGone->value => new TextSpec($group, TextKind::Message, 'پلن دیگر فروخته نمی‌شود', 'وقتی پلنی که مشتری انتخاب کرده دیگر فروخته نمی‌شود.', 'این پلن دیگر در دسترس نیست.'),
        ];
    }

    /** @return array<string, TextSpec> */
    private static function checkout(): array
    {
        $group = 'checkout';

        return [
            BotText::Checkout->value => new TextSpec($group, TextKind::Message, 'صفحه پرداخت', 'بعد از انتخاب سرور؛ دکمه روش‌های پرداخت زیر آن می‌آید. سفارش وقتی ثبت می‌شود که مشتری روش پرداخت را انتخاب کند.', '🧾 <b>خرید اشتراک</b>

پلن: %plan%
سرور: %server%
مبلغ: <b>%amount%</b>

روش پرداخت را انتخاب کنید:', self::vars('plan', 'server') + ['amount' => 'مبلغ سفارش']),
            BotText::TopupCheckout->value => new TextSpec($group, TextKind::Message, 'صفحه پرداخت شارژ کیف پول', 'همان صفحه پرداخت، برای شارژ کیف پول.', '🧾 <b>شارژ کیف پول</b>

مبلغ: <b>%amount%</b>

روش پرداخت را انتخاب کنید:', ['amount' => 'مبلغ شارژ']),
            BotText::CheckoutNoGateway->value => new TextSpec($group, TextKind::Message, 'روش پرداختی فعال نیست', 'وقتی هیچ روش پرداختی فعال نیست.', 'هیچ روش پرداختی فعال نیست. لطفا با پشتیبانی تماس بگیرید.'),
            BotText::OrderNotPending->value => new TextSpec($group, TextKind::Message, 'سفارش دیگر باز نیست', 'وقتی مشتری سفارشی را پرداخت می‌کند که پرداخت یا لغو شده است.', 'این سفارش دیگر قابل پرداخت نیست.'),
            BotText::PayInsufficient->value => new TextSpec($group, TextKind::Message, 'موجودی کافی نیست', 'وقتی مشتری با کیف پول پرداخت می‌کند و موجودی کم است؛ دکمه افزایش موجودی زیر آن می‌آید.', 'موجودی کیف پول کافی نیست.
موجودی: <b>%balance%</b>
مبلغ سفارش: <b>%amount%</b>
کمبود: <b>%missing%</b>', self::vars('balance', 'missing') + ['amount' => 'مبلغ سفارش']),
            BotText::PayFailed->value => new TextSpec($group, TextKind::Message, 'پرداخت ناموفق', 'وقتی پرداخت انجام نمی‌شود.', 'پرداخت انجام نشد: %reason%', self::vars('reason')),
            BotText::CardInstructions->value => new TextSpec($group, TextKind::Message, 'راهنمای کارت به کارت', 'بعد از انتخاب روش کارت به کارت؛ «درخواست ارسال رسید» پشت آن می‌آید.', 'لطفا مبلغ %amount% را به شماره کارت زیر واریز کنید:
<code>%card%</code>
به نام <b>%holder%</b>

%instructions%', self::vars('card', 'holder', 'instructions') + ['amount' => 'مبلغ سفارش'], ['card']),
            BotText::PayInstructionsSuffix->value => new TextSpec($group, TextKind::Part, 'درخواست ارسال رسید', 'انتهای راهنمای کارت به کارت.', '

بعد از واریز، عکس رسید را همین‌جا بفرستید.'),
            BotText::ReceiptWaiting->value => new TextSpec($group, TextKind::Message, 'منتظر رسید', 'وقتی ربات منتظر عکس رسید است و مشتری چیز دیگری می‌نویسد.', 'لطفا عکس رسید پرداخت را بفرستید، یا از منوی پایین گزینه دیگری را انتخاب کنید.'),
            BotText::ReceiptNotImage->value => new TextSpec($group, TextKind::Message, 'رسید باید عکس باشد', 'وقتی مشتری به جای عکس رسید، فایل یا پیام دیگری می‌فرستد.', 'فقط عکس رسید پذیرفته می‌شود. لطفا اسکرین‌شات یا عکس رسید واریز را بفرستید.'),
            BotText::ReceiptReceived->value => new TextSpec($group, TextKind::Message, 'دریافت رسید', 'بعد از دریافت رسید، وقتی رسید فقط دستی تایید می‌شود.', 'رسید دریافت شد ✅
بعد از تایید توسط پشتیبانی، %outcome%.', self::vars('outcome')),
            BotText::ReceiptReceivedTimed->value => new TextSpec($group, TextKind::Message, 'دریافت رسید با مهلت', 'بعد از دریافت رسید، وقتی روش پرداخت مهلت تایید خودکار دارد.', 'رسید دریافت شد ✅
بعد از بررسی، %outcome%؛ حداکثر تا %wait% دیگر.', self::vars('outcome', 'wait')),
            BotText::ReceiptOutcomeSubscription->value => new TextSpec($group, TextKind::Part, 'نتیجه رسید: اشتراک', 'مقدار %outcome% در پیام دریافت رسید و پیام «پرداخت انجام شد»، وقتی سفارش خرید اشتراک است.', 'اشتراک شما فعال و همین‌جا ارسال می‌شود'),
            BotText::ReceiptOutcomeRenewal->value => new TextSpec($group, TextKind::Part, 'نتیجه رسید: تمدید', 'مقدار %outcome% در پیام دریافت رسید و پیام «پرداخت انجام شد»، وقتی سفارش تمدید سرویس است.', 'سرویس شما تمدید می‌شود و همین‌جا خبر می‌دهیم'),
            BotText::ReceiptOutcomeTopup->value => new TextSpec($group, TextKind::Part, 'نتیجه رسید: شارژ کیف پول', 'مقدار %outcome% در پیام دریافت رسید و پیام «پرداخت انجام شد»، وقتی سفارش شارژ کیف پول است.', 'کیف پول شما شارژ می‌شود و همین‌جا خبر می‌دهیم'),
            BotText::PayProcessing->value => new TextSpec($group, TextKind::Message, 'پرداخت انجام شد', 'وقتی پرداخت از کیف پول انجام شد ولی تحویل سفارش هنوز در جریان است؛ نتیجه‌اش بعدا همین‌جا فرستاده می‌شود.', '✅ پرداخت سفارش #%order% انجام شد؛ %outcome%.', self::vars('order', outcome: 'نتیجه پرداخت (یکی از متن‌های نتیجه رسید)')),
        ];
    }

    /** @return array<string, TextSpec> */
    private static function delivery(): array
    {
        $group = 'delivery';

        return [
            BotText::PaySuccess->value => new TextSpec($group, TextKind::Caption, 'تحویل سرویس', 'بعد از پرداخت موفق؛ همراه عکس کد QR لینک، وقتی ساخت آن روشن است.', '✅ سرویس با موفقیت ایجاد شد

👤 نام کاربری سرویس: <code>%client%</code>
🌿 نام سرویس: %plan%
‏🇺🇳 لوکیشن: %server%
⏳ مدت زمان: %duration%
🗜️ حجم سرویس: %traffic%

🔗 لینک اتصال:

<code>%subscription%</code>', self::vars(...self::SERVICE), ['subscription']),
            BotText::PayProvisionFailed->value => new TextSpec($group, TextKind::Message, 'ساخت سرویس ناموفق', 'وقتی پرداخت انجام شد ولی ساخت سرویس روی سرور ناموفق بود.', 'پرداخت ثبت شد ولی ساخت اشتراک روی سرور ناموفق بود. پشتیبانی به‌زودی آن را فعال می‌کند؛ نیازی به پرداخت دوباره نیست.'),
            BotText::ReceiptRejected->value => new TextSpec($group, TextKind::Message, 'رد رسید', 'وقتی پشتیبانی رسید را رد می‌کند؛ در پاسخ به همان رسید.', '❌ رسید شما برای سفارش #%order% تایید نشد.%note%

می‌توانید از منو دوباره خرید کنید یا با پشتیبانی در تماس باشید.', self::vars('order', 'note')),
            BotText::OrderCancelledByAdmin->value => new TextSpec($group, TextKind::Message, 'لغو سفارش توسط پشتیبانی', 'وقتی پشتیبانی سفارشی را لغو می‌کند؛ در پاسخ به رسید، اگر فرستاده شده باشد.', '❌ سفارش #%order% توسط پشتیبانی لغو شد.%note%

اگر مبلغی واریز کرده‌اید، با پشتیبانی در تماس باشید.', self::vars('order', 'note')),
            BotText::PaymentRefunded->value => new TextSpec($group, TextKind::Message, 'برگشت مبلغ به کیف پول', 'وقتی پشتیبانی مبلغ پرداخت را به کیف پول مشتری برمی‌گرداند.', '↩️ مبلغ %amount% بابت سفارش #%order% به کیف پول شما برگشت داده شد.%note%
موجودی: <b>%balance%</b>', self::vars('order', 'note', 'balance') + ['amount' => 'مبلغ برگشتی']),
            BotText::TopupRefunded->value => new TextSpec($group, TextKind::Message, 'برگشت شارژ کیف پول', 'وقتی پشتیبانی پرداخت یک شارژ کیف پول را برمی‌گرداند و مبلغ شارژ از کیف پول مشتری کم می‌شود.', '↩️ پرداخت %amount% برای شارژ کیف پول (سفارش #%order%) برگشت داده شد و این مبلغ از کیف پول شما کم شد.%note%
موجودی: <b>%balance%</b>', self::vars('order', 'note', 'balance') + ['amount' => 'مبلغ شارژ']),
            BotText::TopupRefundedUndelivered->value => new TextSpec($group, TextKind::Message, 'برگشت شارژی که نرسیده بود', 'وقتی پشتیبانی پرداخت شارژ کیف پولی را برمی‌گرداند که هنوز به کیف پول مشتری نرسیده بود؛ چیزی از موجودی کم نمی‌شود.', '↩️ پرداخت %amount% برای شارژ کیف پول (سفارش #%order%) برگشت داده شد. این شارژ به کیف پول شما نرسیده بود و موجودی شما تغییری نکرد.%note%', self::vars('order', 'note') + ['amount' => 'مبلغ شارژ']),
            BotText::AdminNote->value => new TextSpec($group, TextKind::Part, 'توضیح پشتیبانی', 'مقدار %note% در پیام‌های رد رسید، لغو، برگشت مبلغ و کارهای پشتیبانی روی سرویس؛ اگر پشتیبانی توضیحی ننوشته باشد، نمی‌آید.', '
توضیح پشتیبانی: %comment%', self::vars('comment'), ['comment']),
            BotText::PaymentReminder->value => new TextSpec($group, TextKind::Message, 'یادآوری پرداخت', 'وقتی پشتیبانی یادآوری پرداخت می‌فرستد؛ دکمه‌های «ارسال رسید» و «پرداخت دوباره» زیر آن می‌آید.', '⏳ پرداخت سفارش #%order% (%amount%) هنوز کامل نشده است.
اگر واریز کرده‌اید، عکس رسید را بفرستید؛ وگرنه می‌توانید دوباره پرداخت کنید.', self::vars('order') + ['amount' => 'مبلغ پرداخت']),
            BotText::PaymentReminderRejected->value => new TextSpec($group, TextKind::Message, 'یادآوری پرداخت بعد از رد رسید', 'همان یادآوری، برای پرداختی که رسیدش رد شده است؛ فقط دکمه «پرداخت دوباره» زیر آن می‌آید.', '⏳ پرداخت سفارش #%order% (%amount%) هنوز کامل نشده است.
رسید قبلی تایید نشد؛ می‌توانید دوباره پرداخت کنید.', self::vars('order') + ['amount' => 'مبلغ پرداخت']),
            BotText::PayAgain->value => new TextSpec($group, TextKind::Button, 'دکمه پرداخت دوباره', 'زیر یادآوری پرداخت.', '🔄 پرداخت دوباره'),
            BotText::SendReceipt->value => new TextSpec($group, TextKind::Button, 'دکمه ارسال رسید', 'زیر یادآوری پرداخت.', '📎 ارسال رسید'),
        ];
    }

    /** @return array<string, TextSpec> */
    private static function services(): array
    {
        $group = 'services';

        return [
            BotText::SubscriptionsTitle->value => new TextSpec($group, TextKind::Message, 'لیست سرویس‌ها', 'بالای دکمه‌های سرویس‌ها در «سرویس‌های من».', '🛍️ سرویس‌های فعال شما
برای دیدن جزئیات و مدیریت، روی نام سرویس بزنید.'),
            BotText::SubscriptionsPage->value => new TextSpec($group, TextKind::Part, 'شماره صفحه', 'زیر لیست سرویس‌ها و لیست تیکت‌ها، وقتی بیش از یک صفحه است.', '
صفحه %page% از %pages%', self::vars('page', 'pages')),
            BotText::SubscriptionsEmpty->value => new TextSpec($group, TextKind::Message, 'سرویسی نیست', 'وقتی مشتری سرویس فعالی ندارد.', 'شما هنوز سرویس فعالی ندارید.'),
            BotText::ServiceButton->value => new TextSpec($group, TextKind::Button, 'دکمه سرویس', 'هر سرویس در لیست سرویس‌ها — و در انتخاب سرویس تیکت جدید — یک دکمه است.', '✨ %client% ✨', self::vars('client'), ['client']),
            BotText::PageNext->value => new TextSpec($group, TextKind::Button, 'دکمه صفحه بعد', 'زیر لیست سرویس‌ها و لیست تیکت‌ها.', 'بعدی'),
            BotText::PagePrev->value => new TextSpec($group, TextKind::Button, 'دکمه صفحه قبل', 'زیر لیست سرویس‌ها و لیست تیکت‌ها.', 'قبلی'),
        ];
    }

    /** @return array<string, TextSpec> */
    private static function service(): array
    {
        $group = 'service';

        return [
            BotText::ServiceDetails->value => new TextSpec($group, TextKind::Message, 'صفحه سرویس', 'وقتی مشتری یک سرویس را باز می‌کند؛ اطلاعات همان لحظه از پنل خوانده می‌شود.', '📊 وضعیت سرویس: %status%
👤 نام کاربری سرویس: <code>%client%</code>
🌿 نام سرویس: %plan%
‏🇺🇳 لوکیشن: %server%

🗜️ حجم سرویس: %traffic%
📤 حجم مصرفی: %used%
📥 حجم باقی‌مانده: %remaining%

📅 تاریخ اتمام: %expires%

📶 آخرین اتصال: %last_online%
📡 وضعیت اتصال: %connection%', self::vars('status', 'client', 'plan', 'server', 'traffic', 'used', 'remaining', 'expires', 'last_online', 'connection')),
            BotText::ServiceRotateHint->value => new TextSpec($group, TextKind::Part, 'راهنمای تغییر لینک', 'زیر صفحه سرویس، وقتی سرور امکان تغییر لینک دارد.', '💡 برای قطع دسترسی دیگران به این سرویس، روی «تغییر لینک» بزنید.'),
            BotText::ServiceStale->value => new TextSpec($group, TextKind::Part, 'پنل در دسترس نبود', 'زیر صفحه سرویس، وقتی پنل جواب نداد و اطلاعات مربوط به آخرین به‌روزرسانی است.', '⚠️ سرور در دسترس نبود؛ اطلاعات مربوط به آخرین به‌روزرسانی است.'),
            BotText::ServiceStatusActive->value => new TextSpec($group, TextKind::Part, 'وضعیت: فعال', 'مقدار %status% در صفحه سرویس.', '✅ فعال'),
            BotText::ServiceStatusDisabled->value => new TextSpec($group, TextKind::Part, 'وضعیت: غیرفعال', 'مقدار %status% در صفحه سرویس.', '⛔️ غیرفعال'),
            BotText::ServiceStatusExpired->value => new TextSpec($group, TextKind::Part, 'وضعیت: منقضی', 'مقدار %status% در صفحه سرویس.', '⌛ منقضی شده'),
            BotText::ServiceStatusDepleted->value => new TextSpec($group, TextKind::Part, 'وضعیت: حجم تمام شده', 'مقدار %status% در صفحه سرویس.', '⚠️ حجم تمام شده'),
            BotText::ServiceStatusDeleted->value => new TextSpec($group, TextKind::Part, 'وضعیت: حذف شده', 'مقدار %status% در صفحه سرویس.', '❌ حذف شده'),
            BotText::ServiceUnused->value => new TextSpec($group, TextKind::Part, 'مصرف نشده', 'مقدار %used% وقتی سرویس هنوز مصرفی ندارد.', 'مصرف نشده'),
            BotText::ServiceNeverConnected->value => new TextSpec($group, TextKind::Part, 'هنوز متصل نشده', 'مقدار %last_online% وقتی سرویس هنوز وصل نشده است.', 'هنوز متصل نشده'),
            BotText::ServiceOnline->value => new TextSpec($group, TextKind::Part, 'آنلاین', 'مقدار %connection% وقتی سرویس همین حالا وصل است.', '🟢 آنلاین'),
            BotText::ServiceOffline->value => new TextSpec($group, TextKind::Part, 'آفلاین', 'مقدار %connection% وقتی سرویس وصل نیست.', '⚪ آفلاین'),
            BotText::ServiceUnknown->value => new TextSpec($group, TextKind::Part, 'نامشخص', 'وقتی پنل چیزی را نمی‌داند: آخرین اتصال، وضعیت اتصال، یا دلیل پرداخت ناموفق.', 'نامشخص'),
            BotText::ServiceNotFound->value => new TextSpec($group, TextKind::Message, 'سرویس پیدا نشد', 'وقتی سرویسی که مشتری باز می‌کند دیگر وجود ندارد.', 'این سرویس پیدا نشد.'),
            BotText::ServiceInactive->value => new TextSpec($group, TextKind::Popup, 'سرویس فعال نیست', 'وقتی کاری روی سرویسی خواسته می‌شود که فعال نیست.', 'این سرویس فعال نیست.'),
            BotText::ServiceRefreshed->value => new TextSpec($group, TextKind::Popup, 'اطلاعات به‌روز شد', 'اعلان کوتاه بعد از «به‌روزرسانی اطلاعات».', '✅ اطلاعات به‌روز شد'),
            BotText::ServiceRefreshFailed->value => new TextSpec($group, TextKind::Popup, 'اطلاعات به‌روز نشد', 'اعلان وقتی «به‌روزرسانی اطلاعات» به پنل نمی‌رسد؛ صفحه همان‌طور می‌ماند.', '⚠️ ارتباط با سرور برقرار نشد و اطلاعات به‌روز نشد؛ کمی بعد دوباره تلاش کنید.'),
            BotText::ServiceRefresh->value => new TextSpec($group, TextKind::Button, 'دکمه به‌روزرسانی اطلاعات', 'در صفحه سرویس.', '🔄 به‌روزرسانی اطلاعات'),
            BotText::ServiceLink->value => new TextSpec($group, TextKind::Button, 'دکمه لینک اشتراک', 'در صفحه سرویس، و زیر پیام «لینک جدید ارسال نشد».', '🔗 لینک اشتراک'),
            BotText::ServiceRenew->value => new TextSpec($group, TextKind::Button, 'دکمه تمدید سرویس', 'در صفحه سرویس؛ صفحه پرداخت تمدید همان سرویس را باز می‌کند.', '♻️ تمدید سرویس'),
            BotText::ServiceReport->value => new TextSpec($group, TextKind::Button, 'دکمه گزارش اختلال', 'در صفحه سرویس؛ تیکت پشتیبانی درباره همان سرویس باز می‌کند.', '⚠️ ارسال گزارش اختلال'),
            BotText::ServiceRotate->value => new TextSpec($group, TextKind::Button, 'دکمه تغییر لینک', 'در صفحه سرویس، وقتی سرور امکان تغییر لینک دارد.', '⚙️ تغییر لینک'),
            BotText::ServiceBack->value => new TextSpec($group, TextKind::Button, 'دکمه بازگشت به لیست سرویس‌ها', 'پایین صفحه سرویس.', '⬅️ بازگشت به لیست سرویس‌ها'),
            BotText::LinkRequested->value => new TextSpec($group, TextKind::Caption, 'لینک اشتراک', 'بعد از دکمه «لینک اشتراک»؛ همراه عکس کد QR، وقتی ساخت آن روشن است.', '🔗 لینک اشتراک سرویس

👤 نام کاربری سرویس: <code>%client%</code>
🌿 نام سرویس: %plan%
‏🇺🇳 لوکیشن: %server%

<code>%subscription%</code>

💡 برای کپی، روی لینک بزنید.', self::vars(...self::SERVICE), ['subscription']),
        ];
    }

    /** @return array<string, TextSpec> */
    private static function rotation(): array
    {
        $group = 'rotation';

        return [
            BotText::ServiceRotateConfirm->value => new TextSpec($group, TextKind::Message, 'پرسش تغییر لینک', 'پیش از تغییر لینک، برای تایید مشتری؛ دکمه‌های تایید و انصراف زیر آن می‌آید.', '⚠️ با تغییر لینک، لینک فعلی از کار می‌افتد و هر دستگاهی که با آن متصل است قطع می‌شود؛ باید لینک جدید را در دستگاه‌های خود جایگزین کنید.

لینک سرویس «%client%» تغییر کند؟', self::vars('client')),
            BotText::ServiceRotateYes->value => new TextSpec($group, TextKind::Button, 'دکمه تایید تغییر لینک', 'زیر پرسش تغییر لینک.', '✅ بله، تغییر بده'),
            BotText::ServiceRotateExpired->value => new TextSpec($group, TextKind::Popup, 'تایید تغییر لینک گذشته', 'وقتی تایید تغییر لینک دوباره زده می‌شود، یا بعد از اینکه مشتری به بخش دیگری رفته است؛ لینک تغییر نمی‌کند.', 'این تایید دیگر معتبر نیست؛ برای تغییر لینک، از صفحه سرویس دوباره «تغییر لینک» را بزنید.'),
            BotText::ServiceRotated->value => new TextSpec($group, TextKind::Popup, 'لینک تغییر کرد', 'اعلان کوتاه بعد از تغییر لینک.', '✅ لینک سرویس تغییر کرد'),
            BotText::ServiceRotateFailed->value => new TextSpec($group, TextKind::Message, 'تغییر لینک ناموفق', 'وقتی پنل لینک را تغییر نمی‌دهد.', '❌ تغییر لینک انجام نشد؛ کمی بعد دوباره تلاش کنید یا با پشتیبانی در تماس باشید.'),
            BotText::ServiceRotatedUnsent->value => new TextSpec($group, TextKind::Message, 'لینک جدید ارسال نشد', 'وقتی لینک تغییر کرد ولی پیام لینک جدید ارسال نشد؛ دکمه «لینک اشتراک» زیر آن می‌آید.', '⚠️ لینک سرویس تغییر کرد ولی ارسال آن ناموفق بود؛ لینک جدید را با دکمه زیر بگیرید.'),
            BotText::LinkRotated->value => new TextSpec($group, TextKind::Caption, 'لینک جدید', 'بعد از تغییر لینک؛ همراه عکس کد QR، وقتی ساخت آن روشن است.', '🔄 لینک سرویس شما تغییر کرد

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

    /** @return array<string, TextSpec> */
    private static function supportActions(): array
    {
        $group = 'support_actions';

        return [
            BotText::ServiceDisabledBySupport->value => new TextSpec($group, TextKind::Message, 'غیرفعال شدن سرویس', 'وقتی پشتیبانی سرویس را غیرفعال می‌کند.', '⛔️ سرویس <code>%client%</code> توسط پشتیبانی غیرفعال شد.%note%

برای پیگیری با پشتیبانی در تماس باشید.', self::vars('client', 'note')),
            BotText::ServiceEnabledBySupport->value => new TextSpec($group, TextKind::Message, 'فعال شدن دوباره سرویس', 'وقتی پشتیبانی سرویس را دوباره فعال می‌کند.', '✅ سرویس <code>%client%</code> دوباره فعال شد.', self::vars('client')),
            BotText::ServiceMoved->value => new TextSpec($group, TextKind::Caption, 'انتقال سرویس', 'وقتی پشتیبانی سرویس را به سرور دیگری منتقل می‌کند؛ همراه عکس کد QR لینک جدید، وقتی ساخت آن روشن است.', '🔄 سرویس شما به سرور دیگری منتقل شد

لینک جدید را در همه دستگاه‌های خود جایگزین کنید؛ زمان و حجم باقی‌مانده همان است.

👤 نام کاربری سرویس: <code>%client%</code>
🌿 نام سرویس: %plan%
‏🇺🇳 لوکیشن: %server%
📅 تاریخ اتمام: %expires%
📥 حجم باقی‌مانده: %traffic%

🔗 لینک اتصال:

<code>%subscription%</code>', self::vars(...self::SERVICE, server: 'نام سرور جدید', traffic: 'حجم سرویس روی سرور جدید (همان باقی‌مانده)'), ['subscription']),
            BotText::ServiceDeletedBySupport->value => new TextSpec($group, TextKind::Message, 'حذف سرویس', 'وقتی پشتیبانی سرویسی را که هنوز فعال بود حذف می‌کند.', '🗑️ سرویس <code>%client%</code> توسط پشتیبانی حذف شد.%note%

برای پیگیری با پشتیبانی در تماس باشید.', self::vars('client', 'note')),
            BotText::ServiceGranted->value => new TextSpec($group, TextKind::Message, 'افزودن زمان و حجم', 'وقتی پشتیبانی به سرویس زمان یا حجم اضافه می‌کند: از صفحه سرور، از «هدیه همگانی» یا برای همین یک سرویس از صفحه اشتراک‌ها.', '🎁 %gift% به سرویس <code>%client%</code> اضافه شد.%note%

📅 تاریخ اتمام: %expires%
📥 حجم باقی‌مانده: %remaining%', self::vars(...self::SERVICE) + self::vars('gift', 'note')),
        ];
    }

    /** @return array<string, TextSpec> */
    private static function renewal(): array
    {
        $group = 'renewal';

        return [
            BotText::RenewChoose->value => new TextSpec($group, TextKind::Message, 'انتخاب سرویس برای تمدید', 'بعد از دکمه «تمدید سرویس» منوی اصلی؛ دکمه سرویس‌هایی که می‌شود تمدید کرد، فعال یا منقضی، زیر آن می‌آید.', '♻️ تمدید سرویس
برای تمدید، روی نام سرویس بزنید.'),
            BotText::RenewNothing->value => new TextSpec($group, TextKind::Message, 'سرویسی برای تمدید نیست', 'وقتی مشتری دکمه «تمدید سرویس» منوی اصلی را می‌زند و سرویسی ندارد که بشود تمدید کرد.', 'شما سرویسی برای تمدید ندارید.'),
            BotText::RenewCheckout->value => new TextSpec($group, TextKind::Message, 'صفحه پرداخت تمدید', 'بعد از دکمه «تمدید سرویس» صفحه سرویس، یا انتخاب سرویس در «تمدید سرویس» منوی اصلی؛ دکمه روش‌های پرداخت زیر آن می‌آید. سفارش وقتی ثبت می‌شود که مشتری روش پرداخت را انتخاب کند.', '🧾 <b>تمدید سرویس</b>

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
            BotText::RenewUnavailable->value => new TextSpec($group, TextKind::Popup, 'تمدید ممکن نیست', 'اعلان وقتی مشتری سرویسی را تمدید می‌کند که الان نمی‌شود: پشتیبانی آن را غیرفعال کرده، پلنش دیگر وجود ندارد یا تمدید فعلا در دسترس نیست.', 'تمدید این سرویس در حال حاضر ممکن نیست؛ لطفا با پشتیبانی در تماس باشید.'),
            BotText::RenewUnderWay->value => new TextSpec($group, TextKind::Popup, 'تمدید در جریان است', 'اعلان وقتی برای سرویس یک تمدید در جریان است: رسیدش پیش پشتیبانی است، یا پرداخت شده و در حال انجام است.', 'برای این سرویس یک تمدید در حال بررسی یا انجام است؛ نتیجه همین‌جا اعلام می‌شود و نیازی به پرداخت دوباره نیست.'),
            BotText::ServiceAutoRenewOn->value => new TextSpec($group, TextKind::Button, 'دکمه تمدید خودکار: روشن', 'در صفحه سرویس، وقتی تمدید خودکار روشن است.', '🔁 تمدید خودکار: روشن'),
            BotText::ServiceAutoRenewOff->value => new TextSpec($group, TextKind::Button, 'دکمه تمدید خودکار: خاموش', 'در صفحه سرویس، وقتی تمدید خودکار خاموش است.', '🔁 تمدید خودکار: خاموش'),
            BotText::AutoRenewEnabled->value => new TextSpec($group, TextKind::Popup, 'روشن شدن تمدید خودکار', 'اعلان وقتی مشتری تمدید خودکار را روشن می‌کند.', '✅ تمدید خودکار روشن شد: %days% روز پیش از پایان سرویس، %amount% از کیف پول کسر و سرویس تمدید می‌شود.', ['days' => 'چند روز پیش از پایان سرویس', 'amount' => 'هزینه تمدید']),
            BotText::AutoRenewDisabled->value => new TextSpec($group, TextKind::Popup, 'خاموش شدن تمدید خودکار', 'اعلان کوتاه وقتی مشتری تمدید خودکار را خاموش می‌کند.', 'تمدید خودکار خاموش شد.'),
            BotText::AutoRenewUnavailable->value => new TextSpec($group, TextKind::Popup, 'تمدید خودکار در دسترس نیست', 'اعلان وقتی تمدید خودکار برای سرویس ممکن نیست.', 'تمدید خودکار برای این سرویس در دسترس نیست.'),
            BotText::AutoRenewed->value => new TextSpec($group, TextKind::Message, 'تمدید خودکار انجام شد', 'بعد از تمدید خودکار از کیف پول.', '♻️ سرویس <code>%client%</code> خودکار تمدید شد.

💰 %amount% از کیف پول کسر شد؛ موجودی فعلی: %balance%
🌿 نام سرویس: %plan%
📅 تاریخ اتمام: %expires%
📥 حجم باقی‌مانده: %remaining%%leftover%', self::vars(...self::SERVICE) + self::vars('balance', 'leftover') + ['amount' => 'مبلغ کسرشده از کیف پول']),
            BotText::AutoRenewShort->value => new TextSpec($group, TextKind::Message, 'موجودی کافی برای تمدید خودکار نیست', 'یک بار در هر دوره تمدید، وقتی موجودی کیف پول کم است؛ دکمه افزایش موجودی زیر آن می‌آید.', '⚠️ تمدید خودکار سرویس <code>%client%</code> انجام نشد: موجودی کیف پول کافی نیست.

💰 هزینه تمدید: %amount%
💳 موجودی کیف پول: %balance%
📅 پایان سرویس: %expires%

کیف پول را شارژ کنید تا سرویس پیش از پایان، خودکار تمدید شود.', self::vars('client', 'balance', 'expires') + ['amount' => 'هزینه تمدید']),
            BotText::Renewed->value => new TextSpec($group, TextKind::Message, 'تمدید انجام شد', 'بعد از تمدید سرویس از ربات — همان لحظه وقتی از کیف پول پرداخت شد، یا بعد از تایید رسید —، و وقتی تمدیدی که پرداخت شده بود بعدا تحویل می‌شود.', '♻️ سرویس <code>%client%</code> تمدید شد.

🌿 نام سرویس: %plan%
📅 تاریخ اتمام: %expires%
📥 حجم باقی‌مانده: %remaining%%leftover%', self::vars(...self::SERVICE) + self::vars('leftover')),
            BotText::RenewalLeftoverUntil->value => new TextSpec($group, TextKind::Part, 'حجم دوره فعلی', 'مقدار %leftover% در پیام‌های تمدید و خطی زیر صفحه سرویس، وقتی حجم باقی‌مانده به دوره جدید نمی‌رود.', '

⏳ %expiring% از حجم باقی‌مانده مال دوره فعلی است و فقط تا %date% قابل استفاده است؛ از آن روز دوره جدید با %next% شروع می‌شود.', self::vars('expiring', 'date', 'next')),
            BotText::RenewalLeftoverCarried->value => new TextSpec($group, TextKind::Part, 'انتقال حجم باقی‌مانده', 'مقدار %leftover% در پیام‌های تمدید، وقتی حجم باقی‌مانده به دوره جدید منتقل شد.', '

📥 حجم باقی‌مانده دوره قبل هم به دوره جدید منتقل شد.'),
            BotText::RenewFailed->value => new TextSpec($group, TextKind::Message, 'تمدید ناموفق', 'وقتی تمدید پرداخت شد ولی روی سرور انجام نشد.', 'پرداخت تمدید سرویس <code>%client%</code> ثبت شد ولی تمدید روی سرور انجام نشد. پشتیبانی به‌زودی آن را انجام می‌دهد؛ نیازی به پرداخت دوباره نیست.', self::vars('client')),
        ];
    }

    /** @return array<string, TextSpec> */
    private static function reminders(): array
    {
        $group = 'reminders';

        return [
            BotText::ExpiryReminder->value => new TextSpec($group, TextKind::Message, 'یادآوری پایان سرویس', 'چند روز پیش از پایان سرویس، وقتی این یادآوری روشن است؛ دکمه «مشاهده سرویس» زیر آن می‌آید.', '⏳ سرویس <code>%client%</code> رو به پایان است.

📅 تاریخ اتمام: %expires%
📥 حجم باقی‌مانده: %remaining%

برای اینکه سرویس قطع نشود، پیش از پایان تمدیدش کنید.%hint%', self::vars(...self::SERVICE) + self::vars('hint')),
            BotText::TrafficReminder->value => new TextSpec($group, TextKind::Message, 'یادآوری پایان حجم', 'وقتی بیشتر حجم سرویس مصرف شده، وقتی این یادآوری روشن است؛ دکمه «مشاهده سرویس» زیر آن می‌آید.', '⚠️ %percent% از حجم سرویس <code>%client%</code> مصرف شده است.

📥 حجم باقی‌مانده: %remaining% از %traffic%
📅 تاریخ اتمام: %expires%

برای اینکه سرویس قطع نشود، پیش از تمام شدن حجم تمدیدش کنید.', self::vars(...self::SERVICE) + self::vars('percent')),
            BotText::ReminderAutoRenewHint->value => new TextSpec($group, TextKind::Part, 'نکته تمدید خودکار', 'مقدار %hint% در یادآوری پایان سرویس، وقتی سرویس می‌تواند خودکار تمدید شود و تمدید خودکارش خاموش است.', '

💡 با روشن کردن «تمدید خودکار» در صفحه سرویس، سرویس پیش از پایان از کیف پول تمدید می‌شود.'),
            BotText::ReminderOpenService->value => new TextSpec($group, TextKind::Button, 'دکمه مشاهده سرویس', 'زیر پیام‌های یادآوری، و زیر پیام تمدید وقتی سرویس از ربات با کیف پول تمدید شد.', '📊 مشاهده سرویس'),
        ];
    }

    /** @return array<string, TextSpec> */
    private static function wallet(): array
    {
        $group = 'wallet';

        return [
            BotText::WalletBalance->value => new TextSpec($group, TextKind::Message, 'موجودی کیف پول', 'بالای صفحه کیف پول؛ تراکنش‌ها و دکمه افزایش موجودی زیر آن می‌آید.', '💳 موجودی کیف پول: <b>%balance%</b>', self::vars('balance')),
            BotText::WalletHistory->value => new TextSpec($group, TextKind::Part, 'آخرین تراکنش‌ها', 'زیر موجودی در صفحه کیف پول.', '

📜 آخرین تراکنش‌ها:
%history%', self::vars('history'), ['history']),
            BotText::WalletHistoryCredit->value => new TextSpec($group, TextKind::Part, 'تراکنش واریز', 'یک خط از آخرین تراکنش‌ها، برای مبلغی که به کیف پول اضافه شد.', '➕ %amount% · %entry% · %when%', self::vars('amount', 'entry', 'when')),
            BotText::WalletHistoryDebit->value => new TextSpec($group, TextKind::Part, 'تراکنش برداشت', 'یک خط از آخرین تراکنش‌ها، برای مبلغی که از کیف پول کم شد.', '➖ %amount% · %entry% · %when%', self::vars('amount', 'entry', 'when')),
            BotText::WalletNoHistory->value => new TextSpec($group, TextKind::Part, 'تراکنشی نیست', 'زیر موجودی، وقتی مشتری هنوز تراکنشی ندارد.', '

هنوز تراکنشی ندارید.'),
            BotText::WalletTopup->value => new TextSpec($group, TextKind::Button, 'دکمه افزایش موجودی', 'در صفحه کیف پول، زیر «موجودی کافی نیست» و زیر پیام کمبود موجودی تمدید خودکار.', '➕ افزایش موجودی'),
            BotText::WalletCharged->value => new TextSpec($group, TextKind::Message, 'شارژ کیف پول', 'بعد از شارژ موفق کیف پول.', '✅ کیف پول شما شارژ شد.
موجودی جدید: <b>%balance%</b>', self::vars('balance')),
            BotText::TopupPick->value => new TextSpec($group, TextKind::Message, 'انتخاب مبلغ شارژ', 'بعد از «افزایش موجودی»؛ دکمه مبلغ‌ها و «مبلغ دلخواه» زیر آن می‌آید.', 'مبلغ شارژ را انتخاب کنید، یا مبلغ دلخواه خود را بفرستید (حداقل %min%):', self::vars('min')),
            BotText::TopupCustom->value => new TextSpec($group, TextKind::Button, 'دکمه مبلغ دلخواه', 'زیر انتخاب مبلغ شارژ.', '✏️ مبلغ دلخواه'),
            BotText::TopupAsk->value => new TextSpec($group, TextKind::Message, 'پرسش مبلغ دلخواه', 'بعد از «مبلغ دلخواه».', 'مبلغ شارژ را به تومان بفرستید (حداقل %min%):', self::vars('min')),
            BotText::TopupInvalid->value => new TextSpec($group, TextKind::Message, 'مبلغ نامعتبر', 'وقتی مبلغی که مشتری فرستاده عدد درستی نیست یا از حداقل کمتر است.', 'مبلغ نامعتبر است. یک عدد به تومان بفرستید، حداقل %min%.', self::vars('min')),
            BotText::TopupFailed->value => new TextSpec($group, TextKind::Message, 'شارژ ناموفق', 'وقتی پرداخت انجام شد ولی شارژ کیف پول انجام نشد.', 'پرداخت ثبت شد ولی شارژ کیف پول انجام نشد. پشتیبانی به‌زودی درستش می‌کند؛ نیازی به پرداخت دوباره نیست.'),
            BotText::WalletCredit->value => new TextSpec($group, TextKind::Part, 'اعتبار نمایندگی', 'زیر موجودی در صفحه کیف پول، برای نماینده‌ای که اعتبار خرید دارد.', '
🏦 اعتبار خرید نمایندگی: %credit%', self::vars('credit')),
        ];
    }

    /** @return array<string, TextSpec> */
    private static function referral(): array
    {
        $group = 'referral';

        return [
            BotText::ReferralScreen->value => new TextSpec($group, TextKind::Message, 'زیرمجموعه‌گیری', 'صفحه «زیرمجموعه‌گیری»: لینک دعوت مشتری، شرط پورسانت و آمار او؛ دکمه ارسال لینک زیر آن می‌آید.', '👥 <b>زیرمجموعه‌گیری</b>

%terms%

🔗 لینک دعوت شما:
<code>%link%</code>

👤 تعداد زیرمجموعه‌ها: %referrals%
💰 پورسانت دریافتی: %earned%', self::vars('terms', 'link', 'referrals', 'earned'), ['link']),
            BotText::ReferralTermsEvery->value => new TextSpec($group, TextKind::Part, 'شرط پورسانت: هر پرداخت', 'مقدار %terms% در صفحه زیرمجموعه‌گیری، وقتی هر پرداخت زیرمجموعه پورسانت دارد.', 'دوستانتان را با لینک زیر به ربات دعوت کنید؛ از هر پرداخت موفق آن‌ها %rate% به عنوان پورسانت به کیف پول شما اضافه می‌شود.', self::vars('rate')),
            BotText::ReferralTermsFirst->value => new TextSpec($group, TextKind::Part, 'شرط پورسانت: اولین پرداخت', 'مقدار %terms% در صفحه زیرمجموعه‌گیری، وقتی فقط اولین پرداخت هر زیرمجموعه پورسانت دارد.', 'دوستانتان را با لینک زیر به ربات دعوت کنید؛ از اولین پرداخت موفق هر کدام از آن‌ها %rate% به عنوان پورسانت به کیف پول شما اضافه می‌شود.', self::vars('rate')),
            BotText::ReferralShare->value => new TextSpec($group, TextKind::Button, 'دکمه ارسال لینک', 'زیر صفحه زیرمجموعه‌گیری؛ پنجره اشتراک‌گذاری تلگرام را با لینک دعوت باز می‌کند.', '📤 ارسال لینک برای دوستان'),
            BotText::ReferralOff->value => new TextSpec($group, TextKind::Message, 'زیرمجموعه‌گیری خاموش', 'صفحه «زیرمجموعه‌گیری»، وقتی زیرمجموعه‌گیری در تنظیمات ربات خاموش است.', 'زیرمجموعه‌گیری فعلا فعال نیست.'),
            BotText::ReferralJoined->value => new TextSpec($group, TextKind::Message, 'زیرمجموعه جدید', 'به معرف، وقتی کسی اولین بار با لینک دعوت او وارد ربات می‌شود.', '🎉 یک زیرمجموعه جدید با لینک دعوت شما وارد ربات شد.', ['name' => 'نام زیرمجموعه جدید']),
            BotText::ReferralCommission->value => new TextSpec($group, TextKind::Message, 'دریافت پورسانت', 'به معرف، وقتی پرداخت یکی از زیرمجموعه‌هایش پورسانت دارد و به کیف پول او اضافه می‌شود.', '💰 %commission% پورسانت زیرمجموعه به کیف پول شما اضافه شد.
موجودی جدید: <b>%balance%</b>', self::vars('commission', 'paid', 'balance')),
        ];
    }

    /** @return array<string, TextSpec> */
    private static function agency(): array
    {
        $group = 'agency';
        $price = ['price' => 'قیمت هر گیگابایت برای نماینده'];
        $left = ['traffic' => 'حجم باقی‌مانده ربات نماینده'];

        return [
            BotText::AgencyOff->value => new TextSpec($group, TextKind::Message, 'نمایندگی خاموش', 'صفحه «نمایندگی»، وقتی نمایندگی در تنظیمات ربات خاموش است.', 'نمایندگی فعلا فعال نیست.'),
            BotText::AgencyTerms->value => new TextSpec($group, TextKind::Message, 'معرفی نمایندگی', 'صفحه «نمایندگی» برای کسی که هنوز نماینده نیست؛ دکمه «درخواست نمایندگی» زیر آن می‌آید.', '🤝 <b>نمایندگی</b>

نماینده‌ها ربات فروش خودشان را دارند: پلن‌ها، قیمت‌ها، روش‌های پرداخت و مشتری‌هایش مال خودشان است و سرویس‌ها روی سرورهای ما ساخته می‌شوند. حجمی که ربات می‌فروشد را از همین‌جا می‌خرند.

قیمت هر گیگابایت در هر سطح:
%levels%

برای شروع درخواست بدهید؛ پشتیبانی آن را بررسی می‌کند و نتیجه همین‌جا اعلام می‌شود.', self::vars('levels')),
            BotText::AgencyLevelLine->value => new TextSpec($group, TextKind::Part, 'سطح نمایندگی در معرفی', 'یک خط از %levels% در معرفی نمایندگی، برای هر سطح.', '• %level%: هر گیگابایت %price%', self::vars('level') + ['price' => 'قیمت هر گیگابایت این سطح']),
            BotText::AgencyNoLevels->value => new TextSpec($group, TextKind::Part, 'هنوز سطحی نیست', 'مقدار %levels% در معرفی نمایندگی، وقتی هنوز سطحی ساخته نشده است.', 'به‌زودی اعلام می‌شود'),
            BotText::AgencyApply->value => new TextSpec($group, TextKind::Button, 'دکمه درخواست نمایندگی', 'زیر معرفی نمایندگی.', '📝 درخواست نمایندگی'),
            BotText::AgencyAskNote->value => new TextSpec($group, TextKind::Message, 'پرسش توضیح درخواست', 'بعد از «درخواست نمایندگی»؛ آنچه مشتری می‌نویسد همراه درخواست به پشتیبانی می‌رسد.', 'درباره خودتان و فروشتان چند خط بنویسید (مثلا کانال یا تعداد مشتری‌ها)؛ پشتیبانی آن را همراه درخواست می‌بیند.'),
            BotText::AgencyNoteInvalid->value => new TextSpec($group, TextKind::Message, 'توضیح نامعتبر', 'وقتی مشتری به جای متن چیز دیگری می‌فرستد یا متنش بلند است.', 'لطفا توضیح را به صورت متن و حداکثر ۵۰۰ کاراکتر بفرستید.'),
            BotText::AgencyRequested->value => new TextSpec($group, TextKind::Message, 'ثبت درخواست', 'بعد از اینکه درخواست نمایندگی ثبت شد.', '✅ درخواست نمایندگی شما ثبت شد. نتیجه بررسی همین‌جا به شما اعلام می‌شود.'),
            BotText::AgencyPending->value => new TextSpec($group, TextKind::Message, 'درخواست در حال بررسی', 'صفحه «نمایندگی»، وقتی درخواست مشتری هنوز بررسی نشده است.', '⏳ درخواست نمایندگی شما در حال بررسی است. نتیجه همین‌جا به شما اعلام می‌شود.'),
            BotText::AgencyApproved->value => new TextSpec($group, TextKind::Message, 'تایید نمایندگی', 'وقتی پشتیبانی درخواست نمایندگی را تایید می‌کند.', '🎉 درخواست نمایندگی شما تایید شد!

🏅 سطح: %level%
💾 قیمت هر گیگابایت: %price%
🏦 اعتبار خرید: %credit%

از «نمایندگی» در منو ربات خودتان را وصل کنید، حجم بخرید و وارد پنل فروشگاهتان شوید.', self::vars('level', 'credit') + $price),
            BotText::AgencyRejected->value => new TextSpec($group, TextKind::Message, 'رد درخواست نمایندگی', 'وقتی پشتیبانی درخواست نمایندگی را رد می‌کند.', '❌ درخواست نمایندگی شما تایید نشد.%note%', self::vars('note')),
            BotText::AgencyChanged->value => new TextSpec($group, TextKind::Message, 'تغییر نمایندگی', 'وقتی پشتیبانی سطح یا اعتبار نماینده را تغییر می‌دهد.', '🔄 نمایندگی شما به‌روز شد.

🏅 سطح: %level%
💾 قیمت هر گیگابایت: %price%
🏦 اعتبار خرید: %credit%', self::vars('level', 'credit') + $price),
            BotText::AgencyRevoked->value => new TextSpec($group, TextKind::Message, 'لغو نمایندگی', 'وقتی پشتیبانی نمایندگی را لغو می‌کند.', '⛔️ نمایندگی شما لغو شد.%note%

ربات فروش شما خاموش شد و ورود به پنل آن دیگر ممکن نیست.', self::vars('note')),
            BotText::AgencyPanel->value => new TextSpec($group, TextKind::Message, 'حساب نمایندگی', 'صفحه «نمایندگی» برای نماینده؛ دکمه‌های خرید حجم، ربات من، ورود به پنل و افزایش موجودی زیر آن می‌آید.', '🤝 <b>حساب نمایندگی</b>

🏅 سطح: %level% (هر گیگابایت %price%)
💾 حجم باقی‌مانده: %traffic%
💳 موجودی کیف پول: %balance%
🏦 اعتبار خرید: %credit%

🤖 ربات شما: %bot%
👥 مشتری‌ها: %customers%
📦 سرویس‌های فروخته‌شده: %sold%
✅ سرویس‌های فعال: %active%', self::vars('level', 'balance', 'credit', 'bot', 'customers', 'sold', 'active') + $price + $left),
            BotText::AgencyBuyTraffic->value => new TextSpec($group, TextKind::Button, 'دکمه خرید حجم', 'در حساب نمایندگی، و زیر خبر کم آمدن حجم.', '💾 خرید حجم'),
            BotText::AgencyMyBot->value => new TextSpec($group, TextKind::Button, 'دکمه ربات من', 'در حساب نمایندگی.', '🤖 ربات من'),
            BotText::AgencyPanelLogin->value => new TextSpec($group, TextKind::Button, 'دکمه ورود به پنل', 'در حساب نمایندگی و زیر لینک ورود.', '🔐 ورود به پنل'),
            BotText::AgencyTraffic->value => new TextSpec($group, TextKind::Message, 'خرید حجم', 'بعد از «خرید حجم»؛ دکمه حجم‌های پیشنهادی زیر آن می‌آید.', '💾 <b>خرید حجم</b>

قیمت هر گیگابایت: %price%
حجم باقی‌مانده ربات: %traffic%

یکی از حجم‌ها را بزنید، یا تعداد گیگابایت را بفرستید (حداقل %min%).', $price + $left + ['min' => 'کمترین خرید حجم، به گیگابایت']),
            BotText::AgencyTrafficButton->value => new TextSpec($group, TextKind::Button, 'دکمه حجم پیشنهادی', 'زیر صفحه خرید حجم، یکی برای هر حجم پیشنهادی.', '%traffic% · %amount%', ['traffic' => 'حجم', 'amount' => 'قیمت این حجم']),
            BotText::AgencyTrafficInvalid->value => new TextSpec($group, TextKind::Message, 'حجم نامعتبر', 'وقتی عددی که نماینده برای حجم فرستاده درست نیست.', 'تعداد گیگابایت را به عدد بفرستید؛ حداقل %min%.', ['min' => 'کمترین خرید حجم، به گیگابایت']),
            BotText::AgencyTrafficCheckout->value => new TextSpec($group, TextKind::Message, 'صفحه پرداخت خرید حجم', 'همان صفحه پرداخت، برای خرید حجم نمایندگی.', '🧾 <b>خرید حجم نمایندگی</b>

حجم: <b>%traffic%</b>
مبلغ: <b>%amount%</b>

روش پرداخت را انتخاب کنید:', ['traffic' => 'حجمی که خریده می‌شود', 'amount' => 'مبلغ']),
            BotText::AgencyTrafficAdded->value => new TextSpec($group, TextKind::Message, 'حجم اضافه شد', 'بعد از پرداخت موفق خرید حجم.', '✅ %traffic% به حجم ربات شما اضافه شد.
حجم باقی‌مانده: <b>%remaining%</b>', ['traffic' => 'حجمی که خریده شد', 'remaining' => 'حجم باقی‌مانده ربات نماینده']),
            BotText::AgencyTrafficFailed->value => new TextSpec($group, TextKind::Message, 'خرید حجم ناموفق', 'وقتی پرداخت انجام شد ولی حجم اضافه نشد.', 'پرداخت ثبت شد ولی افزودن حجم انجام نشد؛ پشتیبانی به‌زودی آن را انجام می‌دهد.'),
            BotText::AgencyTrafficShort->value => new TextSpec($group, TextKind::Message, 'حجم ربات کم است', 'به نماینده، وقتی ربات او سفارشی را به خاطر کم بودن حجم تحویل نداد؛ دکمه خرید حجم زیر آن می‌آید.', '⚠️ حجم ربات شما برای تحویل سفارش #%order% کافی نیست.
حجم باقی‌مانده: %traffic%

حجم بخرید و سپس در پنل، تحویل سفارش را دوباره امتحان کنید.', self::vars('order') + $left),
            BotText::ReceiptOutcomeTraffic->value => new TextSpec($group, TextKind::Part, 'نتیجه رسید: خرید حجم', 'مقدار %outcome% در پیام دریافت رسید و پیام «پرداخت انجام شد»، وقتی سفارش خرید حجم نمایندگی است.', 'حجم به ربات شما اضافه می‌شود و همین‌جا خبر می‌دهیم'),
            BotText::AgencyNoBot->value => new TextSpec($group, TextKind::Part, 'ربات هنوز وصل نشده', 'مقدار %bot% در حساب نمایندگی، وقتی نماینده هنوز رباتش را وصل نکرده است.', 'هنوز وصل نشده'),
            BotText::AgencyBotNone->value => new TextSpec($group, TextKind::Message, 'وصل کردن ربات', 'بعد از «ربات من»، وقتی نماینده هنوز رباتش را وصل نکرده است.', '🤖 <b>ربات من</b>

۱. در @BotFather با دستور /newbot یک ربات بسازید.
۲. توکنی را که @BotFather می‌دهد همین‌جا بفرستید.

ربات شما همین حالا راه می‌افتد؛ پلن‌ها، قیمت‌ها، روش‌های پرداخت و متن‌هایش را در پنل خودتان می‌سازید.'),
            BotText::AgencyBotInfo->value => new TextSpec($group, TextKind::Message, 'ربات من', 'بعد از «ربات من»، وقتی ربات نماینده وصل است.', '🤖 ربات شما: %bot%
%status%

اگر توکن ربات را در @BotFather عوض کردید، توکن تازه را همین‌جا بفرستید.', self::vars('bot') + ['status' => 'وضعیت ربات (یکی از دو متن وضعیت ربات)']),
            BotText::AgencyBotRunning->value => new TextSpec($group, TextKind::Part, 'وضعیت ربات: فعال', 'مقدار %status% در صفحه ربات من، وقتی ربات بدون مشکل کار می‌کند.', '✅ فعال است و به مشتری‌ها جواب می‌دهد.'),
            BotText::AgencyBotProblem->value => new TextSpec($group, TextKind::Part, 'وضعیت ربات: مشکل', 'در صفحه ربات من و حساب نمایندگی، وقتی چیزی جلوی کار ربات را گرفته است.', '⚠️ %reason%', ['reason' => 'آنچه جلوی کار ربات را گرفته']),
            BotText::AgencyBotSaved->value => new TextSpec($group, TextKind::Message, 'ربات وصل شد', 'بعد از اینکه نماینده توکن درست را فرستاد.', '✅ ربات %bot% وصل شد و از همین حالا به مشتری‌ها جواب می‌دهد.

حالا وارد پنل شوید و پلن‌ها و روش‌های پرداخت ربات را بسازید.', self::vars('bot')),
            BotText::AgencyBotRefused->value => new TextSpec($group, TextKind::Message, 'توکن پذیرفته نشد', 'وقتی توکنی که نماینده فرستاده پذیرفته نمی‌شود.', '⚠️ %reason%', ['reason' => 'چرا توکن پذیرفته نشد']),
            BotText::AgencyLogin->value => new TextSpec($group, TextKind::Message, 'لینک ورود به پنل', 'بعد از «ورود به پنل»؛ لینکی که یک بار و برای چند دقیقه کار می‌کند.', '🔐 <b>ورود به پنل نمایندگی</b>

این لینک فقط یک بار و تا %minutes% دقیقه کار می‌کند؛ آن را به کسی ندهید:
%login%', self::vars('minutes', 'login'), ['login']),
        ];
    }

    /** @return array<string, TextSpec> */
    private static function account(): array
    {
        $group = 'account';

        return [
            BotText::TwoFactorDisabled->value => new TextSpec($group, TextKind::Message, 'خاموش شدن ورود دو مرحله‌ای', 'وقتی پشتیبانی ورود دو مرحله‌ای حساب مشتری در وب‌سایت را خاموش می‌کند (مثلا گوشی‌اش گم شده است).', '🔐 ورود دو مرحله‌ای حساب شما در وب‌سایت توسط پشتیبانی خاموش شد.

از این پس برای ورود با ایمیل، رمز عبور کافی است؛ هر وقت خواستید می‌توانید آن را دوباره از حساب‌تان در وب‌سایت روشن کنید.'),
            BotText::TwoFactorTurnedOn->value => new TextSpec($group, TextKind::Message, 'روشن کردن ورود دو مرحله‌ای', 'وقتی مشتری ورود دو مرحله‌ای حسابش در وب‌سایت را روشن می‌کند.', '🔐 ورود دو مرحله‌ای حساب شما در وب‌سایت روشن شد؛ از این پس برای ورود با ایمیل، کد برنامه احراز هویت هم خواسته می‌شود.

اگر این کار شما نبود، هر چه زودتر با پشتیبانی در تماس باشید.'),
            BotText::TwoFactorTurnedOff->value => new TextSpec($group, TextKind::Message, 'خاموش کردن ورود دو مرحله‌ای', 'وقتی مشتری خودش ورود دو مرحله‌ای حسابش در وب‌سایت را خاموش می‌کند.', '🔐 ورود دو مرحله‌ای حساب شما در وب‌سایت خاموش شد.

اگر این کار شما نبود، رمز عبور حساب‌تان دست کس دیگری است؛ هر چه زودتر آن را عوض کنید و با پشتیبانی در تماس باشید.'),
            BotText::WayInAdded->value => new TextSpec($group, TextKind::Message, 'روش ورود تازه', 'وقتی روش ورود تازه‌ای (تلگرام، گوگل یا ایمیل) به حساب مشتری در وب‌سایت اضافه می‌شود.', '🔐 ورود با %way% به حساب شما در وب‌سایت اضافه شد.

اگر این کار شما نبود، هر چه زودتر با پشتیبانی در تماس باشید.', self::vars('way'), ['way']),
            BotText::WayInRemoved->value => new TextSpec($group, TextKind::Message, 'برداشتن روش ورود', 'وقتی یکی از روش‌های ورود حساب مشتری در وب‌سایت برداشته می‌شود.', '🔐 ورود با %way% از حساب شما در وب‌سایت برداشته شد.

اگر این کار شما نبود، هر چه زودتر با پشتیبانی در تماس باشید.', self::vars('way'), ['way']),
            BotText::PasswordChanged->value => new TextSpec($group, TextKind::Message, 'رمز عبور تازه', 'وقتی رمز عبور حساب مشتری در وب‌سایت گذاشته یا عوض می‌شود — از حسابش، یا با «فراموشی رمز عبور».', '🔐 رمز عبور تازه‌ای برای حساب شما در وب‌سایت گذاشته شد.

اگر این کار شما نبود، هر چه زودتر با پشتیبانی در تماس باشید.'),
            BotText::AccountMerged->value => new TextSpec($group, TextKind::Message, 'یکی شدن دو حساب', 'وقتی مشتری حساب دیگری از خودش را با حسابش در وب‌سایت یکی می‌کند.', '🔗 حساب دیگری از شما با این حساب یکی شد؛ سرویس‌ها، سفارش‌ها و کیف پول هر دو از این پس در یک حساب است.

اگر این کار شما نبود، هر چه زودتر با پشتیبانی در تماس باشید.'),
            BotText::SecondStepLocked->value => new TextSpec($group, TextKind::Message, 'کد اشتباه ورود دو مرحله‌ای', 'وقتی کد ورود دو مرحله‌ای حساب مشتری در وب‌سایت آن‌قدر اشتباه وارد می‌شود که ورود با ایمیل مدتی بسته می‌ماند؛ روزی یک بار.', '⚠️ کسی رمز عبور حساب شما در وب‌سایت را درست وارد کرد، ولی کد ورود دو مرحله‌ای را چند بار اشتباه زد؛ ورود با ایمیل و رمز عبور مدتی بسته ماند.

اگر این شما نبودید، رمز عبورتان دست کس دیگری است؛ هر چه زودتر آن را عوض کنید.'),
            BotText::EmailCodesFailed->value => new TextSpec($group, TextKind::Message, 'کد اشتباه ایمیل', 'وقتی کدی که برای ایمیل حساب مشتری در وب‌سایت فرستاده می‌شود (بازیابی رمز عبور یا افزودن ایمیل) آن‌قدر اشتباه وارد می‌شود که تا مدتی کد تازه‌ای به آن ایمیل نمی‌رود؛ روزی یک بار.', '⚠️ کدی که برای ایمیل حساب شما در وب‌سایت فرستاده شد چند بار اشتباه وارد شد؛ تا مدتی کد تازه‌ای به این ایمیل فرستاده نمی‌شود.

اگر این شما نبودید، کسی می‌خواهد با ایمیل شما وارد حساب‌تان شود؛ برای امنیت بیشتر، ورود دو مرحله‌ای را در حساب‌تان روشن کنید.'),
        ];
    }

    /** @return array<string, TextSpec> */
    private static function support(): array
    {
        $group = 'support';

        return [
            BotText::SupportContact->value => new TextSpec($group, TextKind::Message, 'پشتیبانی', 'صفحه «پشتیبانی»؛ راه تماس از تنظیمات ربات می‌آید.', 'نیاز به کمک دارید؟ با ما در تماس باشید: %contact%', self::vars('contact'), ['contact']),
            BotText::SupportUnavailable->value => new TextSpec($group, TextKind::Message, 'پشتیبانی بدون راه تماس', 'صفحه «پشتیبانی»، وقتی راه تماسی در تنظیمات ربات نیست؛ دکمه‌های «تیکت جدید» و «تیکت‌های من» زیر آن می‌آید.', 'نیاز به کمک دارید؟ با دکمه‌های زیر تیکت پشتیبانی ثبت کنید یا تیکت‌های قبلی خود را ببینید؛ پاسخ پشتیبانی همین‌جا به شما می‌رسد.'),
            BotText::TicketNew->value => new TextSpec($group, TextKind::Button, 'دکمه تیکت جدید', 'در صفحه «پشتیبانی»، و زیر لیست تیکت‌ها وقتی مشتری هنوز تیکتی ندارد.', '📨 تیکت جدید'),
            BotText::TicketMine->value => new TextSpec($group, TextKind::Button, 'دکمه تیکت‌های من', 'در صفحه «پشتیبانی».', '🗂️ تیکت‌های من'),
            BotText::TicketPickService->value => new TextSpec($group, TextKind::Message, 'انتخاب سرویس تیکت', 'بعد از «تیکت جدید»، وقتی مشتری سرویس فعالی دارد؛ دکمه هر سرویس و «بدون سرویس مشخص» زیر آن می‌آید.', '📨 <b>تیکت جدید</b>

تیکت شما درباره کدام سرویس است؟'),
            BotText::TicketNoService->value => new TextSpec($group, TextKind::Button, 'دکمه بدون سرویس مشخص', 'زیر انتخاب سرویس تیکت جدید.', 'بدون سرویس مشخص'),
            BotText::TicketAsk->value => new TextSpec($group, TextKind::Message, 'نوشتن تیکت جدید', 'بعد از انتخاب سرویس — یا «تیکت جدید»، وقتی مشتری سرویسی ندارد —؛ پیام بعدی مشتری تیکت را باز می‌کند و خط اول آن موضوع تیکت می‌شود.', '📨 <b>تیکت جدید</b>%service%

مشکل یا سوال خود را در یک پیام بنویسید؛ خط اول پیام، موضوع تیکت می‌شود.
اگر تصویری دارید، آن را همراه توضیح (کپشن) بفرستید.', self::vars('service')),
            BotText::TicketReportAsk->value => new TextSpec($group, TextKind::Message, 'گزارش اختلال سرویس', 'بعد از «ارسال گزارش اختلال» در صفحه سرویس؛ پیام بعدی مشتری تیکتی درباره همان سرویس باز می‌کند و خط اول آن موضوع تیکت می‌شود.', '⚠️ <b>گزارش اختلال</b>
🔖 سرویس: <code>%client%</code>

مشکل این سرویس را در یک پیام بنویسید: از کی، روی چه دستگاهی و با کدام اینترنت وصل نمی‌شود؛ خط اول پیام، موضوع تیکت می‌شود.
اگر تصویری از خطا دارید، آن را همراه توضیح (کپشن) بفرستید.', self::vars('client')),
            BotText::TicketService->value => new TextSpec($group, TextKind::Part, 'سرویس تیکت', 'مقدار %service% در نوشتن تیکت جدید و صفحه تیکت، وقتی تیکت درباره یکی از سرویس‌های مشتری است.', '
🔖 سرویس: <code>%client%</code>', self::vars('client')),
            BotText::TicketPictureNeedsWords->value => new TextSpec($group, TextKind::Message, 'تصویر بدون توضیح', 'وقتی مشتری برای تیکت تصویری بدون توضیح (کپشن) می‌فرستد؛ تصویر نگه داشته می‌شود و پیام متنی بعدی همراه آن فرستاده می‌شود.', '🖼️ تصویر رسید؛ حالا توضیح آن را در یک پیام بنویسید تا همراه تصویر فرستاده شود.'),
            BotText::TicketTextOnly->value => new TextSpec($group, TextKind::Message, 'فقط متن یا تصویر', 'وقتی مشتری برای تیکت چیزی جز متن یا تصویر می‌فرستد: فایل، ویس، ویدیو، استیکر و مانند آن.', 'برای تیکت فقط متن یا تصویر فرستاده می‌شود؛ پیام خود را بنویسید، یا تصویر را همراه توضیح بفرستید.'),
            BotText::TicketTooShort->value => new TextSpec($group, TextKind::Message, 'پیام کوتاه', 'وقتی پیامی که تیکت جدید را باز می‌کند کوتاه‌تر از آن است که موضوع تیکت شود (کمتر از سه حرف)؛ تصویری که همراهش بود نگه داشته می‌شود.', 'پیام شما خیلی کوتاه است؛ لطفا مشکل یا سوال خود را کمی بیشتر توضیح دهید.%kept%', self::vars('kept')),
            BotText::TicketRefused->value => new TextSpec($group, TextKind::Message, 'پیام پذیرفته نشد', 'وقتی پیام تیکت پذیرفته نمی‌شود: خیلی طولانی است، تیکت به سقف پیام‌ها رسیده، یا مشتری در مدت کوتاهی تیکت یا پیام زیادی فرستاده است؛ تصویری که همراهش بود نگه داشته می‌شود.', '⚠️ %reason%%kept%', self::vars('reason', 'kept', reason: 'چرا پیام پذیرفته نشد'), ['reason']),
            BotText::TicketOpened->value => new TextSpec($group, TextKind::Message, 'ثبت تیکت', 'بعد از اینکه تیکت جدید باز شد؛ دکمه «مشاهده تیکت» زیر آن می‌آید. پیام‌های بعدی مشتری به همین تیکت اضافه می‌شود، تا وقتی به بخش دیگری از ربات برود.', '✅ تیکت #%ticket% ثبت شد.
📌 موضوع: %subject%

پاسخ پشتیبانی همین‌جا برایتان فرستاده می‌شود. اگر توضیح دیگری دارید، همین حالا بفرستید تا به این تیکت اضافه شود.', self::vars('ticket', 'subject')),
            BotText::TicketMessageSent->value => new TextSpec($group, TextKind::Message, 'ثبت پیام تیکت', 'بعد از هر پیام مشتری به تیکتش؛ دکمه «مشاهده تیکت» زیر آن می‌آید.', '✅ پیام شما به تیکت #%ticket% اضافه شد.', self::vars('ticket')),
            BotText::TicketView->value => new TextSpec($group, TextKind::Button, 'دکمه مشاهده تیکت', 'زیر ثبت تیکت و ثبت پیام، و زیر پاسخ پشتیبانی و بسته شدن تیکت.', '🗂️ مشاهده تیکت'),
            BotText::TicketsTitle->value => new TextSpec($group, TextKind::Message, 'لیست تیکت‌ها', 'بالای دکمه‌های تیکت‌ها در «تیکت‌های من»؛ تیکتی که تازه‌تر پیام گرفته بالاتر است.', '🗂️ <b>تیکت‌های شما</b>
برای دیدن گفتگو و پاسخ دادن، روی تیکت بزنید.

🟡 در انتظار پاسخ · 🟢 پاسخ داده شده · 🔒 بسته'),
            BotText::TicketsEmpty->value => new TextSpec($group, TextKind::Message, 'تیکتی نیست', 'وقتی مشتری هنوز تیکتی ندارد؛ دکمه «تیکت جدید» زیر آن می‌آید.', 'شما هنوز تیکتی ندارید. برای پرسیدن سوال یا گزارش مشکل، تیکت جدید بفرستید.'),
            BotText::TicketButtonOpen->value => new TextSpec($group, TextKind::Button, 'دکمه تیکت در انتظار پاسخ', 'در لیست تیکت‌ها، برای تیکتی که منتظر پاسخ پشتیبانی است.', '🟡 #%ticket% · %subject%', self::vars('ticket', 'subject'), ['ticket']),
            BotText::TicketButtonAnswered->value => new TextSpec($group, TextKind::Button, 'دکمه تیکت پاسخ‌داده‌شده', 'در لیست تیکت‌ها، برای تیکتی که پشتیبانی به آن پاسخ داده است.', '🟢 #%ticket% · %subject%', self::vars('ticket', 'subject'), ['ticket']),
            BotText::TicketButtonClosed->value => new TextSpec($group, TextKind::Button, 'دکمه تیکت بسته', 'در لیست تیکت‌ها، برای تیکت بسته.', '🔒 #%ticket% · %subject%', self::vars('ticket', 'subject'), ['ticket']),
            BotText::TicketScreen->value => new TextSpec($group, TextKind::Message, 'صفحه تیکت', 'وقتی مشتری یک تیکت را باز می‌کند؛ آخرین پیام‌های گفتگو در آن است و دکمه‌های پاسخ، بستن یا امتیاز زیر آن می‌آید.', '🎫 <b>تیکت #%ticket%</b>
📌 موضوع: %subject%
📊 وضعیت: %status%%service%%rated%
%messages%', self::vars('ticket', 'subject', 'status', 'service', 'rated', 'messages', status: 'وضعیت تیکت (یکی از متن‌های وضعیت تیکت)'), ['messages']),
            BotText::TicketStatusOpen->value => new TextSpec($group, TextKind::Part, 'وضعیت تیکت: در انتظار پاسخ', 'مقدار %status% در صفحه تیکت، وقتی تیکت منتظر پاسخ پشتیبانی است.', '🟡 در انتظار پاسخ پشتیبانی'),
            BotText::TicketStatusAnswered->value => new TextSpec($group, TextKind::Part, 'وضعیت تیکت: پاسخ داده شده', 'مقدار %status% در صفحه تیکت، وقتی پشتیبانی به تیکت پاسخ داده است.', '🟢 پاسخ داده شده'),
            BotText::TicketStatusClosed->value => new TextSpec($group, TextKind::Part, 'وضعیت تیکت: بسته', 'مقدار %status% در صفحه تیکت، وقتی تیکت بسته است.', '🔒 بسته شده'),
            BotText::TicketRating->value => new TextSpec($group, TextKind::Part, 'امتیاز تیکت', 'مقدار %rated% در صفحه تیکت، وقتی مشتری به تیکت بسته امتیاز داده است.', '
⭐ امتیاز شما: %rating% از ۵', self::vars('rating')),
            BotText::TicketEarlier->value => new TextSpec($group, TextKind::Part, 'پیام‌های قبلی', 'بالای گفتگوی صفحه تیکت، وقتی پیام‌های قدیمی‌تری هست که نشان داده نمی‌شود.', '
⋯ %earlier% پیام قبلی', self::vars('earlier')),
            BotText::TicketFromCustomer->value => new TextSpec($group, TextKind::Part, 'پیام مشتری', 'هر پیام مشتری در گفتگوی صفحه تیکت.', '
👤 <b>شما</b> · %when%
%message%%picture%', self::vars('when', 'message', 'picture', when: 'زمان پیام', picture: 'اشاره به تصویر پیام (متن «تصویر پیام»)، یا خالی'), ['message']),
            BotText::TicketFromSupport->value => new TextSpec($group, TextKind::Part, 'پیام پشتیبانی', 'هر پیام پشتیبانی در گفتگوی صفحه تیکت.', '
🎧 <b>پشتیبانی</b> · %when%
%message%%picture%', self::vars('when', 'message', 'picture', when: 'زمان پیام', picture: 'اشاره به تصویر پیام (متن «تصویر پیام»)، یا خالی'), ['message']),
            BotText::TicketMessagePicture->value => new TextSpec($group, TextKind::Part, 'تصویر پیام', 'مقدار %picture% در پیام‌های صفحه تیکت، وقتی پیام تصویری دارد؛ دکمه «تصویر» با همین شماره زیر صفحه، خود تصویر را می‌فرستد.', '
🖼️ همراه تصویر %number%', self::vars('number')),
            BotText::TicketPictureRemoved->value => new TextSpec($group, TextKind::Part, 'تصویر پیام دیگر نیست', 'مقدار %picture% در پیام‌های صفحه تیکت، وقتی تصویری که همراه پیام بود دیگر نگه داشته نمی‌شود (تصویرهای آپلودشده تیکت بسته، ۳۰ روز بعد از بسته شدن پاک می‌شوند).', '
🖼️ تصویر این پیام دیگر نگه داشته نمی‌شود.'),
            BotText::TicketPicture->value => new TextSpec($group, TextKind::Button, 'دکمه تصویر', 'زیر صفحه تیکت، یکی برای هر پیام نمایش‌داده‌شده‌ای که تصویر دارد؛ تصویر را جدا می‌فرستد.', '🖼️ تصویر %number%', self::vars('number')),
            BotText::TicketPictureCaption->value => new TextSpec($group, TextKind::Message, 'توضیح تصویر تیکت', 'زیر تصویری که دکمه «تصویر» صفحه تیکت می‌فرستد (توضیح بلندتر از هزار حرف، تصویر را بی‌توضیح می‌فرستد).', '🖼️ تصویر پیام %when% — تیکت #%ticket%', self::vars('when', 'ticket', when: 'زمان پیامی که تصویر همراه آن بود')),
            BotText::TicketPictureGone->value => new TextSpec($group, TextKind::Popup, 'تصویر در دسترس نیست', 'وقتی تصویری که مشتری با دکمه «تصویر» می‌خواهد دیگر نیست: تلگرام آن را نمی‌دهد، یا فایلش پاک شده است.', 'این تصویر دیگر در دسترس نیست.'),
            BotText::TicketPictureKept->value => new TextSpec($group, TextKind::Part, 'تصویر نگه داشته شد', 'مقدار %kept% در «پیام کوتاه» و «پیام پذیرفته نشد»، وقتی همراه پیام تصویری هم بود: تصویر نگه داشته می‌شود و با پیام بعدی مشتری می‌رود.', '

🖼️ تصویر شما نگه داشته شد و با پیام بعدی‌تان به تیکت فرستاده می‌شود.'),
            BotText::TicketNotFound->value => new TextSpec($group, TextKind::Message, 'تیکت پیدا نشد', 'وقتی تیکتی که مشتری باز می‌کند پیدا نمی‌شود.', 'این تیکت پیدا نشد.'),
            BotText::TicketReply->value => new TextSpec($group, TextKind::Button, 'دکمه پاسخ', 'در صفحه تیکت و زیر پاسخ پشتیبانی.', '✍️ پاسخ'),
            BotText::TicketReplyAsk->value => new TextSpec($group, TextKind::Message, 'نوشتن پاسخ', 'بعد از «پاسخ»؛ پیام بعدی مشتری به تیکت اضافه می‌شود.', '✍️ پیام خود برای تیکت #%ticket% را بنویسید؛ اگر تصویری دارید، آن را همراه توضیح (کپشن) بفرستید.%reopens%', self::vars('ticket', 'reopens')),
            BotText::TicketReplyReopens->value => new TextSpec($group, TextKind::Part, 'باز شدن دوباره تیکت', 'مقدار %reopens% در نوشتن پاسخ، وقتی تیکت بسته است.', '

🔓 این تیکت بسته شده است؛ با فرستادن پیام دوباره باز می‌شود.'),
            BotText::TicketClose->value => new TextSpec($group, TextKind::Button, 'دکمه بستن تیکت', 'در صفحه تیکتی که هنوز باز است.', '🔒 بستن تیکت'),
            BotText::TicketClosedByCustomer->value => new TextSpec($group, TextKind::Popup, 'تیکت بسته شد', 'اعلان کوتاه بعد از اینکه مشتری تیکت را می‌بندد.', '🔒 تیکت بسته شد.'),
            BotText::TicketAlreadyClosed->value => new TextSpec($group, TextKind::Popup, 'تیکت پیش‌تر بسته شده', 'وقتی مشتری تیکتی را می‌بندد که همان لحظه بسته شده است.', 'این تیکت پیش‌تر بسته شده است.'),
            BotText::TicketRate->value => new TextSpec($group, TextKind::Button, 'دکمه امتیاز', 'در صفحه تیکت بسته‌ای که هنوز امتیاز نگرفته، و زیر خبر بسته شدن تیکت.', '⭐ امتیاز'),
            BotText::TicketRateAsk->value => new TextSpec($group, TextKind::Message, 'امتیاز به تیکت', 'بعد از «امتیاز»؛ دکمه‌های ۱ تا ۵ زیر آن می‌آید.', '⭐ از پاسخ‌گویی پشتیبانی در تیکت #%ticket% چقدر راضی بودید؟ از ۱ تا ۵ امتیاز دهید.', self::vars('ticket')),
            BotText::TicketStar->value => new TextSpec($group, TextKind::Button, 'دکمه امتیاز ۱ تا ۵', 'زیر «امتیاز به تیکت»، یک دکمه برای هر امتیاز.', '%rating% ⭐', self::vars(rating: 'امتیازی که این دکمه می‌دهد، از ۱ تا ۵'), ['rating']),
            BotText::TicketRatedThanks->value => new TextSpec($group, TextKind::Popup, 'ثبت امتیاز', 'اعلان کوتاه بعد از ثبت امتیاز.', '⭐ امتیاز شما ثبت شد؛ ممنون!'),
            BotText::TicketRateUnavailable->value => new TextSpec($group, TextKind::Popup, 'امتیاز فقط برای تیکت بسته', 'وقتی مشتری به تیکتی امتیاز می‌دهد که همان لحظه دوباره باز شده است.', 'امتیاز را پس از بسته شدن تیکت می‌توانید ثبت کنید.'),
            BotText::TicketAnswered->value => new TextSpec($group, TextKind::Message, 'پاسخ پشتیبانی به تیکت', 'وقتی پشتیبانی — از پنل یا گروه گزارش‌ها — به تیکت مشتری پاسخ می‌دهد؛ دکمه‌های «پاسخ» و «مشاهده تیکت» زیر آن می‌آید، و مشتری بدون تلگرام آن را با ایمیل می‌گیرد. پاسخی که تصویر دارد همراه تصویرش فرستاده می‌شود و این متن توضیح تصویر است، تا وقتی در توضیح یک تصویر جا شود.', '💬 <b>پاسخ پشتیبانی به تیکت #%ticket%</b>
📌 %subject%

%answer%%picture%', self::vars('ticket', 'subject', 'answer', 'picture'), ['answer']),
            BotText::TicketAnswerPicture->value => new TextSpec($group, TextKind::Part, 'تصویر همراه پاسخ', 'مقدار %picture% در پاسخ پشتیبانی به تیکت، وقتی پاسخ تصویری هم دارد.', '

🖼️ این پاسخ یک تصویر هم دارد.'),
            BotText::TicketClosed->value => new TextSpec($group, TextKind::Message, 'بسته شدن تیکت', 'وقتی پشتیبانی تیکت مشتری را می‌بندد؛ دکمه‌های «امتیاز» و «مشاهده تیکت» زیر آن می‌آید.', '🔒 تیکت #%ticket% («%subject%») توسط پشتیبانی بسته شد.

اگر هنوز سوالی دارید، به همین تیکت پیام بدهید تا دوباره باز شود.', self::vars('ticket', 'subject')),
        ];
    }

    /**
     * The variables a text offers, in the order the screen shows them: by name, each meaning what the dictionary says
     * (VARIABLES) — or what this text means by it, given as a named argument (`self::vars('plan', 'traffic', traffic:
     * 'حجم پلن')`), which takes the name's place, or comes last when the name is not listed before it.
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
