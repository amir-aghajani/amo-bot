<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Search;

/**
 * Where the bot has every setting of the panel — the panel's settings search index (PanelSettings, generated from the
 * panel's own sections) mapped onto the bot's screens —, and the search over it («⚙️ جستجوی تنظیمات»). Each section of
 * the panel's is a screen of the bot's (SECTIONS), its settings opening that screen unless SETTINGS says otherwise: a
 * field of the card by its API name (opened as its button would: a typed field asked, a switch shown on its card or
 * part), a part of the card, another screen, or — the panel alone sets it — the screen that says why. SettingsParityTest
 * fails while a section of the panel's has no place here, or a place names a screen or a field the bot has not.
 *
 * A search reads what is typed as the panel's search does (normalize(): Persian however it was typed, Arabic letters
 * and digits and half-spaces folded), finds every word typed (AND) in an entry's title, its other words, its place or
 * its hint — whole, as a word's start, inside a word, or a letter off in a title — and ranks the title most.
 */
final class SettingsIndex
{
    /** The most a search returns. */
    public const MOST = 20;

    /** What a match must be worth beside the best one, at the least: a third of it — the rest is found by chance. */
    private const SHARE_OF_BEST = 3;

    /** Where the bot's settings are, as a trail starts. */
    private const SETTINGS_MENU = ['تنظیمات'];

    /**
     * Each section of the panel's settings search, by its address → [the bot screen it opens, its name in the bot, the
     * menus it is under, other words it is looked for by (the panel's keywords.ts, and the bot's own)].
     *
     * @var array<string, array{0: string, 1: string, 2: list<string>, 3: list<string>}>
     */
    private const SECTIONS = [
        '/agents' => ['agr', 'درخواست‌های نمایندگی', ['نمایندگان'], ['agent', 'reseller', 'نماینده', 'ریسلر', 'همکار']],
        '/agents/levels' => ['agk', 'سطح‌های نمایندگی', ['نمایندگان'], ['level', 'tier', 'سطح', 'قیمت نماینده', 'قیمت هر گیگ']],
        '/agents/list' => ['agl', 'نماینده‌ها', ['نمایندگان'], ['agent', 'reseller', 'نماینده', 'ریسلر', 'ربات نماینده']],
        '/agents/settings' => ['ags', 'تنظیمات نمایندگی', ['نمایندگان'], ['agency', 'reseller', 'نمایندگی', 'نماینده', 'ریسلر', 'اعتبار', 'credit', 'قوانین نمایندگی']],
        '/bot-settings/bot-admins' => ['ba', 'مدیران ربات', self::SETTINGS_MENU, ['admin', 'admins', 'staff', 'permission', 'manage from bot', 'bot panel', 'ادمین', 'ادمین ربات', 'مدیر', 'مدیر ربات', 'مدیران', 'دسترسی', 'نقش', 'مدیریت از ربات', 'پنل در ربات', 'مدیریت ربات']],
        '/bot-settings/channels' => ['ch', 'کانال‌های اجباری', ['ربات'], ['channel', 'join', 'force join', 'کانال', 'عضویت', 'جوین اجباری', 'قفل کانال', 'اسپانسر']],
        '/bot-settings/client-names' => ['bsn', 'نام کاربری سرویس', [...self::SETTINGS_MENU, 'تنظیمات ربات'], ['client', 'username', 'pattern', 'remark', 'کلاینت', 'نام کلاینت', 'یوزرنیم', 'الگو', 'ریمارک', 'نام سرویس']],
        '/bot-settings/expired-services' => ['bse', 'حذف سرویس‌های منقضی‌شده', [...self::SETTINGS_MENU, 'تنظیمات ربات'], ['expired', 'cleanup', 'delete', 'منقضی', 'حذف خودکار', 'پاک کردن', 'سرویس تمام‌شده']],
        '/bot-settings/extras' => ['bsx', 'حجم، زمان و کاربر اضافه', [...self::SETTINGS_MENU, 'تنظیمات ربات'], ['extra', 'traffic', 'devices', 'addon', 'حجم اضافه', 'زمان اضافه', 'کاربر اضافه', 'ترافیک اضافه', 'افزایش حجم', 'خرید حجم', 'خرید اضافه']],
        '/bot-settings/general' => ['bsg', 'عمومی', [...self::SETTINGS_MENU, 'تنظیمات ربات'], ['enable', 'disable', 'on', 'off', 'ربات خاموش', 'روشن کردن ربات', 'خاموش کردن ربات', 'phone', 'شماره', 'شماره موبایل', 'تلفن', 'support', 'پشتیبانی', 'آیدی پشتیبانی']],
        '/bot-settings/payments' => ['bsp', 'پرداخت کارت به کارت', [...self::SETTINGS_MENU, 'تنظیمات ربات'], ['card to card', 'card', 'receipt', 'کارت به کارت', 'کارت', 'شماره کارت', 'رسید', 'فیش', 'واریز', 'اسکرین شات', 'screenshot']],
        '/bot-settings/qr' => ['bsq', 'کد QR', [...self::SETTINGS_MENU, 'تنظیمات ربات'], ['qr', 'qr code', 'qrcode', 'barcode', 'کیو آر', 'کیوآر', 'بارکد']],
        '/bot-settings/referral' => ['bsf', 'قوانین زیرمجموعه‌گیری', ['فروش', 'زیرمجموعه‌گیری'], ['referral', 'invite', 'affiliate', 'commission', 'زیرمجموعه', 'رفرال', 'معرف', 'دعوت', 'لینک دعوت', 'پورسانت', 'کمیسیون']],
        '/bot-settings/reminders' => ['bsm', 'یادآوری به مشتری', [...self::SETTINGS_MENU, 'تنظیمات ربات'], ['reminder', 'notification', 'expire', 'یادآوری', 'اعلان', 'هشدار', 'انقضا', 'اتمام حجم', 'پایان سرویس']],
        '/bot-settings/renewal' => ['bsr', 'تمدید سرویس', [...self::SETTINGS_MENU, 'تنظیمات ربات'], ['renew', 'renewal', 'auto renew', 'تمدید', 'تمدید خودکار', 'reset', 'ریست']],
        '/bot-settings/reports' => ['rg', 'گروه گزارش‌ها', ['ربات'], ['report', 'reports', 'group', 'topic', 'topics', 'log', 'گزارش', 'گروه', 'تاپیک', 'لاگ', 'گروه ادمین', 'خریدها', 'تمدیدها', 'اکانت‌های تست', 'حذف سرویس‌ها', 'شارژ کیف پول', 'رسیدها', 'کاربران جدید', 'خطاها', 'تیکت‌ها', 'نظرات', 'پشتیبان‌گیری']],
        '/bot-settings/service-claims' => ['bsc', 'افزودن سرویس با لینک', [...self::SETTINGS_MENU, 'تنظیمات ربات'], ['claim', 'subscription link', 'config', 'لینک', 'لینک اشتراک', 'کانفیگ', 'افزودن سرویس', 'سرویس قدیمی']],
        '/bot-settings/service-transfers' => ['bsv', 'انتقال سرویس', [...self::SETTINGS_MENU, 'تنظیمات ربات'], ['transfer', 'انتقال', 'انتقال سرویس', 'دادن سرویس']],
        '/bot-settings/wallet' => ['bsw', 'کیف پول', [...self::SETTINGS_MENU, 'تنظیمات ربات'], ['wallet', 'balance', 'top up', 'topup', 'charge', 'کیف پول', 'موجودی', 'شارژ', 'افزایش موجودی', 'حداقل شارژ']],
        '/bot-settings/wallet-transfers' => ['bst', 'انتقال موجودی', [...self::SETTINGS_MENU, 'تنظیمات ربات'], ['transfer', 'wallet', 'انتقال', 'انتقال پول', 'ارسال موجودی', 'کیف پول']],
        '/bot-texts' => ['txp', 'ایموجی پرمیوم متن‌ها', ['ربات', 'متن‌های ربات'], ['premium emoji', 'custom emoji', 'ایموجی پرمیوم', 'اموجی پرمیوم', 'استیکر', 'ایموجی متحرک', 'پرمیوم']],
        '/cashback' => ['cbs', 'کش‌بک', ['تخفیف و جوایز', 'کش‌بک'], ['cashback', 'cash back', 'کش بک', 'کشبک', 'برگشت پول']],
        '/custom-services' => ['css', 'تنظیمات سرویس دلخواه', ['فروشگاه', 'سرویس دلخواه'], ['custom', 'builder', 'دلخواه', 'ساخت سرویس', 'حجم دلخواه']],
        '/discounts' => ['dc', 'کدهای تخفیف', ['تخفیف و جوایز'], ['discount', 'coupon', 'promo', 'تخفیف', 'کد تخفیف', 'کوپن']],
        '/discounts/settings' => ['dcs', 'تنظیمات تخفیف‌ها', ['تخفیف و جوایز', 'کدهای تخفیف'], ['discount', 'coupon', 'تخفیف', 'کد تخفیف', 'مهلت کد']],
        '/gift-codes' => ['gcs', 'کد هدیه در ربات و وب‌سایت', ['تخفیف و جوایز', 'کدهای هدیه'], ['gift', 'gift code', 'voucher', 'هدیه', 'کد هدیه', 'گیفت', 'گیفت کارت']],
        '/lottery' => ['lts', 'قرعه‌کشی شبانه', ['تخفیف و جوایز', 'قرعه‌کشی'], ['lottery', 'draw', 'raffle', 'قرعه کشی', 'لاتاری', 'برنده']],
        '/mini-app-settings/appearance' => ['mal', 'ظاهر مینی‌اپ', [...self::SETTINGS_MENU, 'مینی‌اپ تلگرام'], ['color', 'logo', 'title', 'announcement', 'banner', 'fullscreen', 'theme', 'رنگ', 'لوگو', 'اطلاعیه', 'بنر', 'تمام صفحه', 'تم', 'mini app', 'مینی اپ']],
        '/mini-app-settings/connection' => ['mac', 'اتصال مینی‌اپ', [...self::SETTINGS_MENU, 'مینی‌اپ تلگرام'], ['mini app', 'miniapp', 'web app', 'webapp', 'menu button', 'مینی اپ', 'وب اپ', 'دکمه منو']],
        '/payment-methods' => ['pmd', 'تایید خودکار واریز', ['فروشگاه', 'روش‌های پرداخت'], ['forapp', 'rikhtam', 'sms', 'bank sms', 'auto verify', 'فوراپ', 'فور اپ', 'ریختم', 'پیامک', 'پیامک بانک', 'اس ام اس', 'تایید خودکار', 'تایید واریز', 'درگاه']],
        '/payments' => ['py', 'پرداخت‌ها', ['فروش'], ['payment', 'payments', 'transaction', 'تراکنش', 'پرداخت', 'رسید', 'receipt', 'فیش', 'واریز']],
        '/payments/deposits' => ['dp', 'واریزهای پیامکی', ['فروش'], ['deposit', 'deposits', 'sms', 'bank sms', 'forapp', 'rikhtam', 'واریز', 'پیامک', 'پیامک بانک', 'پیامک واریز', 'اس ام اس', 'فوراپ', 'ریختم']],
        '/referrals' => ['rf', 'زیرمجموعه‌گیری', ['فروش'], ['referral', 'invite', 'affiliate', 'زیرمجموعه', 'رفرال', 'معرف', 'دعوت']],
        '/referrals/alerts' => ['rfa', 'هشدارهای زیرمجموعه‌گیری', ['فروش', 'زیرمجموعه‌گیری'], ['fake', 'fraud', 'alert', 'هشدار', 'فیک', 'تقلب', 'زیرمجموعه فیک']],
        '/referrals/commissions' => ['rfc', 'پورسانت‌ها', ['فروش', 'زیرمجموعه‌گیری'], ['commission', 'پورسانت', 'کمیسیون', 'سود معرف']],
        '/referrals/invitees' => ['rfi', 'زیرمجموعه‌ها', ['فروش', 'زیرمجموعه‌گیری'], ['invitee', 'referred', 'زیرمجموعه']],
        '/settings/advanced' => ['ppv', 'پیشرفته', [...self::SETTINGS_MENU, 'تنظیمات پنل'], ['advanced', 'log', 'session', 'timeout', 'cookie', 'https', 'cron', 'لاگ', 'سشن', 'تایم اوت', 'کوکی', 'کرون']],
        '/settings/app' => ['ppa', 'برنامه', [...self::SETTINGS_MENU, 'تنظیمات پنل'], ['app', 'application', 'url', 'domain', 'timezone', 'debug', 'نام فروشگاه', 'آدرس سایت', 'دامنه', 'منطقه زمانی', 'تایم زون', 'ساعت', 'دیباگ', 'tehran', 'تهران']],
        '/settings/appearance' => ['pp', 'ظاهر پنل', [...self::SETTINGS_MENU, 'تنظیمات پنل'], ['dark', 'light', 'theme', 'dark mode', 'night', 'appearance', 'تم', 'تیره', 'روشن', 'حالت شب', 'دارک مود', 'رنگ پنل']],
        '/settings/backups' => ['ppb', 'پشتیبان‌گیری', [...self::SETTINGS_MENU, 'تنظیمات پنل'], ['backup', 'backups', 'restore', 'dump', 'بک آپ', 'بکاپ', 'بک اپ', 'پشتیبان', 'نسخه پشتیبان', 'فایل پشتیبان', 'بازیابی', 'ریستور', 'بازگردانی']],
        '/settings/database' => ['ppd', 'دیتابیس', [...self::SETTINGS_MENU, 'تنظیمات پنل'], ['database', 'db', 'mysql', 'mariadb', 'sqlite', 'host', 'port', 'prefix', 'دیتابیس', 'دیتا بیس', 'پایگاه داده', 'هاست', 'پورت', 'پیشوند جدول']],
        '/settings/import' => ['ppi', 'انتقال از ربات دیگر', [...self::SETTINGS_MENU, 'تنظیمات پنل'], ['import', 'migrate', 'migration', 'faoxima', 'mirza', 'mirzabot', 'ایمپورت', 'وارد کردن', 'مهاجرت', 'انتقال ربات', 'انتقال از ربات', 'ربات قبلی', 'ربات دیگر', 'ربات میرزا', 'میرزا', 'فاکسیما']],
        '/settings/login' => ['ppl', 'ورود به پنل', [...self::SETTINGS_MENU, 'تنظیمات پنل'], ['login', 'password', 'username', 'admin', 'change password', 'رمز', 'رمز عبور', 'پسورد', 'نام کاربری', 'ورود', 'لاگین', 'تغییر رمز', 'telegram owner', 'manage from bot', 'مدیریت از ربات', 'حساب تلگرام مالک', 'تلگرام مالک', 'وصل کردن تلگرام']],
        '/settings/mail' => ['ppm', 'ایمیل', [...self::SETTINGS_MENU, 'تنظیمات پنل'], ['mail', 'email', 'e-mail', 'smtp', 'resend', 'sendmail', 'ایمیل', 'میل', 'ارسال ایمیل']],
        '/settings/schedule' => ['ppj', 'زمان‌بندی کارها', [...self::SETTINGS_MENU, 'تنظیمات پنل'], ['cron', 'cron job', 'crontab', 'schedule', 'scheduler', 'timer', 'task', 'tasks', 'job', 'کرون', 'کرون جاب', 'زمان‌بند', 'زمان‌بندی', 'تایمر', 'کارها', 'تایید خودکار رسیدها', 'تمدید خودکار', 'همگام‌سازی سرویس‌ها', 'یادآوری‌ها', 'ارسال پشتیبان به گروه', 'بررسی نسخه تازه', 'ارسال گزارش‌ها', 'مهلت سفارش پرداخت‌نشده']],
        '/settings/sms' => ['ppn', 'پیامک', [...self::SETTINGS_MENU, 'تنظیمات پنل'], ['sms', 'otp', 'ippanel', 'pattern', 'پیامک', 'اس ام اس', 'ای پی پنل', 'آی پی پنل', 'کد ورود', 'کد یکبار مصرف', 'پترن', 'الگو', 'سامانه پیامک', 'شماره موبایل', 'اعتبار', 'credit']],
        '/settings/telegram' => ['ppt', 'ربات تلگرام', [...self::SETTINGS_MENU, 'تنظیمات پنل'], ['token', 'bot token', 'botfather', 'webhook', 'polling', 'bot', 'telegram', 'توکن', 'بات فادر', 'وبهوک', 'وب هوک', 'پولینگ', 'بات', 'تلگرام']],
        '/settings/update' => ['ppu', 'به‌روزرسانی', [...self::SETTINGS_MENU, 'تنظیمات پنل'], ['update', 'upgrade', 'version', 'release', 'github', 'rollback', 'آپدیت', 'ورژن', 'نسخه', 'ارتقا', 'گیت هاب', 'نسخه جدید']],
        '/tests' => ['ts', 'اکانت‌های تست', ['فروشگاه'], ['test', 'trial', 'free', 'تست', 'اکانت رایگان', 'رایگان']],
        '/tests/servers' => ['tsr', 'سرورهای اکانت تست', ['فروشگاه', 'اکانت تست'], ['test server', 'سرور تست', 'حجم تست', 'مدت تست']],
        '/tests/settings' => ['tss', 'تنظیمات اکانت تست', ['فروشگاه', 'اکانت تست'], ['test', 'trial', 'free', 'تست', 'اکانت تست', 'رایگان', 'سهمیه', 'quota']],
        '/users' => ['us', 'کاربران', ['فروش'], ['user', 'users', 'customer', 'customers', 'مشتری', 'مشتری‌ها', 'کاربر', 'اعضا', 'member']],
        '/users/groups' => ['ug', 'گروه‌های کاربران', ['فروشگاه'], ['group', 'groups', 'segment', 'گروه مشتری', 'دسته مشتری', 'vip', 'همکار']],
        '/website-settings/connection' => ['wsc', 'اتصال وب‌سایت', [...self::SETTINGS_MENU, 'تنظیمات وب‌سایت'], ['website', 'site', 'api', 'store api', 'cors', 'origin', 'domain', 'key', 'سایت', 'وب سایت', 'دامنه', 'کلید']],
        '/website-settings/email' => ['wse', 'ثبت‌نام با ایمیل', [...self::SETTINGS_MENU, 'تنظیمات وب‌سایت'], ['email', 'signup', 'sign up', 'register', 'captcha', 'turnstile', 'cloudflare', 'altcha', 'ایمیل', 'ثبت نام', 'کپچا', 'تایید امنیتی']],
        '/website-settings/google' => ['wsg', 'ورود مشتری با گوگل', [...self::SETTINGS_MENU, 'تنظیمات وب‌سایت'], ['google', 'oauth', 'client id', 'gmail', 'گوگل', 'جیمیل', 'لاگین گوگل']],
        '/website-settings/phone' => ['wsp', 'ورود مشتری با شماره موبایل', [...self::SETTINGS_MENU, 'تنظیمات وب‌سایت'], ['sms', 'otp', 'phone', 'mobile', 'sms login', 'ورود با پیامک', 'ورود با موبایل', 'شماره موبایل', 'پیامک', 'کد پیامکی', 'اس ام اس', 'موبایل']],
        '/website-settings/reviews' => ['wsr', 'نظرات مشتریان در وب‌سایت', [...self::SETTINGS_MENU, 'تنظیمات وب‌سایت'], ['review', 'reviews', 'comment', 'rating', 'نظر', 'کامنت', 'امتیاز']],
        '/website-settings/staff' => ['wss', 'مدیریت فروشگاه از وب‌سایت', [...self::SETTINGS_MENU, 'تنظیمات وب‌سایت'], ['admin', 'staff', 'permission', 'ادمین', 'مدیر', 'دسترسی', 'نقش']],
        '/website-settings/telegram' => ['wst', 'ورود مشتری با تلگرام', [...self::SETTINGS_MENU, 'تنظیمات وب‌سایت'], ['telegram login', 'login widget', 'oauth', 'client id', 'botfather', 'لاگین تلگرام', 'ورود تلگرام']],
        '/wheel' => ['wh', 'جایزه‌های گردونه', ['تخفیف و جوایز', 'گردونه شانس'], ['wheel', 'spin', 'lucky wheel', 'گردونه', 'شانس', 'چرخونه']],
        '/wheel/settings' => ['whs', 'قوانین گردونه', ['تخفیف و جوایز', 'گردونه شانس'], ['wheel', 'spin', 'lucky', 'گردونه', 'گردونه شانس', 'قوانین گردونه']],
        '/wheel/spins' => ['whn', 'چرخش‌ها', ['تخفیف و جوایز', 'گردونه شانس'], ['spin', 'spins', 'چرخش', 'برنده‌ها']],
    ];

    /** Why the panel's own login is the panel's alone. */
    private const LOGIN = 'نام کاربری و رمز عبور ورود به پنل فقط از خود پنل عوض می‌شوند؛ با ذخیره آن‌جا، دستگاه‌های دیگر از پنل خارج می‌شوند.';

    /**
     * The settings the bot opens otherwise than as their section does, by address and key: `f` the field of that API
     * name on the section's screen (or on `s`), `p` the part of its card, `s` another screen (opened with `a`), `only` why
     * the panel alone sets it (the screen that says so: the section's, or `s`), `main` a field the card has in the main
     * shop alone (found there alone). Its other words, `w`.
     *
     * @var array<string, array<string, array{f?: string, p?: int, s?: string, a?: list<string>, only?: string, main?: bool, w?: list<string>}>>
     */
    private const SETTINGS = [
        '/agents/settings' => [
            'نمایندگی-فعال-باشد' => ['f' => 'enabled'],
            'agency_default_credit' => ['f' => 'default_credit'],
            'agency_traffic_min' => ['f' => 'traffic_min'],
            'agency_traffic_presets' => ['f' => 'traffic_presets'],
            'agency_term_days' => ['f' => 'term_days'],
            'agency_monthly_minimum' => ['f' => 'monthly_minimum'],
            'agency_warn_days' => ['f' => 'warn_days'],
        ],
        '/bot-settings/client-names' => [
            'bot_name_pattern' => ['f' => 'name_pattern'],
            'bot_test_name_pattern' => ['f' => 'test_name_pattern'],
            'نام-رندوم' => ['f' => 'name_random'],
            'نام-دلخواه' => ['f' => 'name_custom'],
        ],
        '/bot-settings/expired-services' => [
            'حذف-خودکار-سرویس-های-منقضی-شده' => ['f' => 'expired_cleanup'],
            'expired_cleanup_hours' => ['f' => 'expired_cleanup_hours'],
        ],
        '/bot-settings/extras' => [
            'فروش-حجم-زمان-و-کاربر-اضافه' => ['f' => 'extras_enabled'],
            'حجم-اضافه' => ['p' => 0],
            'قیمت-هر-گیگابایت-تومان' => ['f' => 'extra_traffic_price'],
            'حجم-های-پیشنهادی' => ['f' => 'extra_traffic_options'],
            'حجم-دلخواه' => ['p' => 0],
            'کمترین-گیگابایت' => ['f' => 'extra_traffic_min'],
            'بیشترین-گیگابایت' => ['f' => 'extra_traffic_max'],
            'زمان-اضافه' => ['p' => 1],
            'قیمت-هر-روز-تومان' => ['f' => 'extra_time_price'],
            'زمان-های-پیشنهادی' => ['f' => 'extra_time_options'],
            'زمان-دلخواه' => ['p' => 1],
            'کمترین-روز' => ['f' => 'extra_time_min'],
            'بیشترین-روز' => ['f' => 'extra_time_max'],
            'کاربر-اضافه' => ['p' => 2],
            'تعدادهای-پیشنهادی' => ['f' => 'extra_devices_options'],
            'تعداد-دلخواه' => ['p' => 2],
            'کمترین-کاربر' => ['f' => 'extra_devices_min'],
            'بیشترین-کاربر' => ['f' => 'extra_devices_max'],
        ],
        '/bot-settings/general' => [
            'ربات-فعال-باشد' => ['f' => 'enabled'],
            'تایید-شماره-موبایل' => ['f' => 'phone_required', 'w' => ['phone', 'contact', 'شماره', 'شماره تلفن']],
            'کجا-پرسیده-شود' => ['f' => 'phone_scope'],
            'کارهایی-که-شماره-می-خواهند' => ['f' => 'phone_on_purchase'],
            'فقط-شماره-های-ایران-98' => ['f' => 'phone_iran_only'],
            'bot_support_contact' => ['f' => 'support_contact', 'w' => ['support', 'support id', 'آیدی پشتیبانی', 'پشتیبانی', 'ادمین']],
            'عنوان-پیام-پشتیبانی' => ['f' => 'support_header'],
        ],
        '/bot-settings/payments' => [
            'جلوگیری-از-اسکرین-شات-و-فوروارد' => ['f' => 'card_protect'],
            'هدایت-پرداخت-کارت-به-کارت-به-پیوی-پشتیبانی' => ['f' => 'card_to_support'],
            'هر-مشتری-فقط-یک-رسید-در-انتظار-بررسی' => ['f' => 'receipt_single'],
        ],
        '/bot-settings/qr' => [
            'ساخت-کد-qr' => ['f' => 'qr_enabled'],
        ],
        '/bot-settings/referral' => [
            'زیرمجموعه-گیری-فعال-باشد' => ['f' => 'referral_enabled'],
            'پورسانت' => ['p' => 0],
            'referral_rate' => ['f' => 'referral_rate'],
            'referral_renewal_rate' => ['f' => 'referral_renewal_rate'],
            'فقط-اولین-پرداخت-هر-زیرمجموعه' => ['f' => 'referral_first_only'],
            'هدیه-شروع' => ['p' => 1],
            'referral_start_referrer' => ['f' => 'referral_start_referrer'],
            'referral_start_invitee' => ['f' => 'referral_start_invitee'],
            'زمان-هدیه' => ['f' => 'referral_start_when'],
            'جایزه-دعوت' => ['p' => 2],
            'referral_reward_every' => ['f' => 'referral_reward_every'],
            'referral_reward_plan_id' => ['f' => 'referral_reward_plan_id'],
            'هشدار-زیرمجموعه-گیری-فیک' => ['p' => 3],
            'referral_alert_burst' => ['f' => 'referral_alert_burst'],
            'referral_alert_minutes' => ['f' => 'referral_alert_minutes'],
            'referral_alert_idle' => ['f' => 'referral_alert_idle'],
        ],
        '/bot-settings/reminders' => [
            'یاداوری-نزدیک-شدن-تاریخ-پایان' => ['f' => 'expiry_reminder'],
            'reminder_days' => ['f' => 'expiry_reminder_days'],
            'یاداوری-تمام-شدن-حجم' => ['f' => 'traffic_reminder'],
            'reminder_percent' => ['f' => 'traffic_reminder_percent'],
        ],
        '/bot-settings/renewal' => [
            'حجم-باقی-مانده-به-دوره-جدید-منتقل-شود' => ['f' => 'carry_traffic'],
            'استانه-مجاز-تمدید' => ['p' => 0],
            'renew_window_days' => ['f' => 'renew_window_days'],
            'renew_window_percent' => ['f' => 'renew_window_percent'],
            'روش-های-تمدید' => ['p' => 1],
            'تمدید-پلن-فعلی' => ['f' => 'renew_extend'],
            'ریست-حجم-افزایش-روز' => ['f' => 'renew_reset_traffic'],
            'ریست-زمان-انتقال-حجم' => ['f' => 'renew_reset_time'],
            'renew_default_method' => ['f' => 'renew_default_method'],
            'تمدید-خودکار' => ['s' => 'bsa'],
            'renewal_days' => ['s' => 'bsa', 'f' => 'auto_renew_days'],
            'برای-سرویس-های-جدید-روشن-باشد' => ['s' => 'bsa', 'f' => 'auto_renew_default'],
        ],
        '/bot-settings/reports' => [
            'بخش-های-گزارش' => ['s' => 'rgt'],
        ],
        '/bot-settings/service-claims' => [
            'مشتری-ها-بتوانند-سرویس-را-با-لینکش-اضافه-کنند' => ['f' => 'claim_enabled'],
        ],
        '/bot-settings/service-transfers' => [
            'مشتری-ها-بتوانند-سرویسشان-را-به-مشتری-دیگری-منتق' => ['f' => 'transfer_enabled'],
        ],
        '/bot-settings/wallet' => [
            'wallet_topup_min' => ['f' => 'topup_min', 'w' => ['minimum top up', 'حداقل شارژ', 'کمترین شارژ', 'کف شارژ']],
            'wallet_topup_presets' => ['f' => 'topup_presets'],
            'دکمه-های-مبلغ-تصادفی' => ['p' => 0],
            'wallet_topup_random_count' => ['f' => 'topup_random_count'],
            'wallet_topup_random_round' => ['f' => 'topup_random_round'],
            'wallet_topup_random_min' => ['f' => 'topup_random_min'],
            'wallet_topup_random_max' => ['f' => 'topup_random_max'],
            'wallet_topup_unique_max' => ['f' => 'topup_unique_max'],
        ],
        '/bot-settings/wallet-transfers' => [
            'مشتری-ها-بتوانند-موجودی-کیف-پولشان-را-به-مشتری-د' => ['f' => 'wallet_transfer_enabled'],
            'wallet_transfer_min' => ['f' => 'wallet_transfer_min'],
            'wallet_transfer_max' => ['f' => 'wallet_transfer_max'],
            'wallet_transfer_daily' => ['f' => 'wallet_transfer_daily'],
        ],
        '/cashback' => [
            'کش-بک-فعال-باشد' => ['f' => 'cashback_enabled'],
        ],
        '/custom-services' => [
            'سرویس-دلخواه-فعال-باشد' => ['f' => 'custom_enabled'],
            'حجم' => ['p' => 0],
            'مدت' => ['p' => 1],
            'سرور-و-کاربر' => ['p' => 2],
            'قیمت-هر-گیگابایت' => ['s' => 'cst', 'a' => ['traffic']],
            'کمترین-گیگابایت' => ['f' => 'custom_traffic_min'],
            'بیشترین-گیگابایت' => ['f' => 'custom_traffic_max'],
            'دکمه-های-پیشنهادی-گیگابایت' => ['f' => 'custom_traffic_options'],
            'قیمت-هر-روز' => ['s' => 'cst', 'a' => ['days']],
            'کمترین-روز' => ['f' => 'custom_days_min'],
            'بیشترین-روز' => ['f' => 'custom_days_max'],
            'دکمه-های-پیشنهادی-روز' => ['f' => 'custom_days_options'],
            'custom_devices' => ['f' => 'custom_devices'],
            'سرورها' => ['f' => 'custom_servers'],
            'ماشین-حساب-قیمت' => ['s' => 'csc'],
            'حجم-گیگابایت' => ['s' => 'csc'],
            'مدت-روز' => ['s' => 'csc'],
        ],
        '/discounts/settings' => [
            'مهلت-پرداخت-با-کد-تخفیف' => ['f' => 'hold_minutes'],
            'discounts_hold' => ['f' => 'hold_minutes'],
            'همکارها' => ['f' => 'agents_without_perks'],
            'همکارها-فقط-با-قیمت-همکاری-بخرند' => ['f' => 'agents_without_perks'],
        ],
        '/gift-codes' => [
            'استفاده-از-کد-هدیه-فعال-باشد' => ['f' => 'gift_codes_enabled'],
        ],
        '/lottery' => [
            'قرعه-کشی-شبانه-فعال-باشد' => ['f' => 'lottery_enabled'],
            'lottery_hour' => ['f' => 'lottery_hour'],
            'lottery_winners' => ['f' => 'lottery_winners'],
            'جایزه-هر-برنده' => ['f' => 'lottery_prize'],
            'lottery_prize_amount' => ['f' => 'lottery_prize'],
            'شانس-شرکت-کننده-ها' => ['f' => 'lottery_entries'],
            'نماینده-ها-شرکت-داده-نشوند' => ['f' => 'lottery_exclude_agents', 'main' => true],
        ],
        '/mini-app-settings/appearance' => [
            'mini_app_title' => ['f' => 'title'],
            'mini_app_accent_color' => ['f' => 'accent_color'],
            'تمام-صفحه-روی-گوشی' => ['f' => 'fullscreen'],
            'لوگو' => ['s' => 'mag'],
            'اطلاعیه' => ['f' => 'announcement'],
            'mini_app_announcement' => ['f' => 'announcement'],
            'mini_app_announcement_url' => ['f' => 'announcement_url'],
            'نظرات-مشتریان-در-مینی-اپ' => ['f' => 'reviews_enabled'],
            'نظرات-در-مینی-اپ' => ['f' => 'reviews_enabled'],
        ],
        '/mini-app-settings/connection' => [
            'مینی-اپ-روشن-باشد' => ['f' => 'enabled'],
            'دکمه-منوی-ربات' => ['f' => 'menu_button'],
            'mini_app_menu_button_text' => ['f' => 'menu_button_text'],
        ],
        '/settings/advanced' => [
            'adv_cron_token' => ['w' => ['cron token', 'توکن کرون', 'کرون از وب']],
            'adv_log_level' => ['w' => ['log level', 'debug log', 'سطح لاگ']],
        ],
        '/settings/app' => [
            'app_timezone' => ['w' => ['timezone', 'time zone', 'تایم زون', 'ساعت', 'tehran', 'تهران']],
            'app_url' => ['w' => ['url', 'domain', 'دامنه', 'آدرس']],
        ],
        '/settings/appearance' => [
            'تم' => ['only' => 'روشن یا تیره بودن پنل فقط به مرورگری مربوط است که پنل را باز کرده؛ ربات همان تم تلگرام خودتان را دارد.'],
        ],
        '/settings/backups' => [
            'ارسال-خودکار-به-گروه-گزارش-ها' => ['w' => ['auto backup', 'بکاپ خودکار', 'پشتیبان خودکار']],
            'بازگردانی' => ['s' => 'ppbr', 'w' => ['restore', 'ریستور', 'بازیابی']],
        ],
        '/settings/login' => [
            'نام-کاربری-و-رمز-عبور' => ['only' => self::LOGIN],
            'login_username' => ['only' => self::LOGIN],
            'login_current_password' => ['only' => self::LOGIN],
            'login_password' => ['only' => self::LOGIN, 'w' => ['password', 'change password', 'رمز', 'پسورد', 'تغییر رمز']],
            'login_password_confirmation' => ['only' => self::LOGIN],
            'مدیریت-از-ربات' => ['s' => 'ol'],
            'owner_telegram_password' => ['s' => 'ol', 'only' => 'ربات برای وصل کردن حساب تلگرام دیگر رمز عبور نمی‌خواهد: مالکی که از همین‌جا مدیریت می‌کند، لینک یک‌بارمصرف را می‌سازد.'],
        ],
        '/settings/mail' => [
            'mail_transport' => ['w' => ['smtp', 'resend', 'sendmail']],
        ],
        '/settings/sms' => [
            'sms_driver' => ['w' => ['ippanel', 'ای پی پنل', 'سامانه پیامک']],
            'sms_test_to' => ['w' => ['test sms', 'پیامک تست', 'تست پیامک', 'اعتبار', 'credit', 'شارژ پیامک']],
        ],
        '/settings/telegram' => [
            'telegram_token' => ['w' => ['token', 'bot token', 'botfather', 'توکن', 'توکن ربات', 'بات فادر']],
            'telegram_webhook_secret' => ['w' => ['webhook secret', 'secret', 'سکرت وبهوک']],
            'telegram_api_url' => ['w' => ['bot api', 'local bot api', 'proxy', 'پروکسی']],
            'دریافت-پیام-ها' => ['s' => 'ppw', 'w' => ['webhook', 'set webhook', 'setwebhook', 'وبهوک', 'وب هوک', 'ثبت وبهوک']],
        ],
        '/tests/settings' => [
            'اکانت-تست-فعال-باشد' => ['f' => 'test_enabled'],
            'سهمیه' => ['p' => 0],
            'حذف-خودکار' => ['p' => 1],
            'test_per_user' => ['f' => 'test_per_user'],
            'test_period_days' => ['f' => 'test_period_days'],
            'test_daily_limit' => ['f' => 'test_daily_limit'],
            'فقط-مشتری-هایی-که-خرید-نکرده-اند' => ['f' => 'test_newcomers_only'],
            'اکانت-های-تمام-شده-حذف-شوند' => ['f' => 'test_cleanup'],
            'test_cleanup_hours' => ['f' => 'test_cleanup_hours'],
            'test_unused_days' => ['f' => 'test_unused_days'],
        ],
        '/website-settings/connection' => [
            'api-وب-سایت-روشن-باشد' => ['f' => 'enabled'],
            'website_url' => ['f' => 'url'],
            'website_origins' => ['f' => 'origins'],
        ],
        '/website-settings/email' => [
            'ثبت-نام-با-ایمیل-و-رمز-عبور' => ['f' => 'email_signup'],
            'تایید-امنیتی' => ['s' => 'wsk'],
            'website_captcha_driver' => ['s' => 'wsk'],
        ],
        '/website-settings/google' => [
            'website_google_client_id' => ['f' => 'google_client_id'],
        ],
        '/website-settings/phone' => [
            'ورود-با-شماره-موبایل-پیامک' => ['f' => 'phone_login'],
        ],
        '/website-settings/reviews' => [
            'نظرات-در-وب-سایت' => ['f' => 'reviews_enabled'],
        ],
        '/website-settings/staff' => [
            'کار-مدیران-از-وب-سایت' => ['f' => 'staff_enabled'],
            'ورود-امن-مدیران' => ['f' => 'staff_strong_sign_in'],
            'کارهای-بیشتر' => ['p' => 0],
            'پلن-ها-و-دسته-بندی-ها' => ['f' => 'grant_catalog'],
            'کیف-پول-مشتری' => ['f' => 'grant_wallet'],
            'بازپرداخت' => ['f' => 'grant_refunds'],
            'رد-یکجای-رسیدها' => ['f' => 'grant_payments'],
            'افزایش-زمان-و-حجم-سرویس' => ['f' => 'grant_extend'],
            'افزودن-سفارش-برای-مشتری' => ['f' => 'grant_manual_orders'],
            'حذف-یا-انتقال-سرویس' => ['f' => 'grant_delete'],
            'امنیت-حساب-مشتری' => ['f' => 'grant_account_security'],
        ],
        '/website-settings/telegram' => [
            'ورود-با-تلگرام' => ['f' => 'telegram_login'],
            'website_telegram_client_id' => ['f' => 'telegram_client_id'],
            'website_telegram_client_secret' => ['f' => 'telegram_client_secret'],
        ],
        '/wheel/settings' => [
            'گردونه-شانس-فعال-باشد' => ['f' => 'wheel_enabled'],
            'wheel_cooldown' => ['f' => 'wheel_cooldown_hours'],
            'wheel_cost' => ['f' => 'wheel_cost'],
            'فقط-مشتری-هایی-که-خرید-کرده-اند' => ['f' => 'wheel_buyers_only'],
        ],
    ];

    /** How well a word of the query was found in a text, worst to best: a letter off, inside a word, its start, the word itself. */
    private const FUZZY = 1;
    private const INFIX = 2;
    private const PREFIX = 3;
    private const EXACT = 4;

    /** What a word found is worth, by where it was found and how well (the panel's search's weights). */
    private const WEIGHTS = [
        'title' => [0, 40, 50, 80, 100],
        'words' => [0, 22, 30, 40, 48],
        'trail' => [0, 0, 18, 26, 30],
        'hint' => [0, 0, 10, 15, 18],
    ];

    /** The whole query is the title, the title starts with it, or it is one of its other words. */
    private const TITLE_IS_QUERY = 120;
    private const TITLE_STARTS_WITH_QUERY = 60;
    private const WORD_IS_QUERY = 50;

    /** Every word of the title found among the words typed — a letter off too: the query is the title («تمدد خودکار»). */
    private const TITLE_COVERED = 40;

    /** Letters typed in their Arabic forms (or with a hamza or madda), as Persian writes them; Persian and Arabic digits as Latin. */
    private const LETTERS = [
        'ي' => 'ی', 'ى' => 'ی', 'ئ' => 'ی', 'ې' => 'ی', 'ك' => 'ک', 'ڪ' => 'ک', 'ة' => 'ه', 'ۀ' => 'ه', 'ھ' => 'ه', 'ہ' => 'ه',
        'أ' => 'ا', 'إ' => 'ا', 'ٱ' => 'ا', 'آ' => 'ا', 'ؤ' => 'و',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];

    /**
     * @var list<SettingsEntry>|null Every entry, made once
     */
    private static ?array $entries = null;

    /**
     * The one card of a section's page under a title of its own, by the section's place among the entries (made with
     * them): what the section is found as besides itself.
     *
     * @var array<int, SettingsEntry>
     */
    private static array $cards = [];

    /**
     * Every entry: each section of the panel's, then its settings, in the panel's order — a card that is its section
     * itself (its title the section's, or the one card of the section's page) is the section's entry already: one result
     * for the one screen both open. The one card under a title of its own is still found as it was, by its title and
     * what it says (find()), and shown as the section.
     *
     * @return list<SettingsEntry>
     */
    public static function entries(): array
    {
        if (self::$entries !== null) {
            return self::$entries;
        }
        $entries = [];
        $cards = [];
        foreach (PanelSettings::SECTIONS as $address => $section) {
            [$screen, $name, $under, $words] = self::section($address) ?? ['', $section['alias'], [], []];
            $heads = array_values(array_filter($section['settings'], static fn(array $setting): bool => $setting['card'] === '' && $setting['part'] === ''));
            $own = count($heads) === 1 && !isset(self::SETTINGS[$address][$heads[0]['key']]) ? $heads[0] : null;
            $at = count($entries);
            $entries[] = new SettingsEntry('', $name, $section['hint'], $under, array_values(array_filter([...$words, $section['alias']], static fn(string $word): bool => $word !== '')), $screen);
            foreach ($section['settings'] as $setting) {
                $place = self::SETTINGS[$address][$setting['key']] ?? [];
                $named = self::normalize($setting['title']) === self::normalize($name);
                $itself = $place === [] && $setting['card'] === '' && ($setting['key'] === ($own['key'] ?? null) || $named);
                if ($itself && $named) {
                    // The card that is the section itself, by its very title: the section's entry already.
                    continue;
                }
                $trail = [...$under, $name, ...array_filter([$setting['card'] === $name ? '' : $setting['card'], $setting['part']], static fn(string $part): bool => $part !== '')];
                $entry = new SettingsEntry(
                    $setting['key'],
                    $setting['title'],
                    $setting['hint'],
                    array_values(array_unique($trail)),
                    $place['w'] ?? [],
                    $place['s'] ?? $screen,
                    $place['a'] ?? [],
                    $place['f'] ?? null,
                    $place['p'] ?? null,
                    $place['only'] ?? '',
                    $words,
                    $place['main'] ?? false,
                );
                if ($itself) {
                    // The one card of the section's page, under a title of its own: found as itself, shown as the section.
                    $cards[$at] = $entry;

                    continue;
                }
                $entries[] = $entry;
            }
        }
        self::$cards = $cards;

        return self::$entries = $entries;
    }

    /**
     * Where the bot has the panel's section of address `$address` (SECTIONS); null for one it has no place for yet — a
     * section the panel added since, which SettingsParityTest names.
     *
     * @return array{0: string, 1: string, 2: list<string>, 3: list<string>}|null
     */
    private static function section(string $address): ?array
    {
        return self::SECTIONS[$address] ?? null;
    }

    /**
     * What `$query` finds among the entries `$opens` keeps (the seat's own screens), best first, at most MOST: every
     * word of it found, the title weighing most, and worth a third of the best at least; equal ones a section first, then
     * the panel's order. A section found better as its one card (entries()) is ranked as that card, once.
     *
     * @param \Closure(SettingsEntry): bool $opens
     * @return list<SettingsEntry>
     */
    public static function find(string $query, \Closure $opens): array
    {
        $terms = self::words(self::normalize($query));
        if ($terms === []) {
            return [];
        }
        $whole = implode(' ', $terms);
        $found = [];
        foreach (self::entries() as $order => $entry) {
            if ($entry->screen === '' || !$opens($entry)) {
                continue;
            }
            $score = self::score($entry, $terms, $whole);
            $rank = $entry->isSection() ? 0 : 1;
            $card = isset(self::$cards[$order]) ? self::score(self::$cards[$order], $terms, $whole) : 0;
            if ($card > $score) {
                [$score, $rank] = [$card, 1];
            }
            if ($score > 0) {
                $found[] = [$score, $rank, $order, $entry];
            }
        }
        usort($found, static fn(array $a, array $b): int => [$b[0], $a[1], $a[2]] <=> [$a[0], $b[1], $b[2]]);
        $least = intdiv((int) ($found[0][0] ?? 0), self::SHARE_OF_BEST);
        $found = array_filter($found, static fn(array $hit): bool => $hit[0] >= $least);

        return array_map(static fn(array $hit): SettingsEntry => $hit[3], array_slice($found, 0, self::MOST));
    }

    /**
     * Text as the search compares it — the panel's normalize(): Persian as one Persian however it was typed (Arabic letters
     * and digits, harakat and tanwin, tatweel, half-spaces), Latin in lower case, punctuation a space between words.
     */
    public static function normalize(string $text): string
    {
        $text = strtr($text, self::LETTERS);
        $text = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}\x{0621}\x{00AD}\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]|\p{M}/u', '', $text);
        $text = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text));

        return trim($text);
    }

    /**
     * What `$entry` is worth for the words `$terms` (the query `$whole`): each word's best place, summed — 0 when one of
     * them is nowhere.
     *
     * @param list<string> $terms
     */
    private static function score(SettingsEntry $entry, array $terms, string $whole): int
    {
        $title = self::normalize($entry->title);
        $words = array_map(self::normalize(...), $entry->words);
        $texts = [
            'title' => [$title],
            'words' => $words,
            'trail' => array_map(self::normalize(...), [...$entry->trail, ...$entry->near]),
            'hint' => [self::normalize($entry->hint)],
        ];
        $total = 0;
        foreach ($terms as $term) {
            $best = 0;
            foreach ($texts as $where => $each) {
                foreach ($each as $text) {
                    $best = max($best, self::WEIGHTS[$where][self::level($term, $text, in_array($where, ['title', 'words'], true))]);
                }
            }
            if ($best === 0) {
                return 0;
            }
            $total += $best;
        }

        $covered = array_filter(self::words($title), static function (string $word) use ($terms): bool {
            foreach ($terms as $term) {
                if (self::level($term, $word, true) > 0) {
                    return true;
                }
            }

            return false;
        });
        if ($title !== $whole && $title !== '' && count($covered) === count(self::words($title))) {
            $total += self::TITLE_COVERED;
        }

        return $total + match (true) {
            $title === $whole => self::TITLE_IS_QUERY,
            str_starts_with($title, $whole) => self::TITLE_STARTS_WITH_QUERY,
            in_array($whole, $words, true) => self::WORD_IS_QUERY,
            default => 0,
        };
    }

    /**
     * How well `$term` is found in `$text`: a word of it, a word's start, inside a word — or across a half-space typed
     * without it («کیفپول») —, a letter off a word (`$fuzzy`: a title's, another word's); 0 for nowhere.
     */
    private static function level(string $term, string $text, bool $fuzzy): int
    {
        if ($text === '') {
            return 0;
        }
        $words = self::words($text);
        if (in_array($term, $words, true)) {
            return self::EXACT;
        }
        foreach ($words as $word) {
            if (str_starts_with($word, $term)) {
                return self::PREFIX;
            }
        }
        if (str_contains($text, $term) || str_contains(str_replace(' ', '', $text), $term)) {
            return self::INFIX;
        }
        if ($fuzzy && mb_strlen($term) >= 4) {
            foreach ($words as $word) {
                if (self::oneOff($term, $word)) {
                    return self::FUZZY;
                }
            }
        }

        return 0;
    }

    /** Whether `$a` is `$b` but for one letter wrong, missing, extra, or two side by side swapped. */
    private static function oneOff(string $a, string $b): bool
    {
        $x = mb_str_split($a);
        $y = mb_str_split($b);
        [$m, $n] = [count($x), count($y)];
        if (abs($m - $n) > 1 || $a === $b) {
            return false;
        }
        $i = 0;
        while ($i < min($m, $n) && $x[$i] === $y[$i]) {
            $i++;
        }
        if ($m === $n) {
            // One letter wrong, or this one and the next swapped.
            return array_slice($x, $i + 1) === array_slice($y, $i + 1)
                || ($i + 1 < $m && $x[$i] === $y[$i + 1] && $x[$i + 1] === $y[$i] && array_slice($x, $i + 2) === array_slice($y, $i + 2));
        }

        // One letter missing from the shorter.
        return $m > $n ? array_slice($x, $i + 1) === array_slice($y, $i) : array_slice($x, $i) === array_slice($y, $i + 1);
    }

    /**
     * The words of a normalised text.
     *
     * @return list<string>
     */
    private static function words(string $normalized): array
    {
        return $normalized === '' ? [] : explode(' ', $normalized);
    }
}
