<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Broadcasts;

use App\Modules\Bots\CurrentBot;
use App\Modules\Telegram\Admin\Sections\Bot\BotScreens;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;
use App\Modules\Telegram\Handlers\BroadcastHandler;
use App\Modules\Telegram\Messages;

/**
 * «📣 ارسال همگانی» (the daily work: every seat): every broadcast run, newest first — a button each, the row: its state
 * as a chip (🟢 sending, ⏸ paused, ✅ done, 🔴 cancelled), its number, what went and how far it got —, each opening its
 * own screen (BroadcastScreen) with the controls its state allows; and «➕ پیام همگانی تازه», which starts the bot's own
 * compose flow (BroadcastHandler: the message sent here — its own preview —, then its draft card: audience, copy or
 * forward, pin, buttons, and «ارسال» asked once more; this window then the way back here) — in the bot of the shop on
 * screen alone, as a broadcast goes out through its own bot. The panel's list route alone: the page's rows are kept for
 * the run's screen.
 *
 * Actions besides the list's: `new` (waits for the message).
 */
final class BroadcastsScreen extends ListScreen
{
    public const ID = 'br';

    /** Each state's mark in a row (the panel's BROADCAST_STATUS). */
    public const MARKS = ['sending' => '🟢', 'paused' => '⏸', 'done' => '✅', 'cancelled' => '🔴'];

    public const TITLE = Icons::BROADCAST . ' ارسال همگانی';

    public function __construct(private readonly BroadcastHandler $composer) {}

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
        return BotScreens::PATH;
    }

    protected function parent(): string
    {
        return BotScreens::MENU;
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

    protected function noun(): string
    {
        return 'ارسال';
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
        if (self::here($admin)) {
            $view->row(Button::to('➕ پیام همگانی تازه', self::ID, 'new')->success());
        } else {
            $view->line()->line('<i>پیام همگانی تازه را از ربات همین فروشگاه بفرستید: هر ارسال از ربات خود فروشگاه می‌رود.</i>');
        }
        // The main bot's shop also has its agents and its servers' customers among its audiences; an agent's bot neither.
        $view->help(($admin->seat->shop()->isMain()
            ? 'پیام به گروهی از مشتری‌ها: همه، خریداران، غیرفعال‌ها، یک گروه، نماینده‌ها یا مشتری‌های یک سرور.'
            : 'پیام به گروهی از مشتری‌های ربات: همه، خریداران، غیرفعال‌ها یا یک گروه.')
            . ' پیشرفت هر ارسال هم در ربات و هم همین‌جا دیده می‌شود و از هر دو جا می‌شود متوقف، ادامه یا لغوش کرد. '
            . self::MARKS['sending'] . ' در حال ارسال · ' . self::MARKS['paused'] . ' متوقف‌شده · ' . self::MARKS['done'] . ' انجام‌شده · ' . self::MARKS['cancelled'] . ' لغوشده');
    }

    protected function action(Admin $admin, string $action, array $args): View
    {
        if ($action !== 'new' || !self::here($admin)) {
            return $this->show($admin, []);
        }
        $this->composer->compose($admin->ctx, $admin->ctx->update->messageId(), Nav::to(self::ID));

        return View::make(Icons::BROADCAST . ' پیام همگانی تازه', ...[...BotScreens::PATH, Fmt::bare(self::TITLE)])
            ->line(Messages::BROADCAST_ASK)
            ->section('بعد از فرستادن پیام')
            ->lines(
                '۱. پیش‌نویس آن زیر همین پیام می‌آید: خود پیامتان پیش‌نمایش همان چیزی است که مشتری می‌گیرد.',
                '۲. مخاطب، کپی یا فوروارد، پین و دکمه‌های زیر پیام را همان‌جا انتخاب می‌کنید.',
                '۳. «ارسال» یک بار دیگر می‌پرسد: به چند نفر و چه چیزی؛ با «بله» شروع می‌شود.',
            )
            ->row(Button::to(Words::CANCEL, self::ID));
    }

    /** Whether the shop on screen is the bot's this chat is in: a broadcast goes out through its own bot. */
    private static function here(Admin $admin): bool
    {
        return $admin->seat->shop()->id === CurrentBot::get()->id;
    }
}
