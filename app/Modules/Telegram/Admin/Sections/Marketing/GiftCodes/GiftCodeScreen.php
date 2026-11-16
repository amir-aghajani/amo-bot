<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\GiftCodes;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Marketing\Links;
use App\Modules\Telegram\Admin\Sections\Marketing\Words as Marketing;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * One gift code (from the list's kept page, GiftCodeListScreen): its summary card — the code to copy, its state and the
 * day an unused one stops working, what it is worth, its batch, who took it, when and by which order —, the code copied
 * at a tap, links to the customer and the order, and «🗑 حذف» for one nobody took — asked first, with the panel's words.
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
        $row = ListScreen::keptRow($admin, GiftCodeListScreen::ID, self::id($args));

        return $row === null ? $admin->open(GiftCodeListScreen::ID) : $this->view($admin, $row);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        $row = ListScreen::keptRow($admin, GiftCodeListScreen::ID, $id);
        if ($row === null) {
            return $admin->open(GiftCodeListScreen::ID);
        }
        if ($action === 'del' && ($row['status'] ?? null) !== 'redeemed') {
            return Marketing::deletion($admin, self::ID, $id, 'کد ' . Marketing::iso((string) ($row['code'] ?? '')) . ' حذف شود؟', 'این کد دیگر پذیرفته نمی‌شود؛ اگر آن را به کسی داده‌اید، دیگر کار نمی‌کند.');
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
        $code = is_string($row['code'] ?? null) ? $row['code'] : '';
        $status = (string) ($row['status'] ?? '');
        $taken = is_string($row['redeemed_at'] ?? null);
        $order = is_array($row['order'] ?? null) ? (int) ($row['order']['id'] ?? 0) : 0;
        $customer = is_array($row['redeemed_by'] ?? null) ? (int) ($row['redeemed_by']['id'] ?? 0) : 0;

        return View::make(Icons::GIFT_CODES . ' کد هدیه ' . Marketing::iso($code), ...Marketing::in(Marketing::GIFT_CODES))
            ->card([
                'کد' => Fmt::code($code),
                'وضعیت' => Fmt::e(GiftCodeListScreen::STATUSES[$status] ?? $status),
                'معتبر تا' => $status === 'unused' && is_string($row['expires_at'] ?? null) ? Fmt::date($row['expires_at']) : null,
                'ارزش' => Fmt::e(GiftCodeListScreen::worth($row)) . ' · ' . Fmt::e(($row['kind'] ?? null) === 'wallet' ? 'شارژ کیف پول' : 'سرویس رایگان'),
                'سری' => is_string($row['batch'] ?? null) ? Fmt::e($row['batch']) : '',
                'استفاده‌کننده' => $taken ? Fmt::e(Marketing::customer($row['redeemed_by'] ?? null)) : null,
                'زمان استفاده' => $taken ? Fmt::date((string) $row['redeemed_at'], true) : null,
                'سفارش' => $order > 0 ? Fmt::id($order) : null,
            ])
            ->row(Button::copy(Words::copy('کد'), $code))
            ->row(
                $customer > 0 ? Links::to($admin, Icons::CUSTOMERS . ' مشتری', Links::CUSTOMER, 'show', $customer) : null,
                $order > 0 ? Links::to($admin, Icons::ORDERS . ' سفارش ' . Fmt::id($order), Links::ORDER, 'show', $order) : null,
            )
            ->row($status !== 'redeemed' ? Button::to(Words::DELETE, self::ID, 'del', $id)->danger() : null)
            ->back(GiftCodeListScreen::ID);
    }
}
