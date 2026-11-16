<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Ui;

use App\Modules\Telegram\Admin\AdminSeat;

/**
 * A menu of management screens on the home (the panel's sidebar groups): a line of what is in it, and its entries two
 * a row, each a screen's id and label — with the count of the queue it holds beside it (a badge) —, the ones the seat
 * may not open, or not built, left out. Registered in Screens.
 */
final class HubScreen extends BaseScreen
{
    /**
     * @param list<string> $path
     * @param list<array{0: string, 1: string, 2?: string}> $entries [screen id, label, queue (Admin\Services\Queues)]
     * @param string $about What is in it, one line (plain text)
     */
    public function __construct(
        private readonly Screens $screens,
        private readonly string $title,
        private readonly array $path,
        private readonly array $entries,
        private readonly string $about = '',
    ) {}

    public function allows(AdminSeat $seat): bool
    {
        return $this->screens->buttons($seat, $this->entries) !== [];
    }

    public function show(Admin $admin, array $args): View
    {
        $view = View::make($this->title, ...$this->path);
        if ($this->about !== '') {
            $view->line(Fmt::e($this->about));
        }

        return $view
            ->grid(2, ...$this->screens->buttons($admin->seat, $this->entries, $this->screens->queues($admin, $this->entries)))
            ->back(Screens::HOME);
    }
}
