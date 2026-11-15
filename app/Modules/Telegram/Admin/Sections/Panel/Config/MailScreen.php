<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Config;

use App\Modules\Telegram\Admin\Sections\Panel\Forms\DraftForm;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «ایمیل» of config.php: how the shop sends email (the websites' codes and resets, notices to customers Telegram cannot
 * reach) — none or a mail driver drawn from its description — and the sender; a kept SMTP password is bound to its
 * server and account. «📧 ارسال ایمیل تست» sends one by the settings saved.
 *
 * Actions besides the form's: `mt` (asks the address), its answer.
 */
final class MailScreen extends ConfigScreen
{
    public const ID = 'ppm';

    /** No email at all: the first choice, and what it means. */
    private const NONE = [
        'label' => 'خاموش',
        'hint' => 'ایمیلی فرستاده نمی‌شود: ثبت‌نام با ایمیل در وب‌سایت‌ها روشن نمی‌شود، کد بازیابی رمز عبور به مشتری نمی‌رسد و مشتری‌هایی که تلگرام ندارند یا ربات را بسته‌اند اعلان‌های فروشگاه را با ایمیل نمی‌گیرند.',
    ];

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '📧 ارسال ایمیل';
    }

    protected function group(): string
    {
        return 'mail';
    }

    protected function chooseLabel(): string
    {
        return 'روش ارسال';
    }

    protected function done(): string
    {
        return '✅ تنظیمات ایمیل ذخیره شد.';
    }

    protected function formOf(array $config): DraftForm
    {
        $group = self::groupOf($config, 'mail');
        $kept = Json::part($group, 'values');
        $sender = ['from_address' => $group['from_address'] ?? '', 'from_name' => $group['from_name'] ?? ''];
        $drivers = DraftForm::drivers(Json::rows($group, 'drivers'));
        $stored = [];
        foreach ($drivers as $key => $driver) {
            // The sender, whatever the driver — its address in the words the driver has for it.
            $drivers[$key]['fields'][] = DraftForm::field('from_address', 'email', 'ایمیل فرستنده', is_string($driver['traits']['sender'] ?? null) ? $driver['traits']['sender'] : '', required: true);
            $drivers[$key]['fields'][] = DraftForm::field('from_name', 'text', 'نام فرستنده', 'خالی بماند، نام هر فروشگاه: نام برنامه برای فروشگاه اصلی و نام ربات برای فروشگاه هر نماینده.');
            $stored[$key] = (is_array($kept[$key] ?? null) ? $kept[$key] : []) + $sender;
        }

        return new DraftForm('transport', $drivers, is_string($group['transport'] ?? null) ? $group['transport'] : DraftForm::NONE, $stored, self::NONE);
    }

    protected function intro(Admin $admin, DraftForm $form, string $driver): array
    {
        return [...parent::intro($admin, $form, $driver), 'کدهای ثبت‌نام و بازیابی رمز عبور وب‌سایت‌ها، وب‌سایت فروشگاه اصلی و نماینده‌ها، و اعلان‌های فروشگاه به مشتری‌هایی که تلگرام ندارند یا ربات را بسته‌اند، با این تنظیمات فرستاده می‌شوند.'];
    }

    protected function extra(View $view, Admin $admin, DraftForm $form, string $driver, array $values): void
    {
        $view->line()->line('<i>«📧 ارسال ایمیل تست»: یک ایمیل کوتاه با تنظیمات ذخیره‌شده فرستاده می‌شود؛ تغییرات را اول ذخیره کنید.</i>')
            ->row(Button::to('📧 ارسال ایمیل تست', self::ID, 'mt'));
    }

    protected function action(Admin $admin, string $action, array $args, DraftForm $form, string $driver, array $values): View
    {
        if ($action === 'mt') {
            return $admin->ask(new Prompt('ایمیل گیرنده', self::ID, 'mt', [], "ایمیل تست به کدام آدرس برود؟ مثل \u{2068}you@example.com\u{2069}"));
        }

        return parent::action($admin, $action, $args, $form, $driver, $values);
    }

    protected function typed(Admin $admin, string $action, array $args, Answer $answer, DraftForm $form): View
    {
        if ($action === 'mt') {
            $admin->api->post('/settings/config/mail/test', ['to' => $answer->latin()]);
            $admin->notice('✅ ایمیل تست فرستاده شد؛ صندوق ورودی و پوشه Spam را نگاه کنید.');
        }

        return $this->card($admin, $form);
    }
}
