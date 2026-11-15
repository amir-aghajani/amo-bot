<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Broadcasts;

use App\Modules\Bots\CurrentBot;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Handlers\BroadcastHandler;
use App\Modules\Telegram\Messages;

/**
 * «📣 ارسال همگانی» (the daily work: every seat): every broadcast run, newest first — what went, how far it got, its
 * state —, each opening its own screen (BroadcastScreen) with the controls its state allows; and «➕ پیام همگانی تازه»,
 * which starts the bot's own compose flow (BroadcastHandler: the message sent here, then its draft card, this window then
 * the way back here) — in the bot of the shop on screen alone, as a broadcast goes out through its own bot. The panel's
 * list route alone: the page's rows are kept for the run's screen.
 *
 * Actions besides the list's: `new` (waits for the message).
 */
final class BroadcastsScreen extends ListScreen
{
    public const ID = 'br';

    /** Each state's mark in a row (the panel's BROADCAST_STATUS). */
    public const MARKS = ['sending' => '📤', 'paused' => '⏸', 'done' => '✅', 'cancelled' => '🚫'];

    public function __construct(private readonly BroadcastHandler $composer) {}

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '📣 ارسال همگانی';
    }

    protected function path(): array
    {
        return ['مدیریت', 'ربات'];
    }

    protected function parent(): string
    {
        return 'hb';
    }

    protected function endpoint(): string
    {
        return '/broadcasts';
    }

    protected function key(): string
    {
        return 'broadcasts';
    }

    protected function searchable(): bool
    {
        return false;
    }

    protected function keepsRows(): bool
    {
        return true;
    }

    protected function summary(array $meta): array
    {
        // The main bot's shop also has its agents and its servers' customers among its audiences; an agent's bot neither.
        return [($this->here?->seat->shop()->isMain() ?? true)
            ? 'پیام به گروهی از مشتری‌ها — همه، خریداران، غیرفعال‌ها، یک گروه، نماینده‌ها یا مشتری‌های یک سرور.'
            : 'پیام به گروهی از مشتری‌های ربات — همه، خریداران، غیرفعال‌ها یا یک گروه.'];
    }

    protected function empty(): string
    {
        return 'هنوز ارسالی نشده است. هر ارسال با پیشرفتش این‌جا می‌آید.';
    }

    protected function item(array $row): Button
    {
        $status = self::MARKS[(string) ($row['status'] ?? '')] ?? '•';
        $reached = min((int) ($row['sent'] ?? 0) + (int) ($row['blocked'] ?? 0) + (int) ($row['failed'] ?? 0), (int) ($row['total'] ?? 0));

        return Button::to($status . ' ' . Fmt::plain(Fmt::id((int) ($row['id'] ?? 0))) . ' · ' . BroadcastScreen::what($row, 22) . ' · ' . Fmt::plain(Fmt::number($reached) . '/' . Fmt::number((int) ($row['total'] ?? 0))), BroadcastScreen::ID, 'show', (int) ($row['id'] ?? 0));
    }

    protected function extra(View $view, Admin $admin): void
    {
        $view->line()->line('<i>پیشرفت هر ارسال هم در ربات و هم همین‌جا دیده می‌شود و از هر دو جا می‌شود متوقف، ادامه یا لغوش کرد.</i>');
        if (self::here($admin)) {
            $view->row(Button::to('➕ پیام همگانی تازه', self::ID, 'new'));
        } else {
            $view->line('<i>پیام همگانی تازه را از ربات همین فروشگاه بفرستید: هر ارسال از ربات خود فروشگاه می‌رود.</i>');
        }
        $view->row(Button::to('🔄 تازه‌سازی', self::ID));
    }

    protected function action(Admin $admin, string $action, array $args): View
    {
        if ($action !== 'new' || !self::here($admin)) {
            return $this->show($admin, []);
        }
        $this->composer->compose($admin->ctx, $admin->ctx->update->messageId(), Nav::to(self::ID));

        return View::make('📣 پیام همگانی تازه', 'مدیریت', 'ربات', 'ارسال همگانی')
            ->line(Messages::BROADCAST_ASK)
            ->note('بعد از فرستادن پیام، پیش‌نویس آن زیر همین پیام می‌آید: مخاطب، کپی یا فوروارد، پین و دکمه‌ها را همان‌جا انتخاب و ارسال می‌کنید.')
            ->row(Button::to('✖️ انصراف', self::ID));
    }

    /** Whether the shop on screen is the bot's this chat is in: a broadcast goes out through its own bot. */
    private static function here(Admin $admin): bool
    {
        return $admin->seat->shop()->id === CurrentBot::get()->id;
    }
}
