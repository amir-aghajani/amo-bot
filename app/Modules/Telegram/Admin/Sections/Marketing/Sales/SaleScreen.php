<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Sales;

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
 * One sale (the panel's row): its summary card — state, percent, the kinds of order and plans it is for, its window —,
 * «✏️ ویرایش» (SaleForm), its switch and «🗑 حذف»: asked first, with the panel's words.
 *
 * Actions: `on` (id), `off` (id), `del` (id: asks), `gone` (id: deletes).
 */
final class SaleScreen extends BaseScreen
{
    public const ID = 'slv';

    public function show(Admin $admin, array $args): View
    {
        $row = Marketing::one($admin, '/sales', 'sales', self::id($args));

        return $row === null ? $admin->open(SalesScreen::ID) : $this->view($row);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        switch ($action) {
            case 'on':
            case 'off':
                $row = Json::part($admin->api->patch("/sales/{$id}", ['enabled' => $action === 'on']), 'sale');
                $admin->notice($action === 'on' ? '✅ فروش ویژه فعال شد' : '⏸ فروش ویژه غیرفعال شد');

                return $this->view($row);
            case 'del':
                $row = Marketing::one($admin, '/sales', 'sales', $id);
                if ($row === null) {
                    return $admin->open(SalesScreen::ID);
                }

                return Marketing::deletion($admin, self::ID, $id, '«' . (string) ($row['name'] ?? '') . '» حذف شود؟', 'سفارش‌هایی که با این فروش ویژه ثبت شده‌اند تخفیفشان را همان‌طور نگه می‌دارند. برای کنار گذاشتن موقت، غیرفعالش کنید.');
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

        return View::make(Icons::SPECIAL_SALES . ' ' . (string) ($row['name'] ?? ''), ...Marketing::in(Marketing::SALES))
            ->card([
                'وضعیت' => Fmt::e(Marketing::state($row['state'] ?? null)),
                'تخفیف' => Fmt::percent((int) ($row['percent'] ?? 0)),
                'سفارش‌ها' => Fmt::e(Marketing::kinds($row['order_types'] ?? null)),
                'پلن‌ها' => Fmt::e(Marketing::reach($row['plans'] ?? null, $row['categories'] ?? null) ?? 'همه پلن‌ها'),
                'زمان' => Fmt::e(Marketing::window($row['starts_at'] ?? null, $row['ends_at'] ?? null)),
            ])
            ->row($enabled ? null : Button::to(Words::ACTIVATE, self::ID, 'on', $id)->success(), Button::to(Words::EDIT, SaleForm::ID, 'edit', $id)->primary())
            ->row($enabled ? Button::to(Words::DEACTIVATE, self::ID, 'off', $id)->danger() : null, Button::to(Words::DELETE, self::ID, 'del', $id)->danger())
            ->back(SalesScreen::ID);
    }
}
