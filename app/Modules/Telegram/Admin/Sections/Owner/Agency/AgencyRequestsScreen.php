<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Agency;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Owner\Words;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\ListScreen;

/**
 * «📥 درخواست‌های نمایندگی» (the panel's requests section): what customers send from the bot's «نمایندگی» page, those
 * waiting first, each opening its own screen (AgencyRequestScreen) to approve on a level or reject. The panel lists them
 * alone: the page's rows are kept for it.
 */
final class AgencyRequestsScreen extends ListScreen
{
    public const ID = 'agr';

    /** A request's status in a word, the list's tabs in its order. */
    public const STATUSES = ['pending' => '⏳ در انتظار بررسی', 'approved' => '✅ تاییدشده', 'rejected' => '🚫 ردشده'];

    public function allows(AdminSeat $seat): bool
    {
        return $seat->isOwner();
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '📥 درخواست‌های نمایندگی';
    }

    protected function path(): array
    {
        return ['مدیریت', 'نمایندگان'];
    }

    protected function endpoint(): string
    {
        return '/agency/requests';
    }

    protected function key(): string
    {
        return 'requests';
    }

    protected function filters(): array
    {
        return ['status' => ['pending' => '⏳ در انتظار', 'approved' => '✅ تاییدشده', 'rejected' => '🚫 ردشده', self::ANY => 'همه']];
    }

    protected function searchHint(): string
    {
        return 'نام، نام کاربری یا شناسه تلگرام';
    }

    protected function empty(): string
    {
        return 'درخواستی نیست. درخواست‌هایی که مشتری‌ها از صفحه «نمایندگی» ربات می‌فرستند این‌جا می‌آید؛ در گروه گزارش‌ها هم با دکمه‌های تایید و رد.';
    }

    protected function keepsRows(): bool
    {
        return true;
    }

    protected function parent(): string
    {
        return AgencyScreen::ID;
    }

    protected function item(array $row): Button
    {
        $status = mb_substr(self::STATUSES[(string) ($row['status'] ?? '')] ?? '•', 0, 1);
        $note = is_string($row['note'] ?? null) && $row['note'] !== '' ? ' · ' . Fmt::cut($row['note'], 24) : '';

        return Button::to($status . ' ' . Words::user($row['user'] ?? null) . $note, AgencyRequestScreen::ID, 'show', (int) ($row['id'] ?? 0));
    }
}
