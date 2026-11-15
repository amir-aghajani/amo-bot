<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Config;

use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Panel\Forms\DraftForm;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «دیتابیس» of config.php: the database's driver and its form (another driver to switch to when one is offered), tried
 * with «🧪 تست اتصال» — and by the save itself, which keeps nothing a database does not answer to.
 *
 * Actions besides the form's: `test`.
 */
final class DatabaseScreen extends ConfigScreen
{
    public const ID = 'ppd';

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '🗄 دیتابیس';
    }

    protected function group(): string
    {
        return 'database';
    }

    protected function chooseLabel(): string
    {
        return 'نوع دیتابیس';
    }

    protected function done(): string
    {
        return '✅ اتصال دیتابیس ذخیره شد.';
    }

    protected function formOf(array $config): DraftForm
    {
        $group = self::groupOf($config, 'database');
        $driver = is_string($group['driver'] ?? null) ? $group['driver'] : '';

        return new DraftForm('driver', DraftForm::drivers(Json::rows($group, 'drivers')), $driver, [$driver => Json::part($group, 'values')]);
    }

    protected function intro(Admin $admin, DraftForm $form, string $driver): array
    {
        $lines = [...parent::intro($admin, $form, $driver), 'قبل از ذخیره، اتصال با مقادیر جدید تست می‌شود و اگر برقرار نشود چیزی تغییر نمی‌کند.'];
        $test = $admin->state(self::ID)['test'] ?? null;
        if (is_string($test)) {
            $lines[] = '';
            $lines[] = $test;
        }

        return $lines;
    }

    protected function extra(View $view, Admin $admin, DraftForm $form, string $driver, array $values): void
    {
        $view->row(Button::to('🧪 تست اتصال', self::ID, 'test'));
    }

    protected function action(Admin $admin, string $action, array $args, DraftForm $form, string $driver, array $values): View
    {
        if ($action !== 'test') {
            return parent::action($admin, $action, $args, $form, $driver, $values);
        }
        try {
            $database = (array) ($admin->api->post('/settings/config/database/test', $form->payload($driver, $values, $this->crypt))['database'] ?? []);
            $admin->remember(self::ID, ['test' => '✅ <b>اتصال برقرار شد:</b> ' . Fmt::ltr((string) ($database['version'] ?? '')) . ' · ' . Fmt::number((int) ($database['tables'] ?? 0)) . ' جدول']);
            $admin->notice('✅ اتصال برقرار شد.');
        } catch (ApiRefusal $refusal) {
            $words = $refusal->field('connection') ?? (array_values($refusal->errors)[0][0] ?? $refusal->getMessage());
            $admin->remember(self::ID, ['test' => '⚠️ ' . Fmt::e($words)]);
            $admin->alert('اتصال برقرار نشد.');
        }

        return $this->card($admin, $form);
    }

    protected function keep(Admin $admin, string $driver, array $values, array $errors): void
    {
        parent::keep($admin, $driver, $values, $errors);
        // What the test said was about the values it tried.
        $admin->remember(self::ID, ['test' => null]);
    }

    protected function forgetDraft(Admin $admin): void
    {
        parent::forgetDraft($admin);
        $admin->remember(self::ID, ['test' => null]);
    }
}
