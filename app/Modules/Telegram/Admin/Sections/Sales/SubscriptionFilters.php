<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Sales;

use App\Modules\Telegram\Admin\Ui\Admin;

/**
 * The services list's «🎛 فیلتر و ترتیب»: the panel's «سرور» (read as the seat's panel reads them) and «نوع» — the
 * services added by their link only in the main shop, the only one that takes them —, and the order of the rows (its
 * sortable headers: the newest, the soonest to end, the most used).
 */
final class SubscriptionFilters extends NarrowScreen
{
    public const ID = 'sbf';

    protected function screen(): string
    {
        return self::ID;
    }

    protected function list(): string
    {
        return SubscriptionsScreen::ID;
    }

    protected function listTitle(): string
    {
        return 'اشتراک‌ها';
    }

    protected function groups(Admin $admin): array
    {
        $servers = ['' => 'همه'];
        foreach (Sales::servers($admin) as $server) {
            $servers[(string) $server['id']] = $server['name'];
        }
        $kinds = ['' => 'همه', 'test' => 'اکانت تست', 'regular' => 'سرویس عادی', 'claimed' => 'افزوده‌شده با لینک'];
        if (!$admin->seat->shop()->isMain()) {
            unset($kinds['claimed']);
        }

        return [
            ['key' => 'server', 'label' => 'سرور', 'options' => $servers],
            ['key' => 'kind', 'label' => 'نوع', 'options' => $kinds],
            ['key' => 'sort', 'label' => 'ترتیب', 'options' => ['' => 'تازه‌ترین', 'expires.asc' => 'زودتر تمام می‌شود', 'used.desc' => 'بیشترین مصرف']],
        ];
    }
}
