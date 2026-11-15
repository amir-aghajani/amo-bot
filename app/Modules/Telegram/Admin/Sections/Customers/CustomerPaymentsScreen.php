<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Customers;

use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;

/** «💳 پرداخت‌ها» of a customer's page: their payments, newest first, each opening the payment's screen (`pyv`). */
final class CustomerPaymentsScreen extends CustomerRowsScreen
{
    public const ID = 'uslp';

    /** A payment's state, by its status. */
    private const STATUS = ['pending' => '⏳', 'awaiting_review' => '🧾', 'paid' => '✅', 'failed' => '⚠️', 'cancelled' => '✖️', 'refunded' => '↩️'];

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

    protected function empty(): string
    {
        return 'هنوز پرداختی نکرده است. پرداخت‌های این مشتری — کارت به کارت، آنلاین یا از کیف پول — این‌جا می‌آیند.';
    }

    protected function summary(array $meta): array
    {
        return ['✅ پرداخت‌شده · 🧾 در انتظار بررسی · ⏳ در انتظار پرداخت · ⚠️ ناموفق · ✖️ لغوشده · ↩️ بازپرداخت‌شده'];
    }

    protected function item(array $row): Button
    {
        return $this->open(
            (self::STATUS[(string) ($row['status'] ?? '')] ?? '•') . ' ' . Fmt::id(Customer::int($row, 'id')) . ' · ' . Fmt::money(Customer::money($row['amount'] ?? null)) . ' · ' . (Customer::text($row, 'method') ?? ''),
            'pyv',
            Customer::int($row, 'id'),
        );
    }
}
