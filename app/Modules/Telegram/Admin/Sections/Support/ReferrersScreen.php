<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Support;

use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\Nav;

/**
 * «👥 معرف‌ها» (the referrals page's first section, GET /referrals/referrers): the customers whose link brought someone,
 * the most referrals first — or by what they earned, or their last referral (the panel's sortable headers) —, each
 * opening its own screen (ReferrerScreen).
 */
final class ReferrersScreen extends ListScreen
{
    public const ID = 'rfr';

    /** The panel's sortable headers, its own order first. */
    private const SORTS = ['referrals' => 'بیشترین زیرمجموعه', 'earned' => 'بیشترین پورسانت', 'last_referral' => 'تازه‌ترین زیرمجموعه'];

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '👥 معرف‌ها';
    }

    protected function path(): array
    {
        return ['مدیریت', 'فروش', 'زیرمجموعه‌گیری'];
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
        return ['sort' => self::SORTS];
    }

    protected function searchHint(): string
    {
        return 'نام، نام کاربری یا شناسه تلگرام معرف';
    }

    protected function empty(): string
    {
        return 'هنوز لینکی کسی را نیاورده است. اولین مشتری‌ای که با لینک دعوت یک نفر وارد ربات شود، معرفش این‌جا می‌آید.';
    }

    protected function summary(array $meta): array
    {
        return ['مشتری‌هایی که لینک دعوتشان کسی را به ربات آورده؛ با پورسانتی که تا حالا گرفته‌اند.'];
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
        $earned = is_numeric($row['earned'] ?? null) ? (string) $row['earned'] : '0';

        return Button::to(
            ((int) ($row['alerts'] ?? 0) > 0 ? '⚠️ ' : '') . Words::name($user) . ' · ' . Fmt::number((int) ($row['referrals'] ?? 0)) . ' نفر' . ((float) $earned > 0 ? ' · ' . Fmt::plain(Fmt::money($earned)) : ''),
            ReferrerScreen::ID,
            Nav::SHOW,
            (int) ($row['id'] ?? 0),
        );
    }
}
