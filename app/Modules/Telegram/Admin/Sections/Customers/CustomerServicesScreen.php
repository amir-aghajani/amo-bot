<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Customers;

use App\Modules\Telegram\Admin\Sections\Sales\Sales;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;

/**
 * «📡 سرویس‌ها» of a customer's page: their services, newest first, narrowed by state (chips: the shop's service list's
 * own, which say what the rows' marks mean), each a button — its state, name, plan and what is left of a running one —
 * opening the service's screen (`sbv`).
 */
final class CustomerServicesScreen extends CustomerRowsScreen
{
    public const ID = 'usls';

    public const TITLE = Icons::SERVICES . ' سرویس‌ها';

    /** The states its chips narrow it to: the shop's service list's, «همه» first. */
    private const STATES = ['active', 'expired', 'disabled'];

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
        return '/subscriptions';
    }

    protected function key(): string
    {
        return 'subscriptions';
    }

    protected function noun(): string
    {
        return 'سرویس';
    }

    protected function filters(): array
    {
        $states = [self::ANY => 'همه'];
        foreach (self::STATES as $state) {
            $states[$state] = Sales::word(Sales::SUBSCRIPTION_STATUS, $state);
        }

        return ['status' => $states];
    }

    protected function empty(): string
    {
        return 'سرویسی پیدا نشد. سرویس‌هایی که این مشتری بخرد این‌جا می‌آیند.';
    }

    protected function item(array $row): Button
    {
        $plan = Customer::text(Json::part($row, 'plan'), 'name');
        $expires = Customer::text($row, 'expires_at');
        $left = ($row['status'] ?? null) === 'active' && $expires !== null ? Sales::left($expires) : null;

        return $this->open(
            Sales::mark(Sales::SUBSCRIPTION_STATUS, $row['status'] ?? '') . ' ' . implode(' · ', array_filter([
                // The name and the plan cut, so what is left of it stays whole on the button.
                Customer::isolate(Fmt::cut((string) ($row['name'] ?? ''), 16)),
                Json::on($row, 'is_test') ? 'اکانت تست' : Fmt::cut($plan ?? 'بدون پلن', 14),
                $left === null ? null : Fmt::plain($left),
            ])),
            'sbv',
            Customer::int($row, 'id'),
        );
    }
}
