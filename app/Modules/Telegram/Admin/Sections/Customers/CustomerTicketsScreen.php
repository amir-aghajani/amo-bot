<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Customers;

use App\Modules\Telegram\Admin\Sections\Support\TicketsScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;

/** «🎧 تیکت‌ها» of a customer's page: their tickets, latest activity first, each opening the ticket's screen (`tkv`). */
final class CustomerTicketsScreen extends CustomerRowsScreen
{
    public const ID = 'uslt';

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '🎧 تیکت‌ها';
    }

    protected function endpoint(): string
    {
        return '/tickets';
    }

    protected function key(): string
    {
        return 'tickets';
    }

    protected function empty(): string
    {
        return 'هنوز تیکتی باز نکرده است. تیکت‌هایی که این مشتری برای پشتیبانی باز کند این‌جا می‌آیند.';
    }

    protected function summary(array $meta): array
    {
        return ['⏳ در انتظار پاسخ · 💬 پاسخ‌داده‌شده · 🔒 بسته‌شده'];
    }

    protected function item(array $row): Button
    {
        return $this->open(
            (TicketsScreen::MARK[(string) ($row['status'] ?? '')] ?? '•') . ' ' . Fmt::cut(Customer::text($row, 'subject'), 30) . ' · ' . Fmt::id(Customer::int($row, 'id')),
            'tkv',
            Customer::int($row, 'id'),
        );
    }
}
