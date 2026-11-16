<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Support;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * A row of a list the panel has no one-row route for (a review, the referral program's lists): read from the page its
 * list kept (ListScreen::keepsRows()), shown on its own; gone from the page — the list moved on —, the list instead,
 * saying so.
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
        $row = ListScreen::keptRow($admin, $this->list(), self::id($args));
        if ($row === null) {
            $admin->notice('این ردیف در صفحه فعلی فهرست نیست؛ فهرست تازه را ببینید.');

            return $admin->open($this->list());
        }

        return $this->view($admin, $row);
    }
}
