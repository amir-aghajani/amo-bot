<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Agency;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
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
 * An agent's renewal request says their end now (passed or not) and, while it waits, the end the program's term gives;
 * once approved, the end it gave — its «✅ تایید» asks the end (AgencyRenewScreen), never a level. The seat's own request
 * (AgentScreen::own()) has no «✅ تایید»: another admin approves it.
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

        return $row === null ? $admin->open(AgencyRequestsScreen::ID) : $this->view($admin, $row);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        switch ($action) {
            case 'no':
                $row = self::row($admin, $id) ?? [];
                $renewal = self::renewal($row);

                return Words::withNote(
                    $admin,
                    ($renewal ? 'درخواست تمدید ' : 'درخواست ') . Words::user($row['user'] ?? null) . ' رد شود؟',
                    ($renewal ? 'درخواست تمدید نمایندگی ' : 'درخواست نمایندگی ') . Fmt::e(Words::user($row['user'] ?? null)) . ' رد می‌شود و به او خبر داده می‌شود.',
                    Button::to('✍️ رد با توضیح', self::ID, 'nw', $id)->danger(),
                    Button::to('⏭ رد بدون توضیح', self::ID, 'nn', $id)->danger(),
                    Button::to(UiWords::CANCEL, self::ID, 'show', $id),
                );
            case 'nw':
                $example = self::renewal(self::row($admin, $id) ?? []) ? 'اول بدهی کیف پولتان را پرداخت کنید' : 'فعلا ظرفیت نمایندگی تازه نداریم';

                return $admin->ask((new Prompt('توضیح رد درخواست', self::ID, 'no', [$id], Words::NOTE_HINT, Button::to(UiWords::CANCEL, self::ID, 'show', $id)))->example($example));
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
     * Request `$row` (the list's kept one) as the API has it now (GET /agency/requests/{id}), kept in its place; null when
     * it is gone.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     * @throws ApiRefusal
     */
    public static function fresh(Admin $admin, array $row): ?array
    {
        try {
            $answer = $admin->api->get('/agency/requests/' . (int) ($row['id'] ?? 0));
        } catch (ApiRefusal $refusal) {
            if ($refusal->status === 404) {
                return null;
            }

            throw $refusal;
        }
        $found = Json::part($answer, 'request');

        return $found === [] ? null : self::keep($admin, $found);
    }

    /**
     * Whether the request asks for an agent's end to be moved on, not to become an agent (a row with no `kind`: the
     * latter).
     *
     * @param array<string, mixed> $row
     */
    public static function renewal(array $row): bool
    {
        return ($row['kind'] ?? null) === 'renewal';
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

        return $this->view($admin, self::keep($admin, $row));
    }

    /** @param array<string, mixed> $row */
    private function view(Admin $admin, array $row): View
    {
        $id = (int) ($row['id'] ?? 0);
        $level = is_array($row['level'] ?? null) ? $row['level'] : null;
        $status = (string) ($row['status'] ?? '');
        $renewal = self::renewal($row);
        $gone = $renewal && ($row['agent'] ?? true) === false;
        $end = static fn(string $key): ?string => is_string($row[$key] ?? null) ? $row[$key] : null;
        $view = View::make(($renewal ? '🔄 درخواست تمدید نمایندگی ' : '📥 درخواست نمایندگی ') . Fmt::id($id), ...[...AgencyScreen::path(), 'درخواست‌ها'])
            ->card([
                ($renewal ? 'نماینده' : 'مشتری') => Words::who($row['user'] ?? null) . (($row['user_status'] ?? null) === 'banned' ? ' · ⛔️ مسدود' : '') . ($gone ? ' · دیگر نماینده نیست' : ''),
                'وضعیت' => Fmt::e(AgencyRequestsScreen::STATUSES[$status] ?? $status),
                'سطح' => $level === null ? null : Fmt::e((string) ($level['name'] ?? '')) . ' · هر گیگابایت ' . Fmt::money((string) ($level['price_per_gb'] ?? '0')),
                // A renewal: the agent's end now while it is not approved (an agent no more has none, as the panel's), the end the program's term gives while it waits, the end it gave once approved.
                'پایان نمایندگی' => $renewal && !$gone && $status !== 'approved' ? AgencyEnd::current($end('expires_at')) : null,
                'تمدید با مدت نمایندگی' => $renewal && $status === 'pending' && $end('term_end') !== null ? AgencyEnd::words(AgencyEnd::of($end('term_end'))) : null,
                'تمدید تا' => $renewal && $status === 'approved' ? AgencyEnd::words(AgencyEnd::of($end('extended_to'))) : null,
                'توضیح پشتیبانی' => $status === 'rejected' && is_string($row['reason'] ?? null) && $row['reason'] !== '' ? Fmt::e($row['reason']) : null,
                'تصمیم' => is_string($row['decided_at'] ?? null) ? implode(' · ', array_filter([Words::reviewer($row), Fmt::date($row['decided_at'], true)])) : null,
                'زمان' => Fmt::date(is_string($row['created_at'] ?? null) ? $row['created_at'] : null, true),
            ]);
        if (is_string($row['note'] ?? null) && $row['note'] !== '') {
            $view->section('✍️ نوشته مشتری')->line('<blockquote>' . Fmt::e($row['note']) . '</blockquote>');
        }
        $actions = Json::part($row, 'actions');
        // Nobody approves their own request (AgencyActions: ActorRefusedException::ownRequest()); rejecting it stays.
        $user = is_array($row['user'] ?? null) ? $row['user'] : [];
        $own = AgentScreen::own($admin, is_int($user['id'] ?? null) ? $user['id'] : 0);
        if ($own && Json::on($actions, 'approve')) {
            $view->note('این درخواست خود شماست؛ تاییدش با مدیر دیگری است.');
        }

        return $view->row(
            !$own && Json::on($actions, 'approve') ? Button::to('✅ تایید', $renewal ? AgencyRenewScreen::ID : AgencyApproveScreen::ID, 'new', $id)->success() : null,
            Json::on($actions, 'reject') ? Button::to('🚫 رد', self::ID, 'no', $id)->danger() : null,
        )->back(AgencyRequestsScreen::ID);
    }
}
