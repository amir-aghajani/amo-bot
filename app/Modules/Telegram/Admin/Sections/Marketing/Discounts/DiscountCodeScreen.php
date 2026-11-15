<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Discounts;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Marketing\Words;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * One discount code (as the panel has it now, DiscountCodesScreen::fresh()): its text to copy, what it takes off and
 * for what, its window, its uses and state — the panel's row —, and its switch, «✏️ ویرایش» (DiscountCodeForm) and
 * «🗑 حذف»: asked first, or — a code an order keeps for its history — refused up front with the panel's reason.
 *
 * Actions: `on` (id), `off` (id), `del` (id: asks), `gone` (id: deletes).
 */
final class DiscountCodeScreen extends BaseScreen
{
    public const ID = 'dcv';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        $row = DiscountCodesScreen::fresh($admin, self::id($args));

        return $row === null ? $admin->open(DiscountCodesScreen::ID) : $this->view($row);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        switch ($action) {
            case 'on':
            case 'off':
                $row = (array) ($admin->api->patch("/discount-codes/{$id}", ['enabled' => $action === 'on'])['code'] ?? []);
                $admin->notice($action === 'on' ? '✅ کد تخفیف فعال شد' : '⏸ کد تخفیف غیرفعال شد');

                return $this->view(DiscountCodesScreen::keep($admin, $row));
            case 'del':
                $row = DiscountCodesScreen::fresh($admin, $id);
                if ($row === null) {
                    return $admin->open(DiscountCodesScreen::ID);
                }
                $code = (string) ($row['code'] ?? '');
                $kept = self::keptFor($row);
                if ($kept !== null) {
                    $admin->alert($kept);

                    return $this->view($row);
                }

                return $admin->confirm(
                    'کد ' . Words::iso($code) . ' حذف شود؟',
                    Button::to('🗑 بله، حذف شود', self::ID, 'gone', $id),
                    Button::to('✖️ انصراف', self::ID, 'show', $id),
                    'کد ' . Fmt::ltr($code) . ' حذف می‌شود و دیگر پذیرفته نمی‌شود. برای کنار گذاشتن موقت، کافی است آن را غیرفعال کنید.',
                );
            case 'gone':
                $admin->api->delete("/discount-codes/{$id}");
                $admin->notice('🗑 کد تخفیف حذف شد');

                return $admin->open(DiscountCodesScreen::ID);
        }

        return $this->show($admin, $args);
    }

    /** @param array<string, mixed> $row */
    private function view(array $row): View
    {
        $id = (int) ($row['id'] ?? 0);
        $enabled = (bool) ($row['enabled'] ?? false);
        $view = View::make('🏷 کد تخفیف ' . Words::iso((string) ($row['code'] ?? '')), ...[...Words::PATH, 'کدهای تخفیف'])
            ->line(Fmt::code(is_string($row['code'] ?? null) ? $row['code'] : null));
        if (is_string($row['note'] ?? null) && $row['note'] !== '') {
            $view->line('<i>' . Fmt::e($row['note']) . '</i>');
        }
        $view->facts([
            'تخفیف' => Fmt::e(DiscountCodesScreen::worth($row)),
            'برای' => Fmt::e(self::targets($row)),
            'زمان' => Fmt::e(Words::window($row['starts_at'] ?? null, $row['ends_at'] ?? null)),
            'استفاده' => DiscountCodesScreen::uses($row) . (is_int($row['per_user'] ?? null) ? ' · هر مشتری تا ' . Fmt::number($row['per_user']) . ' بار' : ''),
            'وضعیت' => Fmt::e(Words::state($row['state'] ?? null)),
            'ساخته شده' => is_string($row['created_at'] ?? null) ? Fmt::date($row['created_at']) : null,
        ]);

        return $view
            ->row($enabled ? Button::to('⏸ غیرفعال کردن', self::ID, 'off', $id) : Button::to('▶️ فعال کردن', self::ID, 'on', $id), Button::to('✏️ ویرایش', DiscountCodeForm::ID, 'edit', $id))
            ->row(Button::to('🗑 حذف', self::ID, 'del', $id))
            ->back(DiscountCodesScreen::ID);
    }

    /**
     * What a code holds for, in a short line (the panel's codeTargets): «خرید، تمدید · ۲ پلن · ۱ سرور · خرید اول · دست‌کم …».
     *
     * @param array<string, mixed> $code
     */
    private static function targets(array $code): string
    {
        $parts = [Words::kinds($code['order_types'] ?? null)];
        if (is_array($code['plans'] ?? null)) {
            $parts[] = Words::named('پلن', $code['plans']);
        }
        if (is_array($code['servers'] ?? null)) {
            $parts[] = Words::named('سرور', $code['servers']);
        }
        if ((bool) ($code['first_purchase_only'] ?? false)) {
            $parts[] = 'خرید اول';
        }
        if (is_numeric($code['min_amount'] ?? null)) {
            $parts[] = 'دست‌کم ' . Fmt::money((string) $code['min_amount']);
        }

        return implode(' · ', $parts);
    }

    /**
     * Why a code cannot be deleted (`deletable`, the server's own rule), in the panel's words; null for one that may go.
     *
     * @param array<string, mixed> $code
     */
    private static function keptFor(array $code): ?string
    {
        if ((bool) ($code['deletable'] ?? true)) {
            return null;
        }
        $uses = (int) ($code['uses'] ?? 0);

        return $uses > 0
            ? 'این کد ' . Fmt::number($uses) . ' بار در سفارش‌ها استفاده شده و برای سابقه می‌ماند؛ به جای حذف، آن را غیرفعال کنید.'
            : 'این کد فقط در سفارش‌هایی آمده که بازپرداخت شده‌اند؛ استفاده‌ای از آن حساب نمی‌شود، ولی برای سابقه آن سفارش‌ها می‌ماند. به جای حذف، آن را غیرفعال کنید.';
    }
}
