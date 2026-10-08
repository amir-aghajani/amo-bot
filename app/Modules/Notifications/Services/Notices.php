<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Core\Mail\Mailer;
use App\Core\Mail\MailFailedException;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Services\Bots;
use App\Modules\Notifications\Enums\Delivery;
use App\Modules\Notifications\Enums\NoticeSubject;
use App\Modules\Notifications\Enums\NoticeType;
use App\Modules\Notifications\Mail\NoticeMail;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Orders\Models\Order;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Support\Models\Ticket;
use App\Modules\Users\Models\User;

/**
 * Every notice the shop sends a customer (CustomerNotifier words them) reaches them on every door they have: it is kept
 * for their website — the feed they read it in, `notifications`, for KEEP_DAYS —, and it goes to their Telegram chat
 * when the bot knows their account (CustomerChats), else — no Telegram account, or one that turned the bot away — to
 * their email when they have an address and the shop's email goes out (Mailer). The same words everywhere — the bot
 * text's, as the bot sends them —, never a second copy of a notice's words.
 */
final class Notices
{
    /** How long a notice stays in the website's feed. */
    public const KEEP_DAYS = 180;

    public function __construct(
        private readonly CustomerChats $chats,
        private readonly Mailer $mailer,
        private readonly Bots $bots,
    ) {}

    /**
     * The notice kept for the customer's website first — whatever comes of the rest —, then sent: to their Telegram chat
     * (`$telegram` sends it there, given the chat's id: CustomerChats::send()), or — they have no Telegram account, or
     * they turned the bot away (known, or learned as it was sent) — to their email. What came of it is the answer:
     * Delivery::Emailed for an email the mail server took, Unreachable for one it did not (the Mailer logged why),
     * NoTelegram and TurnedAway when no door was open. Worked in the customer's shop (CurrentBot), as CustomerNotifier runs
     * every notice: its bot speaks, its name signs the email, the notice is its.
     * With `$everyDoor` — a change of how their account is signed in to, which whoever made it may hold one door of —
     * their email gets it too beside their Telegram chat (what came of the chat is the answer).
     *
     * @param string $text The notice as the bot words it — Telegram HTML; the caption, when it goes as a QR card
     * @param Order|Subscription|Ticket|null $subject What it is about — the customer's own order, service or ticket, what their website links it to —; null for none
     * @param \Closure(int): mixed $telegram
     */
    public function deliver(User $customer, NoticeType $type, string $text, Order|Subscription|Ticket|null $subject, \Closure $telegram, bool $everyDoor = false): Delivery
    {
        $kind = match (true) {
            $subject instanceof Order => NoticeSubject::Order,
            $subject instanceof Subscription => NoticeSubject::Subscription,
            $subject instanceof Ticket => NoticeSubject::Ticket,
            default => null,
        };
        Notification::query()->create([
            'user_id' => $customer->id,
            'type' => $type,
            'text' => $text,
            'subject_type' => $kind,
            'subject_id' => $subject?->id,
        ]);

        if ($customer->telegram_id === null && $customer->email !== null && $this->mailer->ready()) {
            return $this->email($customer, $customer->email, $type, $text);
        }

        $delivery = $this->chats->send($customer, $telegram, $type->value . ($kind === null ? '' : " ({$kind->value} #{$subject?->id})"));
        if ($delivery === Delivery::TurnedAway && $customer->email !== null && $this->mailer->ready()) {
            // They turned the bot away — known, or learned now —: their email is the door left.
            return $this->email($customer, $customer->email, $type, $text);
        }
        if ($everyDoor && $customer->telegram_id !== null && $customer->email !== null && $this->mailer->ready()) {
            $this->email($customer, $customer->email, $type, $text);
        }

        return $delivery;
    }

    /** Every shop's notices older than KEEP_DAYS go: the website's feed goes back that far, and no further. */
    public function prune(): void
    {
        $before = now()->subDays(self::KEEP_DAYS);
        CurrentBot::everywhere(static fn() => Notification::query()->where('created_at', '<', $before)->delete());
    }

    /** The notice emailed to the customer's address, from their shop — what it is about its subject, its words the bot's. */
    private function email(User $customer, string $address, NoticeType $type, string $text): Delivery
    {
        try {
            $this->mailer->send(NoticeMail::message($address, $this->bots->name($customer->shop()), $type, $text));
        } catch (MailFailedException) {
            // The mail server did not take it (the Mailer logged why, once): this notice is lost to the email, kept on the website.
            return Delivery::Unreachable;
        }

        return Delivery::Emailed;
    }
}
