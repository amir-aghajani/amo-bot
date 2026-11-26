<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Loyalty;

use App\Modules\Telegram\Admin\Sections\Marketing\Words as Marketing;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * One loyalty tier (the panel's row): its summary card — threshold, discount, extra cashback, how many customers are on
 * it —, «✏️ ویرایش» (LoyaltyTierForm) and «🗑 حذف»: asked first, saying where its customers go.
 *
 * Actions: `del` (id: asks), `gone` (id: deletes).
 */
final class LoyaltyTierScreen extends BaseScreen
{
    public const ID = 'lyv';

    public function show(Admin $admin, array $args): View
    {
        $row = Marketing::one($admin, '/loyalty/tiers', 'tiers', self::id($args));

        return $row === null ? $admin->open(LoyaltyTiersScreen::ID) : $this->view($row);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        if ($action === 'del') {
            $row = Marketing::one($admin, '/loyalty/tiers', 'tiers', $id);
            if ($row === null) {
                return $admin->open(LoyaltyTiersScreen::ID);
            }
            $customers = (int) ($row['customers'] ?? 0);

            return Marketing::deletion(
                $admin,
                self::ID,
                $id,
                '«' . (string) ($row['name'] ?? '') . '» حذف شود؟',
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

        return View::make(Icons::LOYALTY . ' ' . (string) ($row['name'] ?? ''), ...Marketing::in(Marketing::LOYALTY))
            ->card([
                'حد خرید' => LoyaltyTiersScreen::from($row),
                'تخفیف' => LoyaltyTiersScreen::percent($row['discount_percent'] ?? 0),
                'کش‌بک اضافه' => LoyaltyTiersScreen::percent($row['cashback_percent'] ?? 0),
                'مشتری‌ها' => Fmt::number((int) ($row['customers'] ?? 0)),
            ])
            ->row(Button::to(Words::EDIT, LoyaltyTierForm::ID, 'edit', $id)->primary())
            ->row(Button::to(Words::DELETE, self::ID, 'del', $id)->danger())
            ->back(LoyaltyTiersScreen::ID);
    }
}
