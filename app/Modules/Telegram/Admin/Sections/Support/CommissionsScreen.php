<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Support;

use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\Nav;

/**
 * «🧾 پورسانت‌ها» (GET /referrals/commissions): the commissions paid into referrers' wallets, newest first — or the
 * largest —, searched by a payment's number or the customers on either side, each opening its own screen
 * (CommissionScreen).
 */
final class CommissionsScreen extends ListScreen
{
    public const ID = 'rfc';

    /** The panel's sortable headers, its own order first. */
    private const SORTS = ['created' => 'تازه‌ترین', 'commission' => 'بیشترین پورسانت'];

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '🧾 پورسانت‌ها';
    }

    protected function path(): array
    {
        return ['مدیریت', 'فروش', 'زیرمجموعه‌گیری'];
    }

    protected function endpoint(): string
    {
        return '/referrals/commissions';
    }

    protected function key(): string
    {
        return 'commissions';
    }

    protected function filters(): array
    {
        return ['sort' => self::SORTS];
    }

    protected function searchHint(): string
    {
        return 'شماره پرداخت، معرف یا زیرمجموعه';
    }

    protected function empty(): string
    {
        return 'هنوز پورسانتی پرداخت نشده است. اولین پرداخت موفق یک زیرمجموعه، پورسانت معرفش را این‌جا می‌آورد.';
    }

    protected function summary(array $meta): array
    {
        return ['پورسانت‌هایی که بابت پرداخت زیرمجموعه‌ها به کیف پول معرف‌ها اضافه شده.'];
    }

    protected function keepsRows(): bool
    {
        return true;
    }

    protected function parent(): string
    {
        return ReferralsScreen::ID;
    }

    protected function item(array $row): Button
    {
        $referrer = is_array($row['referrer'] ?? null) ? $row['referrer'] : null;
        $payment = Json::part($row, 'payment');

        return Button::to(
            Fmt::plain(Fmt::money(is_numeric($row['commission'] ?? null) ? (string) $row['commission'] : '0')) . ' · ' . Words::name($referrer) . ' · پرداخت ' . Fmt::id(is_int($payment['id'] ?? null) ? $payment['id'] : null),
            CommissionScreen::ID,
            Nav::SHOW,
            (int) ($row['id'] ?? 0),
        );
    }
}
