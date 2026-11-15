<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Customers;

use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Json;

/** «📡 سرویس‌ها» of a customer's page: their services, newest first, each opening the service's screen (`sbv`). */
final class CustomerServicesScreen extends CustomerRowsScreen
{
    public const ID = 'usls';

    /** A service's state as the panel's badge says it, by its status. */
    private const STATUS = ['active' => '🟢', 'expired' => '⌛️', 'disabled' => '⏸', 'deleted' => '🗑'];

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '📡 سرویس‌ها';
    }

    protected function endpoint(): string
    {
        return '/subscriptions';
    }

    protected function key(): string
    {
        return 'subscriptions';
    }

    protected function empty(): string
    {
        return 'هنوز سرویسی نخریده است. سرویس‌هایی که این مشتری بخرد این‌جا می‌آیند.';
    }

    protected function summary(array $meta): array
    {
        return ['🟢 فعال · ⌛️ منقضی‌شده · ⏸ غیرفعال · 🗑 حذف‌شده'];
    }

    protected function item(array $row): Button
    {
        $plan = is_array($row['plan'] ?? null) ? Customer::text($row['plan'], 'name') : null;
        $server = is_array($row['server'] ?? null) ? Customer::text($row['server'], 'name') : null;
        $what = Json::on($row, 'is_test') ? 'اکانت تست' : ($plan ?? 'بدون پلن');

        return $this->open(
            (self::STATUS[(string) ($row['status'] ?? '')] ?? '•') . ' ' . Customer::isolate((string) ($row['name'] ?? '')) . ' · ' . $what . ($server !== null ? ' · ' . $server : ''),
            'sbv',
            Customer::int($row, 'id'),
        );
    }
}
