<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Customers;

use App\Modules\Telegram\Admin\Sections\Sales\Sales;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;

/**
 * «💳 پرداخت‌ها» of a customer's page: their payments, newest first, each a button — its state (✋ one held for support),
 * number, amount and method — opening the payment's screen (`pyv`).
 */
final class CustomerPaymentsScreen extends CustomerRowsScreen
{
    public const ID = 'uslp';

    public const TITLE = Icons::PAYMENTS . ' پرداخت‌ها';


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

    protected function empty(): string
    {
        return 'هنوز پرداختی نکرده است. پرداخت‌های این مشتری — کارت به کارت، آنلاین یا از کیف پول — این‌جا می‌آیند.';
    }

    protected function legend(): string
    {
        return implode(' · ', [...array_values(Sales::PAYMENT_STATUS), Sales::HELD_MARK . ' ' . Sales::HELD]);
    }

    protected function item(array $row): Button
    {
        return $this->open(
            Sales::paymentMark($row) . ' ' . implode(' · ', array_filter([
                Fmt::id(Customer::int($row, 'id')),
                Fmt::money(Customer::money($row['amount'] ?? null)),
                Customer::text($row, 'method'),
            ])),
            'pyv',
            Customer::int($row, 'id'),
        );
    }
}
