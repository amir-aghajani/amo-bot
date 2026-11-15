<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Website;

use App\Modules\Telegram\Admin\Sections\Panel\Access;
use App\Modules\Telegram\Admin\Sections\Panel\Config\MailScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «ورود با ایمیل» of the website: email sign-up — held off while no email goes out, its code would reach nobody (said
 * with where the owner sets the email up) — and the captcha guarding the website's forms (CaptchaScreen).
 */
final class EmailScreen extends WebsiteCard
{
    public const ID = 'wse';

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '📧 ثبت‌نام با ایمیل';
    }

    protected function intro(): string
    {
        return 'مشتری‌ای که از قبل با ایمیل حساب دارد، هر وضعیتی که این گزینه داشته باشد، با ایمیل و رمز عبورش وارد می‌شود و رمز فراموش‌شده‌اش را بازیابی می‌کند؛ این گزینه فقط ثبت‌نام تازه را باز یا بسته می‌کند.';
    }

    protected function lead(array $values): array
    {
        if ((self::part($values, 'email')['mail_ready'] ?? false) === true) {
            return [];
        }
        $kept = Json::on($values, 'email_signup');
        $where = $this->here !== null && $this->here->seat->isOwner()
            ? 'ارسال ایمیل را در «تنظیمات پنل › ایمیل» راه بیندازید.'
            : 'ارسال ایمیل را مالک فروشگاه راه می‌اندازد.';

        return ['', '⚠️ ارسال ایمیل راه نیفتاده است و کد ثبت‌نام بدون آن به مشتری نمی‌رسد؛ تا راه نیفتد، ' . ($kept ? 'کسی نمی‌تواند با ایمیل ثبت‌نام کند.' : 'این گزینه روشن نمی‌شود.') . ' ' . $where];
    }

    protected function fields(): array
    {
        return [
            FieldDef::toggle('email_signup', 'ثبت‌نام با ایمیل و رمز عبور', 'یک کد به آدرسی که مشتری وارد می‌کند فرستاده می‌شود و حسابش وقتی ساخته می‌شود که همان کد را در وب‌سایت وارد کند.'),
        ];
    }

    protected function extra(View $view, Admin $admin, array $values): void
    {
        $captcha = self::part($values, 'captcha');
        $view->line()
            ->line('<b>🛡 تایید امنیتی</b>: ' . Fmt::e(CaptchaScreen::driverLabel($captcha)))
            ->line('<i>فرم‌های وب‌سایت را در برابر درخواست‌های خودکار ربات‌ها نگه می‌دارد.</i>')
            ->row(Button::to('🛡 تایید امنیتی', CaptchaScreen::ID));
        if ((self::part($values, 'email')['mail_ready'] ?? false) !== true && Access::main($admin->seat)) {
            $view->row(Button::to('📧 تنظیمات ایمیل پنل', MailScreen::ID));
        }
    }
}
