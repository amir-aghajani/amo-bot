<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Customers;

use App\Modules\Telegram\Admin\Sections\Support\TicketScreen;
use App\Modules\Telegram\Admin\Sections\Support\TicketsScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;

/**
 * «🎧 تیکت‌ها» of a customer's page: their tickets, latest activity first, each a button — its state, number and subject —
 * opening the ticket's screen (`tkv`).
 */
final class CustomerTicketsScreen extends CustomerRowsScreen
{
    public const ID = 'uslt';

    public const TITLE = Icons::SUPPORT . ' تیکت‌ها';

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
        return '/tickets';
    }

    protected function key(): string
    {
        return 'tickets';
    }

    protected function noun(): string
    {
        return 'تیکت';
    }

    protected function empty(): string
    {
        return 'هنوز تیکتی باز نکرده است. تیکت‌هایی که این مشتری برای پشتیبانی باز کند این‌جا می‌آیند.';
    }

    protected function legend(): string
    {
        return implode(' · ', array_diff_key(TicketsScreen::STATUSES, [self::ANY => true]));
    }

    protected function item(array $row): Button
    {
        return $this->open(
            // As the shop's tickets list has its rows, the customer left out: «chip #number · subject».
            (TicketsScreen::MARK[(string) ($row['status'] ?? '')] ?? '•') . ' ' . Fmt::id(Customer::int($row, 'id')) . ' · ' . Fmt::cut(Customer::text($row, 'subject'), 30),
            TicketScreen::ID,
            Customer::int($row, 'id'),
        );
    }
}
