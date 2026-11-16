<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Website;

use App\Modules\Telegram\Admin\Sections\Panel\Forms\DraftForm;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;

/** «ورود با گوگل» of the website: the site's OAuth Client ID from Google Cloud (emptied: Google sign-in off), with the steps to make one folded under it. */
final class GoogleLoginScreen extends WebsiteCard
{
    public const ID = 'wsg';

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '🔵 ورود مشتری با گوگل';
    }

    protected function intro(): string
    {
        $url = is_string($this->drawn()['url'] ?? null) ? $this->drawn()['url'] : '';

        return implode("\n", [
            'مشتری با حساب گوگلش وارد وب‌سایت می‌شود؛ اگر با همان ایمیل در فروشگاه حساب داشته باشد به همان حساب، وگرنه حساب تازه‌ای برایش ساخته می‌شود.',
            '',
            "<b>راه‌اندازی در \u{2068}Google Cloud\u{2069}</b>",
            "۱. در \u{2068}Google Cloud Console\u{2069} به \u{2068}APIs &amp; Services › Credentials\u{2069} بروید.",
            "۲. \u{2068}Create Credentials › OAuth client ID\u{2069} را بزنید و نوع \u{2068}Web application\u{2069} را انتخاب کنید.",
            ($url === ''
                ? "۳. اول آدرس وب‌سایت را در بخش «🔌 اتصال» ذخیره کنید، بعد آن را در \u{2068}Authorized JavaScript origins\u{2069} اضافه کنید"
                : '۳. آدرس وب‌سایت، ' . Fmt::ltr(DraftForm::origin($url)) . "، را در \u{2068}Authorized JavaScript origins\u{2069} اضافه کنید")
                . "؛ \u{2068}Origin\u{2069}های دیگر وب‌سایت را هم، اگر ورود با گوگل روی آن‌ها هم لازم است.",
            "۴. \u{2068}Create\u{2069} را بزنید و \u{2068}Client ID\u{2069} را که \u{2068}Google Cloud\u{2069} نشان می‌دهد این‌جا وارد کنید.",
        ]);
    }

    protected function fields(): array
    {
        return [
            FieldDef::text('google_client_id', "\u{2068}Client ID\u{2069} گوگل", 'خالی بماند، ورود با گوگل خاموش است.')->nullable()->example('1234567890-abc123.apps.googleusercontent.com'),
        ];
    }

    protected function shows(FieldDef $field, mixed $value): string
    {
        return is_string($value) && $value !== '' ? Fmt::code($value) : '';
    }
}
