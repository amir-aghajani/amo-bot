<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Cashback;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Marketing\Words as Marketing;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * One cashback rule (the panel's row): its summary card — state, percent, kinds of order, customer group and way to
 * pay —, «✏️ ویرایش» (CashbackRuleForm), its switch and «🗑 حذف»: asked first, with the panel's words.
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
        $row = Marketing::one($admin, '/cashback/rules', 'rules', self::id($args));

        return $row === null ? $admin->open(CashbackRulesScreen::ID) : $this->view($row);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        switch ($action) {
            case 'on':
            case 'off':
                $row = Json::part($admin->api->patch("/cashback/rules/{$id}", ['enabled' => $action === 'on']), 'rule');
                $admin->notice($action === 'on' ? '✅ قانون کش‌بک فعال شد' : '⏸ قانون کش‌بک غیرفعال شد');

                return $this->view($row);
            case 'del':
                $row = Marketing::one($admin, '/cashback/rules', 'rules', $id);
                if ($row === null) {
                    return $admin->open(CashbackRulesScreen::ID);
                }

                return Marketing::deletion($admin, self::ID, $id, 'کش‌بک ' . CashbackRulesScreen::name($row) . ' حذف شود؟', 'کش‌بکی که سفارش‌ها تا امروز با این قانون گرفته‌اند سر جایش می‌ماند. برای کنار گذاشتن موقت، غیرفعالش کنید.');
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

        return View::make(Icons::CASHBACK . ' کش‌بک ' . CashbackRulesScreen::name($row), ...Marketing::in(Marketing::CASHBACK))
            ->card([
                'وضعیت' => Fmt::active($enabled),
                'درصد' => Fmt::percent((int) ($row['percent'] ?? 0)),
                'نوع سفارش' => Fmt::e(Marketing::kinds($row['order_types'] ?? [])),
                'گروه مشتری' => Fmt::e(CashbackRulesScreen::group($row)),
                'روش پرداخت' => Fmt::e(CashbackRulesScreen::method($row)),
            ])
            ->row($enabled ? null : Button::to(Words::ACTIVATE, self::ID, 'on', $id)->success(), Button::to(Words::EDIT, CashbackRuleForm::ID, 'edit', $id)->primary())
            ->row($enabled ? Button::to(Words::DEACTIVATE, self::ID, 'off', $id)->danger() : null, Button::to(Words::DELETE, self::ID, 'del', $id)->danger())
            ->back(CashbackRulesScreen::ID);
    }
}
