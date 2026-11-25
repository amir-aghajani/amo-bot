<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Agency;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Owner\Words;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words as UiWords;

/**
 * «📥 درخواست‌های نمایندگی» (the panel's requests section): what customers send from the bot's «نمایندگی» page to
 * become agents, and agents' renewal requests («درخواست تمدید نمایندگی») — those waiting first (the status chips), both
 * kinds or one (the kind chips), the newest first or the oldest (the panel's date column both ways: «↕️», the list's
 * state `dir`) —, each a button, the row: its status as a chip, its kind's icon (KINDS: a renewal's «🔄»), the customer
 * and the start of what they wrote, opening its own screen (AgencyRequestScreen) to approve — on a level, or a
 * renewal with an end — or reject. The panel lists them alone: the page's rows are kept for it.
 *
 * Actions besides the list's: `dir` (asc|desc: the order).
 */
final class AgencyRequestsScreen extends ListScreen
{
    public const ID = 'agr';

    /** A request's status as a chip, the list's tabs in its order. */
    public const STATUSES = ['pending' => UiWords::PENDING, 'approved' => '✅ تاییدشده', 'rejected' => UiWords::REJECTED];

    /** A request's kind (the API's `kind`) as a chip: to become an agent, or an agent's end moved on. */
    public const KINDS = ['join' => '🤝 نمایندگی', 'renewal' => '🔄 تمدید'];

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
        return AgencyScreen::path();
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
        return ['status' => self::STATUSES + [self::ANY => 'همه'], 'kind' => [self::ANY => 'هر دو نوع'] + self::KINDS];
    }

    protected function fixed(): array
    {
        return ['sort' => 'created', 'dir' => self::dir($this->here?->state(self::ID) ?? [])];
    }

    protected function noun(): string
    {
        return 'درخواست';
    }

    protected function searchHint(): string
    {
        return 'نام، نام کاربری یا شناسه تلگرام';
    }

    protected function empty(): string
    {
        return 'درخواستی نیست. درخواست‌هایی که مشتری‌ها از صفحه «نمایندگی» ربات می‌فرستند، و درخواست تمدید نماینده‌ها، این‌جا می‌آید؛ در گروه گزارش‌ها هم با دکمه‌های تایید و رد.';
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
        $status = explode(' ', self::STATUSES[(string) ($row['status'] ?? '')] ?? '•')[0];
        $renewal = AgencyRequestScreen::renewal($row);
        $kind = explode(' ', self::KINDS[$renewal ? 'renewal' : 'join'])[0];
        $note = is_string($row['note'] ?? null) && $row['note'] !== '' ? ' · ' . Fmt::cut($row['note'], 24) : '';

        return Button::to($status . ' ' . $kind . ' ' . Words::user($row['user'] ?? null) . $note, AgencyRequestScreen::ID, 'show', (int) ($row['id'] ?? 0));
    }

    protected function tools(Admin $admin): array
    {
        $asc = self::dir($admin->state(self::ID)) === 'asc';

        return [Button::to('↕️ ' . ($asc ? 'قدیمی‌ترین اول' : 'تازه‌ترین اول'), self::ID, 'dir', $asc ? 'desc' : 'asc')];
    }

    protected function action(Admin $admin, string $action, array $args): View
    {
        if ($action === 'dir') {
            $admin->remember(self::ID, ['dir' => ($args[0] ?? '') === 'asc' ? 'asc' : 'desc', 'page' => 1]);
        }

        return $this->show($admin, []);
    }

    /**
     * The order the list is in: the newest first (`desc`, the panel's default) or the oldest.
     *
     * @param array<string, mixed> $state
     */
    private static function dir(array $state): string
    {
        return ($state['dir'] ?? null) === 'asc' ? 'asc' : 'desc';
    }
}
