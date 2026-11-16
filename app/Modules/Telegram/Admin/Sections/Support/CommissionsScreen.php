<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Support;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «💰 پورسانت‌ها» (GET /referrals/commissions): the commissions paid into referrers' wallets, ordered by when or how
 * much — either way —, searched by a payment's number or the customers on either side, each a row «amount · referrer ·
 * payment» opening its own screen (CommissionScreen).
 */
final class CommissionsScreen extends ListScreen
{
    public const ID = 'rfc';

    /** The panel's sortable headers, its own order first. */
    private const SORTS = ['created' => 'زمان', 'commission' => 'مبلغ پورسانت'];

    /** The ones of them that are moments. */
    private const MOMENTS = ['created'];

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return ReferralsScreen::COMMISSIONS;
    }

    protected function path(): array
    {
        return ReferralsScreen::under();
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
        return ['sort' => self::SORTS, Sorting::DIR => Sorting::directions($this->here, self::ID, self::SORTS, self::MOMENTS)];
    }

    protected function noun(): string
    {
        return 'پورسانت';
    }

    protected function searchHint(): string
    {
        return 'شماره پرداخت، معرف یا زیرمجموعه';
    }

    protected function empty(): string
    {
        return 'هنوز پورسانتی پرداخت نشده است. اولین پرداخت موفق یک زیرمجموعه، پورسانت معرفش را این‌جا می‌آورد.';
    }

    protected function extra(View $view, Admin $admin): void
    {
        $view->help('پورسانت‌هایی که بابت پرداخت زیرمجموعه‌ها به کیف پول معرف‌ها اضافه شده؛ هر کدام بابت یک پرداخت.');
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
            Fmt::plain(Fmt::money(Words::figure($row['commission'] ?? null))) . ' · ' . Fmt::cut(Words::name($referrer), 20) . ' · پرداخت ' . Fmt::id(is_int($payment['id'] ?? null) ? $payment['id'] : null),
            CommissionScreen::ID,
            Nav::SHOW,
            (int) ($row['id'] ?? 0),
        );
    }
}
