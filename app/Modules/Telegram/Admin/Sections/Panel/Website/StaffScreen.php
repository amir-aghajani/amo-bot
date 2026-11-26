<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Website;

use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;

/**
 * «مدیران سایت» of the website: whether the bot's admins work the shop from the website (the Store API's /admin), whether
 * they must sign in strongly, and the grants beyond the daily work — a part of their own («کارهای بیشتر») —, with the
 * panel's word that the guards stop an admin only on their own account folded under it.
 */
final class StaffScreen extends WebsiteCard
{
    public const ID = 'wss';

    /** Each grant's switch and what it lets the admins do on the website, in the grants' order (the panel's words). */
    private const GRANTS = [
        'catalog' => ['پلن‌ها و دسته‌بندی‌ها', 'ساختن، ویرایش، مرتب کردن، روشن و خاموش کردن و حذف پلن‌ها و دسته‌بندی‌ها؛ و گروه‌های مشتری: ساختن، ویرایش، مرتب کردن و حذف آن‌ها و گذاشتن مشتری در گروه «همکار» (قیمت نماینده).'],
        'wallet' => ['کیف پول مشتری', 'افزایش و کم کردن موجودی کیف پول مشتری.'],
        'refunds' => ['بازپرداخت', 'بازپرداخت پرداخت مشتری، همان «بازپرداخت» صفحه پرداخت‌ها.'],
        'payments' => ['رد یکجای رسیدها', 'رد کردن یکجای رسیدهای در انتظار بررسی و پاک کردن عکسشان؛ رد کردن رسیدها یکی‌یکی همیشه ممکن است.'],
        'extend' => ['افزایش زمان و حجم سرویس', 'دادن روز و حجم بیشتر به سرویس یک مشتری.'],
        'manual_orders' => ['افزودن سفارش برای مشتری', 'ساختن سرویس برای مشتری به صورت رایگان، از کیف پولش یا با پولی که بیرون از فروشگاه گرفته شده؛ بلافاصله تحویل می‌شود.'],
        'delete' => ['حذف یا انتقال سرویس', 'حذف همیشگی سرویس مشتری از سرورش و از فروشگاه، یا انتقال آن به حساب مشتری دیگر.'],
        'account_security' => ['امنیت حساب مشتری', 'خاموش کردن ورود دو مرحله‌ای حساب وب‌سایت مشتری و خارج کردنش از همه دستگاه‌ها.'],
    ];

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '🛡 مدیریت فروشگاه از وب‌سایت';
    }

    protected function intro(): string
    {
        return implode("\n\n", [
            'مدیران ربات با حساب وب‌سایت خودشان کارهای روزانه فروشگاه را در وب‌سایت انجام می‌دهند: رسیدها و پرداخت‌ها، سفارش‌ها، سرویس‌ها، تیکت‌ها، مشتری‌ها و ارسال‌های همگانی. روش‌های پرداخت، ربات، گروه گزارش‌ها، وب‌سایت و اینکه چه کسی مدیر است در وب‌سایت نیست: از پنل تغییر می‌کند، یا مدیر ربات آن را از «⚙️ مدیریت» ربات تغییر می‌دهد. ورود هر مدیر تا ۱۲ ساعت برای کار مدیریتی معتبر است و بعد از آن باید ورودش را دوباره تایید کند؛ تایید پرداخت هم، چون سرویس تحویل می‌دهد، ورود ۱۵ دقیقه گذشته را می‌خواهد.',
            'ℹ️ مدیران ربات همان کاربرانی هستند که نقش مدیر دارند؛ آن‌ها را در «👥 کاربران» ببینید و از صفحه هر کاربر، مدیر ربات کنید یا مدیریت را بردارید.',
            '⚠️ هر مدیر فقط درباره حساب خودش محدود است: پرداخت، کیف پول، سرویس و نظر خودش با مدیر دیگری است و حساب مدیران و نماینده‌ها در وب‌سایت تغییر نمی‌کند. ولی دو مدیر این کارها را برای هم انجام می‌دهند؛ پس نقش مدیر را فقط به کسانی بدهید که به آن‌ها اعتماد دارید.',
            '<b>کارهای بیشتر</b>: این کارها فقط وقتی از وب‌سایت انجام می‌شود که روشنشان کنید، و مدیر برای هر کدام باید در ۱۵ دقیقه گذشته وارد شده یا ورودش را دوباره تایید کرده باشد.',
        ]);
    }

    protected function fields(): array
    {
        $fields = [
            FieldDef::toggle('staff_enabled', 'کار مدیران از وب‌سایت', 'خاموش باشد، وب‌سایت هیچ کار مدیریتی را نمی‌پذیرد.'),
            FieldDef::toggle('staff_strong_sign_in', 'ورود امن مدیران', 'کار مدیریتی فقط از ورودی پذیرفته می‌شود که با تلگرام، گوگل، یا رمز عبور یا کد پیامکی همراه کد ورود دو مرحله‌ای بوده است؛ کد پیامکی تنها کافی نیست. خاموش باشد، رمز عبور تنها هم کافی است.'),
        ];
        foreach (self::GRANTS as $grant => [$label, $hint]) {
            $fields[] = FieldDef::toggle(WebsiteSource::GRANT . $grant, $label, $hint);
        }

        return $fields;
    }

    /** The grants, a part of their own (the panel's «کارهای بیشتر»). */
    protected function parts(): array
    {
        return [['➕ کارهای بیشتر', array_map(static fn(string $grant): string => WebsiteSource::GRANT . $grant, array_keys(self::GRANTS))]];
    }
}
