<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Sales;

use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;

/**
 * «🛒 سفارش‌ها» (the panel's «سفارش‌ها»): every order, the stuck ones — paid and not delivered — a tab of their own with
 * their count, searched by number, customer, plan or service and narrowed by type, days and order (OrderFilters), with
 * what the listed sales add up to. A row opens its order (OrderScreen) to retry its delivery or cancel it.
 */
final class OrdersScreen extends SalesList
{
    public const ID = 'or';

    /** The list's title. */
    public const TITLE = Icons::ORDERS . ' ' . Sales::ORDERS;

    /** The panel's tabs: «همه», then what waits on support first — a status said as its rows say it. */
    private const TABS = [
        self::ANY => 'همه',
        'stuck' => '🆘 نیازمند رسیدگی',
        'pending' => Sales::ORDER_STATUS['pending'],
        'fulfilled' => Sales::ORDER_STATUS['fulfilled'],
        'cancelled' => Sales::ORDER_STATUS['cancelled'],
        'refunded' => Sales::ORDER_STATUS['refunded'],
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
        return '/orders';
    }

    protected function key(): string
    {
        return 'orders';
    }

    protected function noun(): string
    {
        return 'سفارش';
    }

    protected function narrower(): string
    {
        return OrderFilters::ID;
    }

    protected function statuses(): array
    {
        return self::TABS;
    }

    protected function counted(): array
    {
        return ['stuck'];
    }

    protected function searchHint(): string
    {
        return 'شماره سفارش، نام پلن، نام کاربری سرویس، یا نام، یوزرنیم، ایمیل یا شناسه تلگرام مشتری.';
    }

    protected function figures(array $meta): array
    {
        return $this->sold($meta, 'sold', 'sold_amount', 'سفارش فروخته‌شده', 'در این روزها سفارشی فروخته نشده است.');
    }

    protected function empty(): string
    {
        return match (true) {
            $this->narrowed() => 'سفارشی پیدا نشد؛ با این جستجو یا فیلتر چیزی نیست.',
            $this->tab() === 'stuck' => 'فعلا سفارشی نیست که پرداخت شده و تحویل نشده باشد.',
            $this->tab() !== self::ANY => 'سفارشی در این وضعیت نیست.',
            default => 'هنوز سفارشی ثبت نشده است؛ اولین خرید یا شارژ کیف پول از ربات این‌جا ظاهر می‌شود.',
        };
    }

    protected function item(array $row): Button
    {
        $id = (int) ($row['id'] ?? 0);

        // Chip, what it was for, amount and customer — what a row is looked for by —, the number last.
        return Button::to(
            Sales::mark(Sales::ORDER_STATUS, $row['status'] ?? '') . ' ' . Sales::word(Sales::ORDER_TYPE, $row['type'] ?? '') . ' · ' . Fmt::money(Json::text($row, 'amount'))
                . ' · ' . Fmt::cut(Sales::customer(Json::part($row, 'user')), 14) . ' · ' . Fmt::id($id),
            OrderScreen::ID,
            'show',
            $id,
        );
    }
}
