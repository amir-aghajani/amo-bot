<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Customers;

use App\Modules\Telegram\Admin\Sections\Sales\Sales;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;

/** «🛒 سفارش‌ها» of a customer's page: their orders, newest first, each opening the order's screen (`orv`). */
final class CustomerOrdersScreen extends CustomerRowsScreen
{
    public const ID = 'uslo';

    /** Its state, by its status. */
    private const STATUS = ['pending' => '⏳', 'paid' => '💰', 'processing' => '⚙️', 'fulfilled' => '✅', 'failed' => '⚠️', 'cancelled' => '✖️', 'refunded' => '↩️'];

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '🛒 سفارش‌ها';
    }

    protected function endpoint(): string
    {
        return '/orders';
    }

    protected function key(): string
    {
        return 'orders';
    }

    protected function empty(): string
    {
        return 'هنوز سفارشی ثبت نکرده است. خریدها، تمدیدها و شارژهای این مشتری این‌جا می‌آیند.';
    }

    protected function summary(array $meta): array
    {
        return ['✅ تکمیل‌شده · ⏳ در انتظار پرداخت · ⚙️ در حال ساخت · ⚠️ ناموفق · ✖️ لغوشده · ↩️ بازپرداخت‌شده'];
    }

    protected function item(array $row): Button
    {
        $package = Customer::text($row, 'package_name');

        return $this->open(
            (self::STATUS[(string) ($row['status'] ?? '')] ?? '•') . ' ' . Fmt::id(Customer::int($row, 'id')) . ' · ' . (Sales::ORDER_TYPE[(string) ($row['type'] ?? '')] ?? '') . ($package !== null ? ' · ' . $package : '') . ' · ' . Fmt::money(Customer::money($row['amount'] ?? null)),
            'orv',
            Customer::int($row, 'id'),
        );
    }
}
