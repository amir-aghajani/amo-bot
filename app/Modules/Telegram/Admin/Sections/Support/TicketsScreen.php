<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Support;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «🎧 پشتیبانی» (the panel's tickets screen): the support tickets, the latest activity first — those waiting on an answer
 * first —, searched by number, customer or subject, each opening its own screen (TicketScreen) to read and answer it.
 * One customer's alone, as their page links here (`user`).
 *
 * Actions: `user` (customer id: theirs alone), `all` (every customer's again).
 */
final class TicketsScreen extends ListScreen
{
    public const ID = 'tk';

    /** The panel's status tabs, the queue first («در انتظار پاسخ»), «همه» last. */
    public const STATUSES = ['open' => '⏳ در انتظار پاسخ', 'answered' => '💬 پاسخ‌داده‌شده', 'closed' => '🔒 بسته‌شده', self::ANY => 'همه'];

    /** A ticket's state in its row's mark (the panel's TICKET_STATUS). */
    public const MARK = ['open' => '⏳', 'answered' => '💬', 'closed' => '🔒'];

    /** The customer the list is narrowed to (its state's `user`) while it is shown; 0 for none. */
    private int $user = 0;

    public function show(Admin $admin, array $args): View
    {
        $this->user = (int) ($admin->state(self::ID)['user'] ?? 0);

        return parent::show($admin, $args);
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '🎧 پشتیبانی';
    }

    protected function path(): array
    {
        return ['مدیریت'];
    }

    protected function endpoint(): string
    {
        return '/tickets';
    }

    protected function key(): string
    {
        return 'tickets';
    }

    protected function filters(): array
    {
        return ['status' => self::STATUSES];
    }

    protected function fixed(): array
    {
        return $this->user > 0 ? ['user' => $this->user] : [];
    }

    protected function searchHint(): string
    {
        return 'شماره تیکت، مشتری یا موضوع';
    }

    protected function empty(): string
    {
        return 'تیکتی پیدا نشد.';
    }

    protected function summary(array $meta): array
    {
        $open = (int) ($meta['open'] ?? 0);
        $lines = ['تیکت‌هایی که مشتری‌ها برای پشتیبانی باز کرده‌اند؛ تیکتی که تازه‌ترین پیام را دارد بالاتر است. مشتری از پاسخی که این‌جا می‌دهید خبردار می‌شود.'];
        if ($open > 0) {
            $lines[] = '⏳ ' . Fmt::number($open) . ' تیکت در انتظار پاسخ شماست.';
        }
        if ($this->user > 0) {
            $lines[] = '👤 فقط تیکت‌های مشتری ' . Fmt::id($this->user);
        }

        return $lines;
    }

    protected function extra(View $view, Admin $admin): void
    {
        if ($this->user > 0) {
            $view->row(Button::to('✖️ همه مشتری‌ها', self::ID, 'all'));
        }
    }

    protected function item(array $row): Button
    {
        $customer = is_array($row['customer'] ?? null) ? $row['customer'] : null;
        $status = (string) ($row['status'] ?? '');

        return Button::to(
            (self::MARK[$status] ?? '') . ' ' . Fmt::id(is_int($row['id'] ?? null) ? $row['id'] : null) . ' · ' . Fmt::cut((string) ($row['subject'] ?? ''), 24) . ' · ' . Words::name($customer),
            TicketScreen::ID,
            Nav::SHOW,
            (int) ($row['id'] ?? 0),
        );
    }

    protected function action(Admin $admin, string $action, array $args): View
    {
        if ($action === 'user' && self::id($args) > 0) {
            $admin->remember(self::ID, ['user' => self::id($args), 'f' => ['status' => self::ANY], 'search' => '', 'page' => 1]);
        }
        if ($action === 'all') {
            $admin->remember(self::ID, ['user' => 0, 'page' => 1]);
        }

        return $this->show($admin, []);
    }
}
