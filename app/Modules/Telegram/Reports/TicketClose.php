<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports;

use App\Core\Exceptions\ValidationException;
use App\Modules\Auth\Actor;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Services\Tickets;
use App\Modules\Telegram\Keyboard\InlineKeyboard;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Telegram\Update\GroupHandler;
use App\Modules\Telegram\Update\Update;

/**
 * «🔒 بستن تیکت» under a ticket's last report, for bot admins only: closes it as a panel does (Tickets::close()). A
 * ticket already closed, or gone, loses the button with a word.
 */
final class TicketClose implements GroupHandler
{
    /** What the button's callback data starts with: `tk:<ticket id>`. */
    public const PREFIX = 'tk:';

    private const LABEL = '🔒 بستن تیکت';
    private const NOT_ADMIN = 'فقط مدیرهای ربات می‌توانند تیکت را ببندند؛ نقش «مدیر ربات» در صفحه کاربران پنل داده می‌شود.';
    private const NOT_FOUND = 'این تیکت پیدا نشد.';
    private const CLOSED = '🔒 تیکت بسته شد.';

    public function __construct(
        private readonly GroupButtons $groupButtons,
        private readonly Tickets $tickets,
    ) {}

    /** @return list<list<array<string, mixed>>> The one button under a ticket's report. */
    public static function buttons(int $ticketId): array
    {
        return InlineKeyboard::make()->row(InlineKeyboard::callback(self::LABEL, CallbackData::build(self::PREFIX, $ticketId), 'danger'))->build()['inline_keyboard'];
    }

    /** A press on the button. */
    public function handle(Update $update): void
    {
        $args = $update->callbackArgs(self::PREFIX) ?? [];
        if (count($args) !== 1 || !ctype_digit($args[0])) {
            $this->groupButtons->answer($update);

            return;
        }

        $admin = $this->groupButtons->presser($update, self::NOT_ADMIN);
        if ($admin === null) {
            return;
        }

        $ticket = Ticket::query()->with(['user', 'subscription'])->find((int) $args[0]);
        if ($ticket === null) {
            $this->groupButtons->dismiss($update, self::NOT_FOUND);

            return;
        }

        try {
            $this->tickets->close($ticket, Actor::groupAdmin($admin));
        } catch (ValidationException $e) {
            // Closed meanwhile (here, on a panel, or by the customer): nothing to close.
            $this->groupButtons->dismiss($update, $e->getMessage());

            return;
        }

        $this->groupButtons->buttons($update, null);
        $this->groupButtons->answer($update, self::CLOSED);
    }
}
