<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Config;

use App\Modules\Telegram\Admin\Sections\Panel\Forms\DraftForm;

/** «برنامه» of config.php: the shop's name and public address (its sub-folder in it), the zone its times are shown in, and the debug switch. */
final class AppScreen extends ConfigScreen
{
    public const ID = 'ppa';

    public const TITLE = '🏷 برنامه';

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return self::TITLE;
    }

    protected function group(): string
    {
        return 'app';
    }

    protected function done(): string
    {
        return '✅ تنظیمات برنامه ذخیره شد.';
    }

    protected function formOf(array $config): DraftForm
    {
        $zones = [];
        foreach (is_array(self::meta($config)['timezones'] ?? null) ? self::meta($config)['timezones'] : [] as $zone) {
            if (is_array($zone) && is_string($zone['id'] ?? null)) {
                $zones[$zone['id']] = is_string($zone['label'] ?? null) ? $zone['label'] : $zone['id'];
            }
        }

        return DraftForm::fixed([
            DraftForm::field('name', 'text', 'نام برنامه', 'در عنوان صفحه‌ها و پیام‌های ربات.', required: true),
            DraftForm::field('url', 'url', 'آدرس سایت', "بدون / در انتها، با زیرپوشه اگر برنامه در زیرپوشه نصب شده؛ مثل \u{2068}https://shop.example.com\u{2069}. \u{2068}Webhook\u{2069} تلگرام به https نیاز دارد و بعد از عوض کردن آدرس، تا آن را در «🛰 دریافت پیام‌ها» دوباره ثبت نکنید، تلگرام آدرس قبلی را صدا می‌زند.", required: true),
            DraftForm::field('timezone', 'choice', 'منطقه زمانی', 'تاریخ و ساعت‌هایی که پنل‌ها و ربات نشان می‌دهند، روزهای داشبورد و لاگ‌ها به این منطقه زمانی هستند؛ تغییرش زمان‌های ثبت‌شده را جابه‌جا نمی‌کند.', required: true, options: $zones),
            DraftForm::field('debug', 'toggle', "حالت \u{2068}Debug\u{2069}", 'روشن باشد، جواب هر خطای پیش‌بینی‌نشده سرور جزئیات فنی دارد: نوع خطا، متن آن و فایل‌ها و خط‌های کدی که به خطا رسید. این جزئیات فقط به درخواستی می‌رسد که مستقیم از خود همین سرور (localhost) فرستاده شود؛ مرورگر شما و هیچ بازدیدکننده دیگری آن را نمی‌بیند. فقط برای عیب‌یابی روشن کنید.', default: false),
        ], self::groupOf($config, 'app'));
    }
}
