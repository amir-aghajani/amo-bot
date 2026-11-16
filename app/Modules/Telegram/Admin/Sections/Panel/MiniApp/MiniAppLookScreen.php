<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\MiniApp;

use App\Modules\Telegram\Admin\Sections\Support\ReviewsScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «ظاهر» of the mini app — the panel's cards of it in one: its name (the shop's while empty), its colour (Telegram's
 * while empty) and full screen, the shop's announcement on its home screen and where it leads, and whether it shows and
 * takes reviews; the logo is a screen of its own (LogoScreen).
 */
final class MiniAppLookScreen extends MiniAppCard
{
    public const ID = 'mal';

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '🎨 ظاهر مینی‌اپ';
    }

    protected function intro(): string
    {
        return 'نام و رنگی که مشتری در مینی‌اپ می‌بیند؛ بقیه رنگ‌ها از تم تلگرام خود مشتری می‌آیند، روشن یا تیره. هر نظر تا وقتی در «' . Icons::REVIEWS . ' نظرات» تایید نشود، در مینی‌اپ دیده نمی‌شود؛ مشتری در مینی‌اپ همیشه با حساب تلگرامش وارد است، پس هر نظر به نام یک مشتری است.';
    }

    protected function fields(): array
    {
        return [
            FieldDef::text('title', 'نام مینی‌اپ', 'بالای مینی‌اپ نشان داده می‌شود؛ خالی بماند، نام فروشگاه.')->nullable()->limits('حداکثر ۶۴ نویسه'),
            FieldDef::text('accent_color', 'رنگ اصلی', 'رنگ دکمه‌ها و بخش‌های برجسته؛ خالی بماند، رنگ دکمه‌های تلگرام هر مشتری.')->nullable()->example('#2481cc'),
            FieldDef::toggle('fullscreen', 'تمام‌صفحه روی گوشی', 'روشن باشد، مینی‌اپ روی گوشی همه صفحه را می‌گیرد؛ خاموش باشد، مثل یک برگه از پایین صفحه باز می‌شود.'),
            FieldDef::textarea('announcement', 'متن اطلاعیه', 'پیامی برای همه مشتری‌ها در صفحه اول مینی‌اپ؛ مثلا خبر یک تخفیف یا قطعی برنامه‌ریزی‌شده. خالی بماند، اطلاعیه‌ای نشان داده نمی‌شود.')->nullable()->limits('حداکثر ۵۰۰ نویسه'),
            FieldDef::url('announcement_url', 'لینک اطلاعیه', "با زدن اطلاعیه باز می‌شود؛ فقط آدرس \u{2068}https\u{2069}.")->nullable()->example('https://t.me/channel')->limits('حداکثر ۲۵۵ نویسه'),
            FieldDef::toggle('reviews_enabled', 'نظرات در مینی‌اپ', 'روشن باشد، مینی‌اپ نظرهای تاییدشده را نشان می‌دهد و نظر تازه می‌گیرد؛ خاموش باشد، هیچ‌کدام.'),
        ];
    }

    /** The colour, to copy; empty, whose it is instead. */
    protected function shows(FieldDef $field, mixed $value): ?string
    {
        if ($field->name !== 'accent_color') {
            return null;
        }

        return is_string($value) && $value !== '' ? Fmt::code($value) : 'رنگ تلگرام هر مشتری';
    }

    protected function extra(View $view, Admin $admin, array $values): void
    {
        $view->row(Button::to('🖼 لوگو', LogoScreen::ID), Button::to(Icons::REVIEWS . ' نظرات', ReviewsScreen::ID));
    }
}
