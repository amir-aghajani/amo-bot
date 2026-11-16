<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Config;

use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Panel\Forms\DraftForm;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «پیامک» of config.php: how the websites' sign-in codes go out by SMS — none or an SMS driver drawn from its
 * description — and whether agents' websites may send them too, on the owner's account; then the account's credit,
 * read from its provider on a tap, and a test code sent by the settings saved, the code shown to match the SMS against.
 *
 * Actions besides the form's: `credit` (read), `st` (asks the number), its answer.
 */
final class SmsScreen extends ConfigScreen
{
    public const ID = 'ppn';

    /** No SMS at all: the first choice, and what it means. */
    private const NONE = [
        'label' => 'خاموش',
        'hint' => 'پیامکی فرستاده نمی‌شود: ورود با شماره موبایل در وب‌سایت‌ها روشن نمی‌شود.',
    ];

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '📨 ارسال پیامک';
    }

    protected function group(): string
    {
        return 'sms';
    }

    protected function chooseLabel(): string
    {
        return 'روش ارسال';
    }

    protected function done(): string
    {
        return '✅ تنظیمات پیامک ذخیره شد.';
    }

    protected function formOf(array $config): DraftForm
    {
        $group = self::groupOf($config, 'sms');
        $kept = Json::part($group, 'values');
        $drivers = DraftForm::drivers(Json::rows($group, 'drivers'));
        $stored = [];
        foreach ($drivers as $key => $driver) {
            $drivers[$key]['fields'][] = DraftForm::field('agents', 'toggle', 'سایت نماینده‌ها هم بتوانند کد ورود پیامکی بفرستند', 'هزینه هر پیامک، از وب‌سایت هر فروشگاهی که باشد، از اعتبار همین حساب پیامک شما کم می‌شود.', default: false);
            $stored[$key] = (is_array($kept[$key] ?? null) ? $kept[$key] : []) + ['agents' => Json::on($group, 'agents')];
        }

        return new DraftForm('driver', $drivers, is_string($group['driver'] ?? null) ? $group['driver'] : DraftForm::NONE, $stored, self::NONE);
    }

    protected function intro(Admin $admin, DraftForm $form, string $driver): array
    {
        return [...parent::intro($admin, $form, $driver), 'کد ورود با شماره موبایل در وب‌سایت‌ها با این تنظیمات فرستاده می‌شود. متن پیامک همان متن الگویی است که در پنل سامانه پیامک تایید شده؛ فروشگاه فقط کد را می‌فرستد.'];
    }

    protected function extra(View $view, Admin $admin, DraftForm $form, string $driver, array $values): void
    {
        $state = $admin->state(self::ID);
        $view->section('📱 ارسال پیامک آزمایشی')
            ->card([
                'آخرین کد آزمایشی' => is_string($state['sent'] ?? null) ? Fmt::code($state['sent']) . ' — همین کد باید با پیامک روی گوشی برسد' : null,
                'اعتبار حساب' => $form->saved !== DraftForm::NONE && is_string($state['credit'] ?? null) ? $state['credit'] : null,
            ])
            ->note('یک کد آزمایشی با تنظیمات ذخیره‌شده و همان الگوی کد ورود فرستاده می‌شود و هزینه‌اش از اعتبار حساب کم می‌شود؛ تغییرات را اول ذخیره کنید.')
            ->row(
                Button::to('📱 ارسال پیامک آزمایشی', self::ID, 'st'),
                $form->saved !== DraftForm::NONE ? Button::to('💰 اعتبار حساب', self::ID, 'credit') : null,
            );
    }

    protected function action(Admin $admin, string $action, array $args, DraftForm $form, string $driver, array $values): View
    {
        switch ($action) {
            case 'st':
                return $admin->ask((new Prompt('شماره موبایل گیرنده', self::ID, 'st', [], 'کد آزمایشی به کدام شماره برود؟'))->example('09121234567'));
            case 'credit':
                try {
                    $credit = $admin->api->get('/settings/config/sms/credit')['credit'] ?? null;
                    $line = is_array($credit)
                        ? Fmt::number((int) ($credit['amount'] ?? 0)) . ' ' . Fmt::e((string) ($credit['unit'] ?? ''))
                        : 'این سامانه پیامک اعتبار حساب را نمی‌گوید';
                } catch (ApiRefusal $refusal) {
                    $line = '⚠️ خوانده نشد: ' . Fmt::e($refusal->getMessage());
                }
                $admin->remember(self::ID, ['credit' => $line]);
                break;
        }

        return parent::action($admin, $action, $args, $form, $driver, $values);
    }

    protected function typed(Admin $admin, string $action, array $args, Answer $answer, DraftForm $form): View
    {
        if ($action === 'st') {
            $sent = $admin->api->post('/settings/config/sms/test', ['to' => $answer->latin()]);
            // The credit read before is the credit before the test.
            $admin->remember(self::ID, ['sent' => is_scalar($sent['code'] ?? null) ? (string) $sent['code'] : '', 'credit' => null]);
            $admin->notice('✅ پیامک آزمایشی فرستاده شد.');
        }

        return $this->card($admin, $form);
    }

    protected function forgetDraft(Admin $admin): void
    {
        parent::forgetDraft($admin);
        // Saved: the credit and the test were of the settings before.
        $admin->remember(self::ID, ['credit' => null, 'sent' => null]);
    }
}
