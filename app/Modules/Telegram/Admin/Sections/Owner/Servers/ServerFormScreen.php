<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Servers;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Owner\Forms\DescribedForm;
use App\Modules\Telegram\Admin\Sections\Owner\Forms\FormScreen;
use App\Modules\Telegram\Admin\Sections\Owner\Words;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * A server's form on its connector (the panel's ServerForm), drawn from the connector's description (GET
 * /servers/drivers): a new server walks its basic fields, then its card; an existing one's «تنظیمات اتصال» open on the
 * card, secrets kept unless typed again or cleared. «🧪 تست اتصال» asks the panel with the draft before anything is
 * saved — a saved server's test sends its id, so its kept secrets stand in —; a new server is asked as it is added, and
 * its page shows what its panel answered.
 *
 * Actions besides the form's: `new` (driver key), `edit` (server id), `test`.
 */
final class ServerFormScreen extends FormScreen
{
    public const ID = 'svf';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->isOwner();
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function form(Admin $admin, array $state): ?DescribedForm
    {
        $driver = ServerWords::driver($admin, (string) ($state['driver'] ?? ''));

        return $driver === null ? null : new DescribedForm(Json::rows($driver, 'fields'), $this->crypt);
    }

    protected function title(array $state): string
    {
        return self::isNew($state) ? '➕ افزودن سرور ' . (string) ($state['label'] ?? '') : '✏️ تنظیمات اتصال';
    }

    protected function path(array $state): array
    {
        return self::isNew($state) ? ['مدیریت', 'سرورها'] : ['مدیریت', 'سرورها', (string) ($state['name'] ?? '')];
    }

    protected function saveLabel(array $state): string
    {
        return self::isNew($state) ? '✔️ افزودن سرور' : '💾 ذخیره تغییرات';
    }

    protected function leave(Admin $admin, array $state): View
    {
        return self::isNew($state) ? $admin->open(ServerDriversScreen::ID) : $admin->open(ServerScreen::ID, 'show', [(int) ($state['key'] ?? 0)]);
    }

    protected function intro(View $view, Admin $admin, array $state): void
    {
        if (!self::isNew($state)) {
            return;
        }
        $view->line('اطلاعات اتصال به پنل را وارد کنید. قبل از ذخیره می‌توانید اتصال را تست کنید.');
        foreach ((array) ($state['notes'] ?? []) as $note) {
            $view->line('ℹ️ ' . Words::note(is_scalar($note) ? (string) $note : ''));
        }
        $view->line();
    }

    protected function extra(View $view, Admin $admin, array $state): void
    {
        if (is_array($state['probe'] ?? null)) {
            $view->line()->lines(...ServerWords::probe($state['probe']));
        }
        $view->row(Button::to('🧪 تست اتصال', self::ID, 'test'));
    }

    protected function action(Admin $admin, string $action, array $args, array $state, DescribedForm $form): View
    {
        switch ($action) {
            case 'new':
                $driver = ServerWords::driver($admin, $args[0] ?? '');
                if ($driver === null) {
                    return $admin->open(ServerDriversScreen::ID);
                }

                return $this->begin($admin, [
                    'key' => 0,
                    'driver' => $driver['key'],
                    'label' => (string) ($driver['label'] ?? ''),
                    'notes' => array_values(array_filter((array) ($driver['notes'] ?? []), is_string(...))),
                    'stored' => null,
                ], $this->form($admin, ['driver' => $driver['key']]) ?? $form, walk: true);
            case 'edit':
                $id = self::id($args);
                $server = (array) ($admin->api->get("/servers/{$id}")['server'] ?? []);
                $state = ['key' => $id, 'driver' => (string) ($server['driver'] ?? ''), 'name' => (string) ($server['name'] ?? ''), 'stored' => Json::part($server, 'form')];
                $edited = $this->form($admin, $state);
                if ($edited === null) {
                    $admin->alert('فرم تنظیمات این کانکتور در این نسخه در دسترس نیست.');

                    return $admin->open(ServerScreen::ID, 'show', [$id]);
                }

                return $this->begin($admin, $state, $edited, walk: false);
            case 'test':
                if (!isset($state['draft'])) {
                    break;
                }
                $body = $form->payload(self::draftOf($state)) + ['driver' => (string) $state['driver']] + (self::isNew($state) ? [] : ['id' => (int) $state['key']]);
                try {
                    $probe = (array) ($admin->api->post('/servers/test', $body)['probe'] ?? []);
                } catch (ApiRefusal $refusal) {
                    return $this->refused($admin, $state, $form, $refusal);
                }
                $state['probe'] = $probe;
                $admin->remember(self::ID, ['probe' => $probe]);
                $admin->notice(Json::on($probe, 'ok') ? '✅ اتصال برقرار شد' : '❌ اتصال برقرار نشد');

                return $this->card($admin, $state, $form);
        }

        return parent::action($admin, $action, $args, $state, $form);
    }

    protected function save(Admin $admin, array $state, DescribedForm $form): View
    {
        $body = $form->payload(self::draftOf($state)) + ['driver' => (string) $state['driver']];
        if (self::isNew($state)) {
            $answer = $admin->api->post('/servers', $body);
            $id = (int) ($answer['server']['id'] ?? 0);
            $admin->forget(self::ID);
            // The new server's page shows what its panel answered as it was added.
            $admin->remember(ServerScreen::ID, ['id' => $id, 'probe' => is_array($answer['probe'] ?? null) ? $answer['probe'] : null]);
            $admin->notice('✅ سرور اضافه شد');

            return $admin->open(ServerScreen::ID, 'show', [$id]);
        }
        $id = (int) $state['key'];
        $admin->api->put("/servers/{$id}", $body);
        $admin->forget(self::ID);
        $admin->notice('✅ تنظیمات سرور ذخیره شد');

        return $admin->open(ServerScreen::ID, 'show', [$id]);
    }
}
