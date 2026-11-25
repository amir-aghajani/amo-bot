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
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words as UiWords;

/**
 * Approving an agent's renewal request («درخواست تمدید نمایندگی»): the agency's new end — at first the one the program's
 * «مدت نمایندگی» gives (the row's `term_end`, read again as the card opens), else none picked yet, so no tap gives an
 * agency without an end unasked —, picked as an agent's terms pick one (AgencyEnd::prompt(): that end again, «بدون
 * پایان», some days from now, a Jalali day typed), then «✔️ تایید و تمدید»: one POST …/approve with `expires_at` the end
 * the card shows, null for none. The agent is told by the API.
 *
 * State: `for` (the request's id), `name`, `ends` (the agent's end now, ISO; null for none), `term` (the program's end, a
 * moment; null for none), `expires` (the end picked: a moment, or null for none), `picked` (whether one is), `was` (the
 * end as the card first showed it: what a change is measured against).
 *
 * Actions: `new` (request id), `ex` (asks the end), `ext` (the program's end), `exn` (no end), `exd` (days from now),
 * `save`, `quit` (the way out: a changed end asked first), `gone` (the end dropped).
 */
final class AgencyRenewScreen extends BaseScreen
{
    public const ID = 'agrn';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->isOwner();
    }

    public function show(Admin $admin, array $args): View
    {
        $state = $admin->state(self::ID);

        return isset($state['for']) ? $this->card($admin, $state) : $admin->open(AgencyRequestsScreen::ID);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        if ($action === 'new') {
            return $this->start($admin, self::id($args));
        }
        $state = $admin->state(self::ID);
        if (!isset($state['for'])) {
            return $admin->open(AgencyRequestsScreen::ID);
        }
        $term = self::term($state);
        $changes = match ($action) {
            'ext' => $term === null ? null : ['expires' => $term, 'picked' => true],
            'exn' => ['expires' => null, 'picked' => true],
            'exd' => ['expires' => AgencyEnd::in(max(1, self::id($args))), 'picked' => true],
            default => null,
        };
        if ($changes !== null) {
            $admin->remember(self::ID, $changes);

            return $this->card($admin, $admin->state(self::ID));
        }

        switch ($action) {
            case 'ex':
                return $admin->ask(AgencyEnd::prompt(
                    self::ID,
                    'پایان تازه نمایندگی',
                    Json::on($state, 'picked') ? Fmt::plain(AgencyEnd::words(self::moment($state))) : 'انتخاب نشده',
                    $term === null ? '' : '«🔁» پایانی است که مدت نمایندگی می‌دهد.',
                    ...($term === null ? [] : [Button::to('🔁 ' . Fmt::plain(AgencyEnd::words($term)), self::ID, 'ext')]),
                ));
            case 'save':
                return $this->save($admin, $state);
            case 'quit':
            case 'gone':
                if ($action === 'quit' && self::edited($state)) {
                    return Words::discard($admin, self::ID, false);
                }
                $admin->forget(self::ID);

                return $admin->open(AgencyRequestScreen::ID, 'show', [(int) $state['for']]);
        }

        return $this->card($admin, $state);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        $state = $admin->state(self::ID);
        if (!isset($state['for'])) {
            return $admin->open(AgencyRequestsScreen::ID);
        }
        if ($action === 'ex') {
            // Read and kept alone: the approval is the card's tap, whose refusal leaves the card as it is.
            $admin->remember(self::ID, ['expires' => AgencyEnd::read($answer->text()), 'picked' => true]);
        }

        return $this->card($admin, $admin->state(self::ID));
    }

    /**
     * The card for request `$id` of the list's kept page, read again (AgencyRequestScreen::fresh(); the kept row when it
     * is not found), its end at first the program's as it is now; a request to become an agent is approved on a level
     * (AgencyApproveScreen), one decided meanwhile shown as it stands.
     *
     * @throws ApiRefusal
     */
    private function start(Admin $admin, int $id): View
    {
        $row = AgencyRequestScreen::row($admin, $id);
        if ($row === null) {
            return $admin->open(AgencyRequestsScreen::ID);
        }
        if (!AgencyRequestScreen::renewal($row)) {
            return $admin->open(AgencyApproveScreen::ID, 'new', [$id]);
        }
        $row = AgencyRequestScreen::fresh($admin, $row) ?? $row;
        if (!Json::on(Json::part($row, 'actions'), 'approve')) {
            return $admin->open(AgencyRequestScreen::ID, 'show', [$id]);
        }
        $term = AgencyEnd::of(is_string($row['term_end'] ?? null) ? $row['term_end'] : null);
        $admin->forget(self::ID);
        $admin->remember(self::ID, [
            'for' => $id,
            'name' => Words::user($row['user'] ?? null),
            'ends' => is_string($row['expires_at'] ?? null) ? $row['expires_at'] : null,
            'term' => $term,
            'expires' => $term,
            'picked' => $term !== null,
        ]);

        return $this->card($admin, $admin->state(self::ID));
    }

    /**
     * The agent's end now, the end picked, and «✔️ تایید و تمدید» once there is one.
     *
     * @param array<string, mixed> $state
     */
    private function card(Admin $admin, array $state): View
    {
        if (!is_array($state['was'] ?? null)) {
            $state['was'] = self::end($state);
            $admin->remember(self::ID, ['was' => $state['was']]);
        }
        $picked = Json::on($state, 'picked');
        $term = self::term($state);
        $expires = self::moment($state);
        $ends = is_string($state['ends'] ?? null) ? $state['ends'] : null;
        $view = View::make('🔄 تمدید نمایندگی ' . (string) ($state['name'] ?? ''), ...[...AgencyScreen::path(), 'درخواست‌ها']);
        if ($term === null) {
            $view->line($ends === null
                ? 'ℹ️ نمایندگی این نماینده تاریخ پایان ندارد؛ درخواست را رد کنید، یا پایان تازه‌ای انتخاب کنید.'
                : 'ℹ️ در تنظیمات نمایندگی مدتی برای نمایندگی تعیین نشده است؛ پایان تازه را خودتان انتخاب کنید.');
        }
        $view->card([
            'پایان فعلی' => AgencyEnd::current($ends),
            'پایان تازه' => $picked ? AgencyEnd::words($expires) . ($term !== null && $expires === $term ? ' · با مدت نمایندگی' : '') : '⚠️ انتخاب نشده',
        ])
            ->note('پیام تمدید، با تاریخ پایان تازه، در ربات برای نماینده فرستاده می‌شود.')
            ->tiles(Button::to(UiWords::edit('پایان تازه'), self::ID, 'ex')->styled($picked ? null : 'primary'));

        return Words::wayOut($view, self::ID, self::edited($state), $picked ? Button::to('✔️ تایید و تمدید', self::ID, 'save')->success() : null);
    }

    /**
     * The one POST, with the end the card shows — the program's as it was read, any other picked, null for none —, so
     * the agent gets that end and no other the API would reckon now. Refused on `status` (decided meanwhile, the
     * agency ended): why, and the request as it stands now (AgencyRequestScreen::fresh(); the list when it is not found).
     *
     * @param array<string, mixed> $state
     * @throws ApiRefusal any other refusal — one on `expires_at` (an end not ahead, earlier than the agent's, past the last
     *                    day kept) says why and opens the card afresh
     */
    private function save(Admin $admin, array $state): View
    {
        if (!Json::on($state, 'picked')) {
            return $this->card($admin, $state);
        }
        $id = (int) $state['for'];
        try {
            $answer = $admin->api->post("/agency/requests/{$id}/approve", ['expires_at' => self::moment($state)]);
        } catch (ApiRefusal $refusal) {
            if ($refusal->status === 422 && $refusal->field('status') === null && $refusal->field('expires_at') !== null) {
                // The end refused as the agent's now stands (moved meanwhile, earlier than it): why, and the card afresh.
                $admin->alert($refusal->lines());

                return $admin->open(self::ID, 'new', [$id]);
            }
            if ($refusal->status !== 422 || $refusal->field('status') === null) {
                throw $refusal;
            }
            $admin->alert($refusal->lines());
            $admin->forget(self::ID);
            $kept = AgencyRequestScreen::row($admin, $id);

            return ($kept === null ? null : AgencyRequestScreen::fresh($admin, $kept)) === null
                ? $admin->open(AgencyRequestsScreen::ID)
                : $admin->open(AgencyRequestScreen::ID, 'show', [$id]);
        }
        $row = (array) ($answer['request'] ?? []);
        AgencyRequestScreen::keep($admin, $row);
        $admin->forget(self::ID);
        $given = is_string($row['extended_to'] ?? null) ? $row['extended_to'] : null;
        $admin->notice('✅ نمایندگی ' . (string) ($state['name'] ?? '') . ($given === null ? ' بدون پایان تمدید شد' : ' تا ' . Fmt::plain(Fmt::date($given, true)) . ' تمدید شد'));

        return $admin->open(AgencyRequestScreen::ID, 'show', [$id]);
    }

    /**
     * The end as a change to it is measured.
     *
     * @param array<string, mixed> $state
     * @return array{picked: bool, expires: string|null}
     */
    private static function end(array $state): array
    {
        return ['picked' => Json::on($state, 'picked'), 'expires' => self::moment($state)];
    }

    /**
     * Whether the end is no longer as the card first showed it: its way out asks first.
     *
     * @param array<string, mixed> $state
     */
    private static function edited(array $state): bool
    {
        return is_array($state['was'] ?? null) && $state['was'] !== self::end($state);
    }

    /** @param array<string, mixed> $state */
    private static function moment(array $state): ?string
    {
        return is_string($state['expires'] ?? null) && $state['expires'] !== '' ? $state['expires'] : null;
    }

    /** @param array<string, mixed> $state */
    private static function term(array $state): ?string
    {
        return is_string($state['term'] ?? null) && $state['term'] !== '' ? $state['term'] : null;
    }
}
