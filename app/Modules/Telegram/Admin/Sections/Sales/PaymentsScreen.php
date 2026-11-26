<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Sales;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «💳 پرداخت‌ها» (the panel's «پرداخت‌ها»): every payment, the receipts waiting for review and the online payments held
 * for support tabs of their own with their counts, searched by payment, order or customer and narrowed by days and order
 * (PaymentFilters), with what the paid ones add up to. A row opens its payment (PaymentScreen) with its receipt. The
 * review tab starts the review one receipt after another (PaymentScreen's queue: «تایید و بعدی»), offers picking
 * receipts to reject at once, and — the list not narrowed — rejecting every one waiting (ReceiptsScreen).
 */
final class PaymentsScreen extends SalesList
{
    public const ID = 'py';

    /** The list's title. */
    public const TITLE = Icons::PAYMENTS . ' ' . Sales::PAYMENTS;

    /** The review queue's tab. */
    public const REVIEW = 'awaiting_review';

    /** The way into the queue: the first receipt waiting, then the next after each decision. */
    public const START_REVIEW = '▶️ بررسی رسیدها یکی‌یکی';

    /** The panel's tabs: «همه», then what waits on support first — a status said as its rows say it. */
    private const TABS = [
        self::ANY => 'همه',
        self::REVIEW => Sales::PAYMENT_STATUS[self::REVIEW],
        'held' => Sales::HELD_MARK . ' نگه داشته شده',
        'paid' => Sales::PAYMENT_STATUS['paid'],
        'failed' => Sales::PAYMENT_STATUS['failed'],
        'pending' => Sales::PAYMENT_STATUS['pending'],
    ];

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return self::TITLE;
    }

    protected function endpoint(): string
    {
        return '/payments';
    }

    protected function key(): string
    {
        return 'payments';
    }

    protected function noun(): string
    {
        return 'پرداخت';
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
        return 'شماره پرداخت، شماره سفارش، یا نام، یوزرنیم، ایمیل یا شناسه تلگرام مشتری.';
    }

    protected function figures(array $meta): array
    {
        return $this->sold($meta, 'paid', 'paid_amount', 'پرداخت موفق', 'در این روزها پرداخت موفقی نیست.');
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

    protected function extra(View $view, Admin $admin): void
    {
        if ($this->tab() !== self::REVIEW || $this->count(self::REVIEW) === 0) {
            return;
        }
        $view->row(Button::to(self::START_REVIEW, PaymentScreen::ID, 'nx', 0)->primary());
        $view->row(
            Button::to(ReceiptsScreen::TITLE, ReceiptsScreen::ID),
            // Every receipt of the shop: offered only while no search or filter makes the list look like fewer.
            $this->narrowed() ? null : Button::to(ReceiptsScreen::ALL, ReceiptsScreen::ID, 'all')->danger(),
        );
    }

    protected function item(array $row): Button
    {
        return Button::to(Sales::paymentMark($row) . ' ' . Sales::paymentRow($row), PaymentScreen::ID, 'show', (int) ($row['id'] ?? 0));
    }
}
