<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Website;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Panel\Forms\DraftForm;
use App\Modules\Telegram\Admin\Sections\Panel\Forms\DraftScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «تایید امنیتی» of the website (under «ورود با ایمیل», as the panel has it): none, or a captcha driver with its form drawn
 * from its description — a kept secret bound to the site key, typed again with a new one —, saved whole by PATCH
 * /website `captcha`; refusals come under `captcha.<field>`.
 */
final class CaptchaScreen extends DraftScreen
{
    public const ID = 'wsk';

    /** No captcha: the first choice, and what it means. */
    private const NONE = [
        'label' => 'خاموش',
        'hint' => 'فرم‌های وب‌سایت تایید امنیتی نمی‌خواهند؛ ثبت‌نام، ورود با رمز عبور، بازیابی رمز و ثبت نظر فقط با محدودیت تعداد درخواست‌ها در برابر ربات‌ها نگه داشته می‌شوند.',
    ];

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    /**
     * The captcha the website uses, by name: its driver's label, «خاموش» for none.
     *
     * @param array<string, mixed> $captcha The website's `captcha`
     */
    public static function driverLabel(array $captcha): string
    {
        $driver = $captcha['driver'] ?? DraftForm::NONE;

        return DraftForm::drivers(Json::rows($captcha, 'drivers'))[$driver]['label'] ?? self::NONE['label'];
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '🛡 تایید امنیتی وب‌سایت';
    }

    protected function path(): array
    {
        return ['مدیریت', 'تنظیمات وب‌سایت', 'ورود با ایمیل'];
    }

    protected function parent(): string
    {
        return EmailScreen::ID;
    }

    protected function chooseLabel(): string
    {
        return 'نوع تایید امنیتی';
    }

    protected function done(): string
    {
        return '✅ تنظیمات وب‌سایت ذخیره شد.';
    }

    protected function form(Admin $admin): DraftForm
    {
        $website = (array) ($admin->api->get('/website')['website'] ?? []);
        $captcha = Json::part($website, 'captcha');
        $values = Json::part($captcha, 'values');
        $driver = is_string($captcha['driver'] ?? null) ? $captcha['driver'] : DraftForm::NONE;

        return new DraftForm('driver', DraftForm::drivers(Json::rows($captcha, 'drivers')), $driver, array_map(static fn(mixed $kept): array => is_array($kept) ? $kept : [], $values), self::NONE);
    }

    protected function submit(Admin $admin, array $payload): void
    {
        $admin->api->patch('/website', ['captcha' => $payload]);
    }

    protected function errorField(string $key): string
    {
        return str_starts_with($key, 'captcha.') ? substr($key, strlen('captcha.')) : $key;
    }

    protected function intro(Admin $admin, DraftForm $form, string $driver): array
    {
        return ['فرم‌های وب‌سایت را در برابر درخواست‌های خودکار ربات‌ها نگه می‌دارد.'];
    }

    protected function extra(View $view, Admin $admin, DraftForm $form, string $driver, array $values): void
    {
        if ($driver === DraftForm::NONE) {
            return;
        }
        $view->line()->line(
            "<i>ثبت‌نام، ورود با رمز عبور، بازیابی رمز عبور، درخواست کد پیامکی و ثبت نظر از وب‌سایت، توکن ویجت را هم می‌خواهند (فیلد \u{2068}captcha\u{2069})، هر کدام برای \u{2068}action\u{2069} خودش: "
            . "\u{2068}sign_up\u{2069}، \u{2068}sign_in\u{2069}، \u{2068}password_reset\u{2069}، \u{2068}phone_sign_in\u{2069} و \u{2068}review\u{2069}. "
            . "فرم‌های دیگر وب‌سایت، مثل فرم تماس با ما، را سرور وب‌سایت با \u{2068}POST /captcha/verify\u{2069} از فروشگاه می‌پرسد؛ کلید مخفی فقط نزد فروشگاه است.</i>",
        );
    }
}
