<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\GiftCodes;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Marketing\Links;
use App\Modules\Telegram\Admin\Sections\Marketing\Words;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * One gift code (from the list's kept page, GiftCodeListScreen): the code to copy, what it is worth, its batch and
 * state — the day an unused one stops working —, who took it, when, and by which order (the panel's row, with links to
 * the customer and the order), and «🗑 حذف» for one nobody took — asked first, with the panel's words.
 *
 * Actions: `del` (id: asks), `gone` (id: deletes).
 */
final class GiftCodeScreen extends BaseScreen
{
    public const ID = 'gcv';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        $row = $this->row($admin, self::id($args));

        return $row === null ? $admin->open(GiftCodeListScreen::ID) : $this->view($admin, $row);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        $row = $this->row($admin, $id);
        if ($row === null) {
            return $admin->open(GiftCodeListScreen::ID);
        }
        if ($action === 'del' && ($row['status'] ?? null) !== 'redeemed') {
            return $admin->confirm(
                'کد ' . Words::iso((string) ($row['code'] ?? '')) . ' حذف شود؟',
                Button::to('🗑 بله، حذف شود', self::ID, 'gone', $id),
                Button::to('✖️ انصراف', self::ID, 'show', $id),
                'این کد دیگر پذیرفته نمی‌شود؛ اگر آن را به کسی داده‌اید، دیگر کار نمی‌کند.',
            );
        }
        if ($action === 'gone') {
            $admin->api->delete("/gift-codes/{$id}");
            $admin->notice('🗑 کد هدیه حذف شد');

            return $admin->open(GiftCodeListScreen::ID);
        }

        return $this->view($admin, $row);
    }

    /** @param array<string, mixed> $row */
    private function view(Admin $admin, array $row): View
    {
        $id = (int) ($row['id'] ?? 0);
        $status = (string) ($row['status'] ?? '');
        $taken = is_string($row['redeemed_at'] ?? null);
        $order = is_array($row['order'] ?? null) ? (int) ($row['order']['id'] ?? 0) : 0;
        $customer = is_array($row['redeemed_by'] ?? null) ? (int) ($row['redeemed_by']['id'] ?? 0) : 0;

        $view = View::make('🎟 کد هدیه ' . Words::iso((string) ($row['code'] ?? '')), ...[...Words::PATH, 'کدهای هدیه'])
            ->line(Fmt::code(is_string($row['code'] ?? null) ? $row['code'] : null))
            ->facts([
                'ارزش' => Fmt::e(GiftCodeListScreen::worth($row)) . ' · ' . Fmt::e(($row['kind'] ?? null) === 'wallet' ? 'شارژ کیف پول' : 'سرویس رایگان'),
                'سری' => is_string($row['batch'] ?? null) ? Fmt::e($row['batch']) : Fmt::NONE,
                'وضعیت' => Fmt::e(GiftCodeListScreen::STATUSES[$status] ?? $status) . ($status === 'unused' && is_string($row['expires_at'] ?? null) ? ' · تا ' . Fmt::date($row['expires_at']) : ''),
                'استفاده' => $taken ? Fmt::e(Words::customer($row['redeemed_by'] ?? null)) . ' · ' . Fmt::date((string) $row['redeemed_at'], true) . ($order > 0 ? ' · سفارش ' . Fmt::id($order) : '') : Fmt::NONE,
            ]);
        $view->row(
            $customer > 0 ? Links::to($admin, '👤 مشتری', Links::CUSTOMER, 'show', $customer) : null,
            $order > 0 ? Links::to($admin, '🛒 سفارش ' . Fmt::id($order), Links::ORDER, 'show', $order) : null,
        );
        if ($status !== 'redeemed') {
            $view->row(Button::to('🗑 حذف', self::ID, 'del', $id));
        }

        return $view->back(GiftCodeListScreen::ID);
    }

    /**
     * The code from the list's kept page; null when it is not there.
     *
     * @return array<string, mixed>|null
     */
    private function row(Admin $admin, int $id): ?array
    {
        $rows = (array) ($admin->state(GiftCodeListScreen::ID)['rows'] ?? []);
        $row = $rows[$id] ?? $rows[(string) $id] ?? null;

        return is_array($row) ? $row : null;
    }
}
