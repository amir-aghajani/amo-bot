<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Sales;

use App\Modules\Telegram\Admin\Ui\Admin;

/**
 * The orders list's «🎛 فیلتر و ترتیب»: the panel's «نوع» (an agent's traffic bought only in the main shop, so not in an
 * agent's), its date filter — by when the order was made —, and the order of the rows (its sortable headers).
 */
final class OrderFilters extends NarrowScreen
{
    public const ID = 'orf';

    protected function screen(): string
    {
        return self::ID;
    }

    protected function list(): string
    {
        return OrdersScreen::ID;
    }

    protected function listTitle(): string
    {
        return Sales::ORDERS;
    }

    protected function days(): string
    {
        return 'زمان ثبت سفارش';
    }

    protected function groups(Admin $admin): array
    {
        $types = Sales::ORDER_TYPE;
        if (!$admin->seat->shop()->isMain()) {
            unset($types['traffic']);
        }

        return [
            ['key' => 'type', 'label' => 'نوع', 'options' => ['' => 'همه'] + $types],
            ['key' => 'sort', 'label' => 'ترتیب', 'options' => self::SORT_BY_AMOUNT],
        ];
    }
}
