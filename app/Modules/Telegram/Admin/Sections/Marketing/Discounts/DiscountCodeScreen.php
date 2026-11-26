<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Discounts;

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
 * One discount code (as the panel has it now, DiscountCodesScreen::fresh()): its summary card — the code to copy, its
 * state, what it takes off and for what, its window, its uses, its note —; «✏️ ویرایش» (DiscountCodeForm), the code
 * copied at a tap, its switch, and «🗑 حذف»: asked first, or — a code an order keeps for its history — refused up front
 * with the panel's reason.
 *
 * Actions: `on` (id), `off` (id), `del` (id: asks), `gone` (id: deletes).
 */
final class DiscountCodeScreen extends BaseScreen
{
    public const ID = 'dcv';

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
                $row = Json::part($admin->api->patch("/discount-codes/{$id}", ['enabled' => $action === 'on']), 'code');
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

                return Marketing::deletion($admin, self::ID, $id, 'کد ' . Marketing::iso($code) . ' حذف شود؟', 'کد ' . Fmt::ltr($code) . ' دیگر پذیرفته نمی‌شود. برای کنار گذاشتن موقت، غیرفعالش کنید.');
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
        $code = is_string($row['code'] ?? null) ? $row['code'] : '';
        $enabled = (bool) ($row['enabled'] ?? false);

        return View::make(Icons::DISCOUNTS . ' کد تخفیف ' . Marketing::iso($code), ...Marketing::in(Marketing::DISCOUNTS))
            ->card([
                'کد' => Fmt::code($code),
                'وضعیت' => Fmt::e(Marketing::state($row['state'] ?? null)),
                'تخفیف' => Fmt::e(DiscountCodesScreen::worth($row)),
                'برای' => Fmt::e(self::targets($row)),
                'زمان' => Fmt::e(Marketing::window($row['starts_at'] ?? null, $row['ends_at'] ?? null)),
                'استفاده' => DiscountCodesScreen::uses($row) . ' بار' . (is_int($row['per_user'] ?? null) ? ' · هر مشتری تا ' . Fmt::number($row['per_user']) . ' بار' : ''),
                'یادداشت' => is_string($row['note'] ?? null) && $row['note'] !== '' ? Fmt::e($row['note']) : null,
                'ساخته شده' => is_string($row['created_at'] ?? null) ? Fmt::date($row['created_at']) : null,
            ])
            ->row(
                $enabled ? null : Button::to(Words::ACTIVATE, self::ID, 'on', $id)->success(),
                Button::to(Words::EDIT, DiscountCodeForm::ID, 'edit', $id)->primary(),
                Button::copy(Words::copy('کد'), $code),
            )
            ->row($enabled ? Button::to(Words::DEACTIVATE, self::ID, 'off', $id)->danger() : null, Button::to(Words::DELETE, self::ID, 'del', $id)->danger())
            ->back(DiscountCodesScreen::ID);
    }

    /**
     * What a code holds for, in a short line (the panel's codeTargets): «خرید، تمدید · ۲ پلن · ۱ سرور · خرید اول · دست‌کم …».
     *
     * @param array<string, mixed> $code
     */
    private static function targets(array $code): string
    {
        $parts = [Marketing::kinds($code['order_types'] ?? null)];
        if (is_array($code['plans'] ?? null)) {
            $parts[] = Marketing::named('پلن', $code['plans']);
        }
        if (is_array($code['servers'] ?? null)) {
            $parts[] = Marketing::named('سرور', $code['servers']);
        }
        if ((bool) ($code['first_purchase_only'] ?? false)) {
            $parts[] = 'خرید اول';
        }
        if (is_numeric($code['min_amount'] ?? null)) {
            $parts[] = 'دست‌کم ' . Fmt::plain(Fmt::money((string) $code['min_amount']));
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
