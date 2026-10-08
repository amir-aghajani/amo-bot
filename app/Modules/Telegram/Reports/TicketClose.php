<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports;

use App\Core\Exceptions\ValidationException;
use App\Modules\Auth\Actor;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Services\Tickets;
use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Keyboard\InlineKeyboard;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Telegram\Update\GroupHandler;
use App\Modules\Telegram\Update\Update;

/**
 * «🔒 بستن تیکت» under a ticket's last report in the tickets topic: the ticket closed — by the bot's admins only
 * (GroupButtons::admin()), as a panel closes it (Tickets::close(): the customer told, the group's report of it, which
 * takes the button off). A ticket closed already, or gone, loses the button with a word.
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
        private readonly BotApi $api,
        private readonly GroupButtons $groupButtons,
        private readonly Tickets $tickets,
    ) {}

    /** @return list<list<array<string, mixed>>> The one button under a ticket's report. */
    public static function buttons(int $ticketId): array
    {
        return InlineKeyboard::make()->row(InlineKeyboard::callback(self::LABEL, CallbackData::build(self::PREFIX, $ticketId), 'danger'))->build()['inline_keyboard'];
    }

    /** A press on the button, from a group. */
    public function handle(Update $update): void
    {
        $callbackId = (string) $update->callbackId();
        $args = $update->callbackArgs(self::PREFIX) ?? [];
        if (count($args) !== 1 || !ctype_digit($args[0])) {
            $this->api->answerCallbackQuery($callbackId);

            return;
        }

        $admin = GroupButtons::admin($update->from());
        if ($admin === null) {
            $this->api->answerCallbackQuery($callbackId, self::NOT_ADMIN, alert: true);

            return;
        }

        $chatId = (int) $update->chatId();
        $messageId = (int) $update->messageId();
        $ticket = Ticket::query()->with(['user', 'subscription'])->find((int) $args[0]);
        if ($ticket === null) {
            $this->groupButtons->set($chatId, $messageId, null);
            $this->api->answerCallbackQuery($callbackId, self::NOT_FOUND, alert: true);

            return;
        }

        try {
            $this->tickets->close($ticket, Actor::groupAdmin($admin));
        } catch (ValidationException $e) {
            // Closed meanwhile — here, on a panel, by the customer: nothing to close.
            $this->groupButtons->set($chatId, $messageId, null);
            $this->api->answerCallbackQuery($callbackId, $e->getMessage(), alert: true);

            return;
        }

        $this->groupButtons->set($chatId, $messageId, null);
        $this->api->answerCallbackQuery($callbackId, self::CLOSED);
    }
}
