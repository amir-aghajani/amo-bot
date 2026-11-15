<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Agency;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Owner\Forms\DescribedForm;
use App\Modules\Telegram\Admin\Sections\Owner\Forms\FormScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * An agency level added or edited (the panel's LevelForm): its name and what a GB of the traffic their bots sell costs
 * its agents — a new one walked, an edit on its card —, saved in one call.
 *
 * Actions besides the form's: `new`, `edit` (level id).
 */
final class AgencyLevelFormScreen extends FormScreen
{
    public const ID = 'agkn';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->isOwner();
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function form(Admin $admin, array $state): DescribedForm
    {
        return new DescribedForm([
            DescribedForm::field('name', 'text', 'نام سطح', 'همان‌طور که مشتری در معرفی نمایندگی و نماینده در حسابش می‌بیند؛ مثلا «برنزی» یا «طلایی».', required: true),
            DescribedForm::field('price_per_gb', 'amount', 'قیمت هر گیگابایت', 'نماینده‌ها حجمی را که رباتشان می‌فروشد با این قیمت از شما می‌خرند.', required: true),
        ], $this->crypt);
    }

    protected function title(array $state): string
    {
        return self::isNew($state) ? '➕ افزودن سطح' : '✏️ ویرایش «' . (string) ($state['name'] ?? '') . '»';
    }

    protected function path(array $state): array
    {
        return ['مدیریت', 'نمایندگان', 'سطح‌ها'];
    }

    protected function saveLabel(array $state): string
    {
        return self::isNew($state) ? '✔️ افزودن سطح' : '💾 ذخیره تغییرات';
    }

    protected function intro(View $view, Admin $admin, array $state): void
    {
        $price = (int) (self::draftOf($state)['price_per_gb'] ?? 0);
        if ($price > 0) {
            $view->line('۱۰۰ گیگابایت برای نماینده‌های این سطح ' . Fmt::money((string) ($price * 100)) . ' است.')->line();
        }
    }

    protected function leave(Admin $admin, array $state): View
    {
        return self::isNew($state) ? $admin->open(AgencyLevelsScreen::ID) : $admin->open(AgencyLevelScreen::ID, 'show', [(int) $state['key']]);
    }

    protected function action(Admin $admin, string $action, array $args, array $state, DescribedForm $form): View
    {
        if ($action === 'new') {
            return $this->begin($admin, ['key' => 0, 'stored' => null], $this->form($admin, []), walk: true);
        }
        if ($action === 'edit') {
            foreach ((array) ($admin->api->get('/agency/levels')['levels'] ?? []) as $level) {
                if (is_array($level) && (int) ($level['id'] ?? 0) === self::id($args)) {
                    $stored = ['name' => (string) ($level['name'] ?? ''), 'price_per_gb' => (string) (int) round((float) ($level['price_per_gb'] ?? 0))];

                    return $this->begin($admin, ['key' => self::id($args), 'name' => $stored['name'], 'stored' => $stored], $this->form($admin, []), walk: false);
                }
            }

            return $admin->open(AgencyLevelsScreen::ID);
        }

        return parent::action($admin, $action, $args, $state, $form);
    }

    protected function save(Admin $admin, array $state, DescribedForm $form): View
    {
        $body = $form->payload(self::draftOf($state));
        $level = self::isNew($state)
            ? (array) ($admin->api->post('/agency/levels', $body)['level'] ?? [])
            : (array) ($admin->api->put('/agency/levels/' . (int) $state['key'], $body)['level'] ?? []);
        $admin->forget(self::ID);
        $admin->notice(self::isNew($state) ? '✅ سطح اضافه شد' : '✅ سطح ذخیره شد');

        return $admin->open(AgencyLevelScreen::ID, 'show', [(int) ($level['id'] ?? 0)]);
    }
}
