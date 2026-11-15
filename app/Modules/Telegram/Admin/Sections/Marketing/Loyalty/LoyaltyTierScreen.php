<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Loyalty;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Marketing\Words;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * One loyalty tier (the panel's row): its threshold, discount, extra cashback and how many customers are on it, with
 * «✏️ ویرایش» (LoyaltyTierForm) and «🗑 حذف» — asked first, saying where its customers go.
 *
 * Actions: `del` (id: asks), `gone` (id: deletes).
 */
final class LoyaltyTierScreen extends BaseScreen
{
    public const ID = 'lyv';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        $row = Words::one($admin, '/loyalty/tiers', 'tiers', self::id($args));

        return $row === null ? $admin->open(LoyaltyTiersScreen::ID) : $this->view($row);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        if ($action === 'del') {
            $row = Words::one($admin, '/loyalty/tiers', 'tiers', $id);
            if ($row === null) {
                return $admin->open(LoyaltyTiersScreen::ID);
            }
            $customers = (int) ($row['customers'] ?? 0);

            return $admin->confirm(
                '«' . (string) ($row['name'] ?? '') . '» حذف شود؟',
                Button::to('🗑 بله، حذف شود', self::ID, 'gone', $id),
                Button::to('✖️ انصراف', self::ID, 'show', $id),
                $customers === 0 ? 'هیچ مشتری‌ای روی این سطح نیست.' : Fmt::number($customers) . ' مشتری این سطح به سطح پایین‌تر می‌روند، یا بی‌سطح می‌مانند؛ خریدهایشان دست نمی‌خورد.',
            );
        }
        if ($action === 'gone') {
            $admin->api->delete("/loyalty/tiers/{$id}");
            $admin->notice('🗑 سطح حذف شد');

            return $admin->open(LoyaltyTiersScreen::ID);
        }

        return $this->show($admin, $args);
    }

    /** @param array<string, mixed> $row */
    private function view(array $row): View
    {
        $id = (int) ($row['id'] ?? 0);
        $spent = is_numeric($row['min_spent'] ?? null) ? (string) $row['min_spent'] : '0';

        return View::make('🏅 ' . (string) ($row['name'] ?? ''), ...[...Words::PATH, 'سطوح وفاداری'])
            ->facts([
                'حد خرید' => (float) $spent > 0 ? 'از ' . Fmt::money($spent) : 'از ابتدا',
                'تخفیف' => LoyaltyTiersScreen::percent($row['discount_percent'] ?? 0),
                'کش‌بک اضافه' => LoyaltyTiersScreen::percent($row['cashback_percent'] ?? 0),
                'مشتری‌ها' => Fmt::number((int) ($row['customers'] ?? 0)),
            ])
            ->row(Button::to('✏️ ویرایش', LoyaltyTierForm::ID, 'edit', $id), Button::to('🗑 حذف', self::ID, 'del', $id))
            ->back(LoyaltyTiersScreen::ID);
    }
}
