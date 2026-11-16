<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Support;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words as UiWords;

/**
 * «⭐️ نظرات» (the panel's «نظرات»): the reviews written on the website by the panel's tabs — the moderation queue
 * («در انتظار») first —, each a row «chip ⭐rating · signature · words» opening its own screen (ReviewScreen) to approve,
 * reject or delete it at a tap. The panel's list route alone: the page's rows are kept for it.
 */
final class ReviewsScreen extends ListScreen
{
    public const ID = 'rv';

    public const TITLE = Icons::REVIEWS . ' نظرات';

    /** The panel's status tabs, the queue first, «همه» last. */
    public const STATUSES = ['pending' => UiWords::PENDING, 'approved' => '✅ تاییدشده', 'rejected' => UiWords::REJECTED, self::ANY => 'همه'];

    /** A review's state in its row's chip. */
    public const MARK = ['pending' => '🟡', 'approved' => '✅', 'rejected' => '🔴'];

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return self::TITLE;
    }

    protected function path(): array
    {
        return [UiWords::ROOT];
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

    protected function noun(): string
    {
        return 'نظر';
    }

    protected function searchHint(): string
    {
        return 'نام نویسنده، متن نظر یا شماره آن (مثل #۱۲)';
    }

    protected function empty(): string
    {
        return $this->tab() === 'pending' ? UiWords::NOTHING_WAITING : UiWords::NOTHING_FOUND;
    }

    protected function summary(array $meta): array
    {
        $pending = is_numeric($meta['pending'] ?? null) ? (int) $meta['pending'] : 0;

        // Beside another tab, how many wait: the queue's own tab says it in its count.
        return $this->tab() !== 'pending' && $pending > 0 ? ['🟡 ' . Fmt::number($pending) . ' نظر در انتظار بررسی است.'] : [];
    }

    protected function extra(View $view, Admin $admin): void
    {
        $view->help('نظرهایی که مشتری‌ها در وب‌سایت فروشگاه نوشته‌اند. نظر تاییدشده روی سایت دیده می‌شود؛ نظر ردشده نه، و می‌شود بعدا تاییدش کرد.');
    }

    protected function keepsRows(): bool
    {
        return true;
    }

    protected function item(array $row): Button
    {
        return Button::to(
            (self::MARK[(string) ($row['status'] ?? '')] ?? '•') . ' ⭐' . Fmt::digits((string) max(1, min(5, (int) ($row['rating'] ?? 0)))) . ' · ' . Fmt::cut((string) ($row['name'] ?? ''), 16) . ' · ' . Fmt::cut((string) ($row['body'] ?? ''), 24),
            ReviewScreen::ID,
            Nav::SHOW,
            (int) ($row['id'] ?? 0),
        );
    }

    /** The tab in force. */
    private function tab(): string
    {
        return $this->filterValue($this->here?->state(self::ID) ?? [], 'status');
    }
}
