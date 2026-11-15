<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Cashback;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Marketing\Words;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * One cashback rule (the panel's row): its percent, kinds of order, customer group and way to pay, its switch,
 * «✏️ ویرایش» (CashbackRuleForm) and «🗑 حذف» — asked first, with the panel's words.
 *
 * Actions: `on` (id), `off` (id), `del` (id: asks), `gone` (id: deletes).
 */
final class CashbackRuleScreen extends BaseScreen
{
    public const ID = 'cbv';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        $row = Words::one($admin, '/cashback/rules', 'rules', self::id($args));

        return $row === null ? $admin->open(CashbackRulesScreen::ID) : $this->view($row);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        switch ($action) {
            case 'on':
            case 'off':
                $row = (array) ($admin->api->patch("/cashback/rules/{$id}", ['enabled' => $action === 'on'])['rule'] ?? []);
                $admin->notice($action === 'on' ? '✅ قانون کش‌بک فعال شد' : '⏸ قانون کش‌بک غیرفعال شد');

                return $this->view($row);
            case 'del':
                $row = Words::one($admin, '/cashback/rules', 'rules', $id);
                if ($row === null) {
                    return $admin->open(CashbackRulesScreen::ID);
                }

                return $admin->confirm(
                    'کش‌بک ' . CashbackRulesScreen::name($row) . ' حذف شود؟',
                    Button::to('🗑 بله، حذف شود', self::ID, 'gone', $id),
                    Button::to('✖️ انصراف', self::ID, 'show', $id),
                    'کش‌بکی که سفارش‌ها تا امروز با این قانون گرفته‌اند سر جایش می‌ماند.',
                );
            case 'gone':
                $admin->api->delete("/cashback/rules/{$id}");
                $admin->notice('🗑 قانون کش‌بک حذف شد');

                return $admin->open(CashbackRulesScreen::ID);
        }

        return $this->show($admin, $args);
    }

    /** @param array<string, mixed> $row */
    private function view(array $row): View
    {
        $id = (int) ($row['id'] ?? 0);
        $enabled = (bool) ($row['enabled'] ?? false);

        return View::make('💸 کش‌بک ' . CashbackRulesScreen::name($row), ...[...Words::PATH, 'کش‌بک'])
            ->facts([
                'درصد' => Fmt::percent((int) ($row['percent'] ?? 0)),
                'نوع سفارش' => Fmt::e(Words::kinds($row['order_types'] ?? [])),
                'گروه مشتری' => Fmt::e(CashbackRulesScreen::group($row)),
                'روش پرداخت' => Fmt::e(CashbackRulesScreen::method($row)),
                'وضعیت' => Words::onOff($enabled),
            ])
            ->row($enabled ? Button::to('⏸ غیرفعال کردن', self::ID, 'off', $id) : Button::to('▶️ فعال کردن', self::ID, 'on', $id), Button::to('✏️ ویرایش', CashbackRuleForm::ID, 'edit', $id))
            ->row(Button::to('🗑 حذف', self::ID, 'del', $id))
            ->back(CashbackRulesScreen::ID);
    }
}
