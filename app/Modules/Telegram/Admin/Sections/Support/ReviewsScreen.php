<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Support;

use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\ListScreen;

/**
 * «⭐️ نظرات» (the panel's «نظرات»): the reviews written on the website — those waiting first —, each opening its own
 * screen (ReviewScreen) to approve, reject or delete. The panel's list route alone: the page's rows are kept for it.
 */
final class ReviewsScreen extends ListScreen
{
    public const ID = 'rv';

    /** The panel's status tabs, «در انتظار» first. */
    public const STATUSES = ['pending' => '⏳ در انتظار', 'approved' => '✅ تاییدشده', 'rejected' => '🚫 ردشده', self::ANY => 'همه'];

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '⭐️ نظرات';
    }

    protected function path(): array
    {
        return ['مدیریت'];
    }

    protected function endpoint(): string
    {
        return '/reviews';
    }

    protected function key(): string
    {
        return 'reviews';
    }

    protected function filters(): array
    {
        return ['status' => self::STATUSES];
    }

    protected function searchHint(): string
    {
        return 'نام نویسنده یا متن نظر';
    }

    protected function empty(): string
    {
        return 'نظری نیست.';
    }

    protected function keepsRows(): bool
    {
        return true;
    }

    protected function item(array $row): Button
    {
        $rating = max(1, min(5, (int) ($row['rating'] ?? 0)));

        return Button::to(str_repeat('⭐', $rating) . ' ' . (string) ($row['name'] ?? '') . ' · ' . Fmt::cut((string) ($row['body'] ?? ''), 30), ReviewScreen::ID, 'show', (int) ($row['id'] ?? 0));
    }
}
