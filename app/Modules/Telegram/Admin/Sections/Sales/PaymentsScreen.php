<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Sales;

use App\Modules\Auth\StaffGrant;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «💳 پرداخت‌ها» (the panel's «پرداخت‌ها»): every payment, the receipts waiting for review and the online payments held
 * for support tabs of their own with their counts, searched by payment, order or customer and narrowed by days and order
 * (PaymentFilters), with what the paid ones add up to. A row opens its payment (PaymentScreen) with its receipt. With
 * the grant to dismiss receipts in bulk, the review tab offers picking receipts to reject at once, and — the list not
 * narrowed — rejecting every one waiting (ReceiptsScreen).
 */
final class PaymentsScreen extends SalesList
{
    public const ID = 'py';

    /** The review queue's tab. */
    public const REVIEW = 'awaiting_review';

    /** The panel's tabs: «همه», then what waits on support first. */
    private const TABS = [
        self::ANY => 'همه',
        self::REVIEW => '🧾 در انتظار بررسی',
        'held' => '✋ نگه داشته شده',
        'paid' => '✅ پرداخت‌شده',
        'failed' => '⚠️ ناموفق',
        'pending' => '⏳ در انتظار پرداخت',
    ];

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '💳 پرداخت‌ها';
    }

    protected function endpoint(): string
    {
        return '/payments';
    }

    protected function key(): string
    {
        return 'payments';
    }

    protected function narrower(): string
    {
        return PaymentFilters::ID;
    }

    protected function statuses(): array
    {
        return self::TABS;
    }

    protected function counted(): array
    {
        return [self::REVIEW, 'held'];
    }

    protected function searchHint(): string
    {
        return 'شماره پرداخت، سفارش یا مشتری';
    }

    protected function figures(array $meta): array
    {
        return $this->total($meta, 'paid', 'paid_amount', 'پرداخت موفق', 'در این روزها پرداخت موفقی نیست.');
    }

    protected function empty(): string
    {
        return match (true) {
            $this->narrowed() => 'پرداختی پیدا نشد؛ با این جستجو یا فیلتر چیزی نیست.',
            $this->tab() === self::REVIEW => 'فعلا رسیدی منتظر بررسی نیست.',
            $this->tab() === 'held' => 'فعلا پرداختی برای پشتیبانی نگه داشته نشده است.',
            $this->tab() !== self::ANY => 'پرداختی در این وضعیت نیست.',
            default => 'هنوز پرداختی ثبت نشده است؛ اولین خرید یا شارژ کیف پول از ربات این‌جا ظاهر می‌شود.',
        };
    }

    protected function more(View $view, Admin $admin): void
    {
        if ($this->tab() !== self::REVIEW || $this->count(self::REVIEW) === 0 || !$admin->seat->holds(StaffGrant::Payments)) {
            return;
        }
        $view->row(
            Button::to('☑️ انتخاب رسیدها برای رد', ReceiptsScreen::ID),
            // Every receipt of the shop: offered only while no search or filter makes the list look like fewer.
            $this->narrowed() ? null : Button::to('🚫 رد همه رسیدهای در انتظار', ReceiptsScreen::ID, 'all'),
        );
    }

    protected function item(array $row): Button
    {
        $id = (int) ($row['id'] ?? 0);
        $mark = Json::on($row, 'held') ? '✋' : Sales::mark(Sales::PAYMENT_STATUS, $row['status'] ?? '');

        return Button::to($mark . ' ' . Fmt::id($id) . ' · ' . Fmt::money(Json::text($row, 'amount')) . ' · ' . Sales::customer(Json::part($row, 'user')) . ' · ' . (string) ($row['method'] ?? ''), PaymentScreen::ID, 'show', $id);
    }
}
