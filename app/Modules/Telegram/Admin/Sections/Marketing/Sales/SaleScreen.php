<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Sales;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Marketing\Words;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * One sale (the panel's row): its name, percent, the kinds of order and plans it is for, its window and state, and its
 * switch, «✏️ ویرایش» (SaleForm) and «🗑 حذف» — asked first, with the panel's words.
 *
 * Actions: `on` (id), `off` (id), `del` (id: asks), `gone` (id: deletes).
 */
final class SaleScreen extends BaseScreen
{
    public const ID = 'slv';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        $row = Words::one($admin, '/sales', 'sales', self::id($args));

        return $row === null ? $admin->open(SalesScreen::ID) : $this->view($row);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        switch ($action) {
            case 'on':
            case 'off':
                $row = (array) ($admin->api->patch("/sales/{$id}", ['enabled' => $action === 'on'])['sale'] ?? []);
                $admin->notice($action === 'on' ? '✅ فروش ویژه فعال شد' : '⏸ فروش ویژه غیرفعال شد');

                return $this->view($row);
            case 'del':
                $row = Words::one($admin, '/sales', 'sales', $id);
                if ($row === null) {
                    return $admin->open(SalesScreen::ID);
                }

                return $admin->confirm(
                    '«' . (string) ($row['name'] ?? '') . '» حذف شود؟',
                    Button::to('🗑 بله، حذف شود', self::ID, 'gone', $id),
                    Button::to('✖️ انصراف', self::ID, 'show', $id),
                    'سفارش‌هایی که با این فروش ویژه ثبت شده‌اند تخفیفشان را همان‌طور نگه می‌دارند. برای کنار گذاشتن موقت، کافی است آن را غیرفعال کنید.',
                );
            case 'gone':
                $admin->api->delete("/sales/{$id}");
                $admin->notice('🗑 فروش ویژه حذف شد');

                return $admin->open(SalesScreen::ID);
        }

        return $this->show($admin, $args);
    }

    /** @param array<string, mixed> $row */
    private function view(array $row): View
    {
        $id = (int) ($row['id'] ?? 0);
        $enabled = (bool) ($row['enabled'] ?? false);

        return View::make('🔥 ' . (string) ($row['name'] ?? ''), ...[...Words::PATH, 'فروش ویژه'])
            ->facts([
                'تخفیف' => Fmt::percent((int) ($row['percent'] ?? 0)),
                'برای' => Fmt::e(Words::kinds($row['order_types'] ?? null) . ' · ' . (is_array($row['plans'] ?? null) ? Words::named('پلن', $row['plans']) : 'همه پلن‌ها')),
                'زمان' => Fmt::e(Words::window($row['starts_at'] ?? null, $row['ends_at'] ?? null)),
                'وضعیت' => Fmt::e(Words::state($row['state'] ?? null)),
            ])
            ->row($enabled ? Button::to('⏸ غیرفعال کردن', self::ID, 'off', $id) : Button::to('▶️ فعال کردن', self::ID, 'on', $id), Button::to('✏️ ویرایش', SaleForm::ID, 'edit', $id))
            ->row(Button::to('🗑 حذف', self::ID, 'del', $id))
            ->back(SalesScreen::ID);
    }
}
