<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Support;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * A row of a list the panel has no one-row route for (the referral program's lists): read from the page its list kept
 * (ListScreen::keepsRows()), shown on its own; gone from the page — the list moved on —, the list instead.
 *
 * Shown with (id).
 */
abstract class KeptRowScreen extends BaseScreen
{
    /** The list whose kept page the row is read from. */
    abstract protected function list(): string;

    /**
     * The row's screen.
     *
     * @param array<string, mixed> $row
     */
    abstract protected function view(Admin $admin, array $row): View;

    public function show(Admin $admin, array $args): View
    {
        $row = self::kept($admin, $this->list(), self::id($args));

        return $row === null ? $admin->open($this->list()) : $this->view($admin, $row);
    }

    /**
     * Row `$id` of `$list`'s kept page; null when it is not there.
     *
     * @return array<string, mixed>|null
     */
    protected static function kept(Admin $admin, string $list, int $id): ?array
    {
        $rows = (array) ($admin->state($list)['rows'] ?? []);
        $row = $rows[$id] ?? $rows[(string) $id] ?? null;

        return is_array($row) ? $row : null;
    }
}
