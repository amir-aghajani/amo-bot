<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Imports;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Panel\Access;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «آنچه نیامد» of an import's report, a page at a time (GET /imports/{id}/problems), narrowed to a reason — those only
 * counted (the owner's choice, nothing to act on) have no rows —: which thing, its key in the other bot, why, and what
 * finds it.
 *
 * Actions: `r` (id, n: reason n, 0 for every one), `pg` (id, page).
 */
final class ImportProblemsScreen extends BaseScreen
{
    public const ID = 'ppiq';

    /** Rows a page here. */
    private const PER_PAGE = 10;

    public function allows(AdminSeat $seat): bool
    {
        return Access::main($seat);
    }

    public function show(Admin $admin, array $args): View
    {
        $id = self::id($args);
        $import = Words::import($admin, $id);
        $problems = Json::part($import, 'problems');
        $listed = array_values(array_filter(
            Json::part($problems, 'by_reason'),
            static fn(mixed $reason): bool => is_array($reason) && !in_array($reason['reason'] ?? '', Words::COUNTED, true),
        ));
        $state = $admin->state(self::ID);
        if (($state['import'] ?? null) !== $id) {
            $state = ['import' => $id, 'reason' => '', 'page' => 1];
            $admin->remember(self::ID, $state);
        }
        $reason = is_string($state['reason'] ?? null) ? $state['reason'] : '';
        $page = max(1, (int) ($state['page'] ?? 1));
        $start = ($page - 1) * self::PER_PAGE;
        $answer = $admin->api->get("/imports/{$id}/problems", ['reason' => $reason, 'page' => intdiv($start, 50) + 1]);
        $perApi = max(1, (int) ($answer['per_page'] ?? 50));
        if ($perApi !== 50) {
            $answer = $admin->api->get("/imports/{$id}/problems", ['reason' => $reason, 'page' => intdiv($start, $perApi) + 1]);
        }
        $rows = array_slice(array_values(array_filter(Json::part($answer, 'problems'), is_array(...))), $start % $perApi, self::PER_PAGE);
        $total = (int) ($answer['total'] ?? 0);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));

        $view = View::make('📋 آنچه نیامد', 'مدیریت', 'انتقال از ربات دیگر', 'گزارش')
            ->line(Fmt::number($total) . ' مورد' . ($pages > 1 ? ' · صفحه ' . Fmt::number($page) . ' از ' . Fmt::number($pages) : ''));
        if ($rows === []) {
            $view->line()->line('موردی نیست.');
        }
        foreach ($rows as $row) {
            $view->line()->line('• <b>' . Fmt::e(Words::thing((string) ($row['kind'] ?? ''))) . '</b> ' . Fmt::ltr((string) ($row['source_key'] ?? '')));
            if ($reason === '') {
                $view->line('<i>' . Fmt::e((string) ($row['label'] ?? '')) . '</i>');
            }
            if (is_string($row['detail'] ?? null) && $row['detail'] !== '') {
                $view->line(Fmt::e(Fmt::cut($row['detail'], 200)));
            }
        }

        $chips = [Button::to(($reason === '' ? '● ' : '') . 'همه', self::ID, 'r', $id, 0)];
        foreach ($listed as $n => $each) {
            $chips[] = Button::to((($each['reason'] ?? '') === $reason ? '● ' : '') . (string) ($each['label'] ?? '') . ' (' . Fmt::number((int) ($each['count'] ?? 0)) . ')', self::ID, 'r', $id, $n + 1);
        }
        $view->grid(1, ...$chips);
        $view->pager($page, $pages, self::ID, 'pg', $id);

        return $view->back(ImportReportScreen::ID, 'show', $id);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        if ($action === 'r') {
            $import = Words::import($admin, $id);
            $problems = Json::part($import, 'problems');
            $listed = array_values(array_filter(
                Json::part($problems, 'by_reason'),
                static fn(mixed $reason): bool => is_array($reason) && !in_array($reason['reason'] ?? '', Words::COUNTED, true),
            ));
            $n = self::id($args, 1);
            $picked = $n === 0 ? '' : (string) (((array) ($listed[$n - 1] ?? []))['reason'] ?? '');
            $admin->remember(self::ID, ['import' => $id, 'reason' => $picked, 'page' => 1]);
        } elseif ($action === 'pg') {
            $admin->remember(self::ID, ['import' => $id, 'page' => self::page($args, 1)]);
        }

        return $this->show($admin, [(string) $id]);
    }
}
