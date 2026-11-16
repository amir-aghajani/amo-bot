<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Support;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words as UiWords;

/**
 * «🎧 پشتیبانی» (the panel's tickets screen): the support tickets by the panel's tabs — those waiting on an answer
 * first —, the latest activity first, searched by number, customer or subject, each a row «chip #number · subject ·
 * customer» opening its own screen (TicketScreen) to read and answer it. One customer's alone, as their page links here
 * (`user`).
 *
 * Actions: `user` (customer id: theirs alone), `all` (every customer's again).
 */
final class TicketsScreen extends ListScreen
{
    public const ID = 'tk';

    public const TITLE = Icons::SUPPORT . ' پشتیبانی';

    /** The panel's status tabs, the queue first («در انتظار پاسخ»), «همه» last. */
    public const STATUSES = ['open' => '🟡 در انتظار پاسخ', 'answered' => '💬 پاسخ‌داده‌شده', 'closed' => '✅ بسته‌شده', self::ANY => 'همه'];

    /** A ticket's state in its row's chip (the panel's TICKET_STATUS). */
    public const MARK = ['open' => '🟡', 'answered' => '💬', 'closed' => '✅'];

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
        return self::TITLE;
    }

    protected function path(): array
    {
        return [UiWords::ROOT];
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

    protected function noun(): string
    {
        return 'تیکت';
    }

    protected function searchHint(): string
    {
        return 'شماره تیکت (مثل #۱۲)، مشتری یا موضوع';
    }

    protected function empty(): string
    {
        return $this->tab() === 'open' && $this->user === 0 ? UiWords::NOTHING_WAITING : 'تیکتی پیدا نشد.';
    }

    protected function summary(array $meta): array
    {
        $open = (int) ($meta['open'] ?? 0);
        $lines = [];
        if ($this->user > 0) {
            $lines[] = Icons::CUSTOMER . ' فقط تیکت‌های مشتری ' . Fmt::id($this->user);
        } elseif ($this->tab() !== 'open' && $open > 0) {
            // Beside another tab, how many wait: the queue's own tab says it in its count.
            $lines[] = '🟡 ' . Fmt::number($open) . ' تیکت در انتظار پاسخ شماست.';
        }

        return $lines;
    }

    protected function extra(View $view, Admin $admin): void
    {
        if ($this->user > 0) {
            $view->row(Button::to('✖️ همه مشتری‌ها', self::ID, 'all'));
        }
        $view->help('تیکت‌هایی که مشتری‌ها از ربات، وب‌سایت یا مینی‌اپ باز کرده‌اند؛ تیکتی که تازه‌ترین پیام را دارد بالاتر است. مشتری از پاسخی که این‌جا می‌دهید خبردار می‌شود.');
    }

    protected function item(array $row): Button
    {
        $customer = is_array($row['customer'] ?? null) ? $row['customer'] : null;
        $status = (string) ($row['status'] ?? '');

        return Button::to(
            (self::MARK[$status] ?? '•') . ' ' . Fmt::id(is_int($row['id'] ?? null) ? $row['id'] : null) . ' · ' . Fmt::cut((string) ($row['subject'] ?? ''), 24) . ($this->user > 0 ? '' : ' · ' . Words::name($customer)),
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

    /** The tab in force. */
    private function tab(): string
    {
        return $this->filterValue($this->here?->state(self::ID) ?? [], 'status');
    }
}
