<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Agency;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Owner\Words;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words as UiWords;

/**
 * One agency request (from the list's kept page — the panel has no route for one): the customer, what they wrote, its
 * standing — the level given, support's reason, who decided when —, and what its status allows (the panel's `actions`):
 * «✅ تایید» on a level and terms (AgencyApproveScreen), «🚫 رد» asked first — with a note the customer reads, or none.
 *
 * Actions: `no` (id: asks), `nw` (id: asks the note), `nn` (id: rejects without one).
 */
final class AgencyRequestScreen extends BaseScreen
{
    public const ID = 'agrv';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->isOwner();
    }

    public function show(Admin $admin, array $args): View
    {
        $row = self::row($admin, self::id($args));

        return $row === null ? $admin->open(AgencyRequestsScreen::ID) : $this->view($row);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        switch ($action) {
            case 'no':
                $row = self::row($admin, $id) ?? [];

                return Words::withNote(
                    $admin,
                    'درخواست ' . Words::user($row['user'] ?? null) . ' رد شود؟',
                    'درخواست نمایندگی ' . Fmt::e(Words::user($row['user'] ?? null)) . ' رد می‌شود و به او خبر داده می‌شود.',
                    Button::to('✍️ رد با توضیح', self::ID, 'nw', $id)->danger(),
                    Button::to('⏭ رد بدون توضیح', self::ID, 'nn', $id)->danger(),
                    Button::to(UiWords::CANCEL, self::ID, 'show', $id),
                );
            case 'nw':
                return $admin->ask((new Prompt('توضیح رد درخواست', self::ID, 'no', [$id], Words::NOTE_HINT, Button::to(UiWords::CANCEL, self::ID, 'show', $id)))->example('فعلا ظرفیت نمایندگی تازه نداریم'));
            case 'nn':
                return $this->reject($admin, $id, '');
        }

        return $this->show($admin, $args);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        return $action === 'no' ? $this->reject($admin, self::id($args), $answer->text()) : $this->show($admin, $args);
    }

    /**
     * The request from the list's kept page; null when it is not there (the list moved on).
     *
     * @return array<string, mixed>|null
     */
    public static function row(Admin $admin, int $id): ?array
    {
        return ListScreen::keptRow($admin, AgencyRequestsScreen::ID, $id);
    }

    /**
     * The request as the API answered it, kept for the list's page too.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function keep(Admin $admin, array $row): array
    {
        $rows = (array) ($admin->state(AgencyRequestsScreen::ID)['rows'] ?? []);
        $rows[(int) ($row['id'] ?? 0)] = $row;
        $admin->remember(AgencyRequestsScreen::ID, ['rows' => $rows]);

        return $row;
    }

    private function reject(Admin $admin, int $id, string $note): View
    {
        $row = (array) ($admin->api->post("/agency/requests/{$id}/reject", ['note' => $note])['request'] ?? []);
        $admin->notice('🚫 درخواست رد شد');

        return $this->view(self::keep($admin, $row));
    }

    /** @param array<string, mixed> $row */
    private function view(array $row): View
    {
        $id = (int) ($row['id'] ?? 0);
        $level = is_array($row['level'] ?? null) ? $row['level'] : null;
        $view = View::make('📥 درخواست نمایندگی ' . Fmt::id($id), ...[...AgencyScreen::path(), 'درخواست‌ها'])
            ->card([
                'مشتری' => Words::who($row['user'] ?? null) . (($row['user_status'] ?? null) === 'banned' ? ' · ⛔️ مسدود' : ''),
                'وضعیت' => Fmt::e(AgencyRequestsScreen::STATUSES[(string) ($row['status'] ?? '')] ?? (string) ($row['status'] ?? '')),
                'سطح' => $level === null ? null : Fmt::e((string) ($level['name'] ?? '')) . ' · هر گیگابایت ' . Fmt::money((string) ($level['price_per_gb'] ?? '0')),
                'توضیح پشتیبانی' => ($row['status'] ?? null) === 'rejected' && is_string($row['reason'] ?? null) && $row['reason'] !== '' ? Fmt::e($row['reason']) : null,
                'تصمیم' => is_string($row['decided_at'] ?? null) ? implode(' · ', array_filter([Words::reviewer($row), Fmt::date($row['decided_at'], true)])) : null,
                'زمان' => Fmt::date(is_string($row['created_at'] ?? null) ? $row['created_at'] : null, true),
            ]);
        if (is_string($row['note'] ?? null) && $row['note'] !== '') {
            $view->section('✍️ نوشته مشتری')->line('<blockquote>' . Fmt::e($row['note']) . '</blockquote>');
        }
        $actions = Json::part($row, 'actions');

        return $view->row(
            Json::on($actions, 'approve') ? Button::to('✅ تایید', AgencyApproveScreen::ID, 'new', $id)->success() : null,
            Json::on($actions, 'reject') ? Button::to('🚫 رد', self::ID, 'no', $id)->danger() : null,
        )->back(AgencyRequestsScreen::ID);
    }
}
