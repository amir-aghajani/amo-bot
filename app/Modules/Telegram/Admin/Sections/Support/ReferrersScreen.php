<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Support;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «🧲 معرف‌ها» (the referrals page's first section, GET /referrals/referrers): the customers whose link brought someone,
 * ordered by how many they brought, what they earned or their last referral — either way, as the panel's sortable
 * headers —, each a row «⚠️ name · how many · earned» opening its own screen (ReferrerScreen).
 */
final class ReferrersScreen extends ListScreen
{
    public const ID = 'rfr';

    /** The panel's sortable headers, its own order first. */
    private const SORTS = ['referrals' => 'تعداد زیرمجموعه', 'earned' => 'پورسانت', 'last_referral' => 'آخرین زیرمجموعه'];

    /** The ones of them that are moments. */
    private const MOMENTS = ['last_referral'];

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return ReferralsScreen::REFERRERS;
    }

    protected function path(): array
    {
        return ReferralsScreen::under();
    }

    protected function endpoint(): string
    {
        return '/referrals/referrers';
    }

    protected function key(): string
    {
        return 'referrers';
    }

    protected function filters(): array
    {
        return ['sort' => self::SORTS, Sorting::DIR => Sorting::directions($this->here, self::ID, self::SORTS, self::MOMENTS)];
    }

    protected function noun(): string
    {
        return 'معرف';
    }

    protected function searchHint(): string
    {
        return 'نام، نام کاربری یا شناسه تلگرام معرف';
    }

    protected function empty(): string
    {
        return 'هنوز لینکی کسی را نیاورده است. اولین مشتری‌ای که با لینک دعوت یک نفر وارد ربات شود، معرفش این‌جا می‌آید.';
    }

    protected function extra(View $view, Admin $admin): void
    {
        $view->help('مشتری‌هایی که لینک دعوتشان کسی را به ربات آورده، با تعداد زیرمجموعه‌ها و پورسانتی که تا حالا گرفته‌اند. ⚠️ کنار نام: هشداری روی این معرف است.');
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
        $user = is_array($row['user'] ?? null) ? $row['user'] : null;
        $earned = Words::figure($row['earned'] ?? null);

        return Button::to(
            ((int) ($row['alerts'] ?? 0) > 0 ? '⚠️ ' : '') . Fmt::cut(Words::name($user), 24) . ' · ' . Fmt::number((int) ($row['referrals'] ?? 0)) . ' نفر' . ((float) $earned > 0 ? ' · ' . Fmt::plain(Fmt::money($earned)) : ''),
            ReferrerScreen::ID,
            Nav::SHOW,
            (int) ($row['id'] ?? 0),
        );
    }
}
