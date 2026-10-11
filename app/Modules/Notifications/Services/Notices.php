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
 * Delivers every notice CustomerNotifier composes: kept for the customer's website feed (`notifications`, KEEP_DAYS),
 * then sent to their Telegram chat (CustomerChats) or — no Telegram account, or one that turned the bot away — to their
 * email when they have one and the shop's email works (Mailer). The same words everywhere: the bot text as the bot sends
 * it, rendered for the web and email by WebText.
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
     * Keep the notice for the customer's website first, whatever comes of the rest, then send it: to their Telegram chat
     * (`$telegram` sends it given the chat id), or — no Telegram account, or they turned the bot away (known, or learned
     * as it was sent) — to their email. Returns the delivery: Emailed or Unreachable for an email (the Mailer logged
     * why), NoTelegram or TurnedAway when no channel was open, else what the chat said. With `$everyDoor` (a change of how
     * the account is signed in to) the email gets it too beside the chat. Runs in the customer's shop, as
     * CustomerNotifier calls it: its bot speaks and its name signs the email.
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

        if ($customer->telegram_id === null && $this->emailable($customer)) {
            return $this->email($customer, $type, $text);
        }

        $delivery = $this->chats->send($customer, $telegram, $type->value . ($kind === null ? '' : " ({$kind->value} #{$subject?->id})"));
        if ($delivery === Delivery::TurnedAway && $this->emailable($customer)) {
            // They turned the bot away (known, or learned now): their email is the channel left.
            return $this->email($customer, $type, $text);
        }
        if ($everyDoor && $customer->telegram_id !== null && $this->emailable($customer)) {
            $this->email($customer, $type, $text);
        }

        return $delivery;
    }

    /** Delete every shop's notices older than KEEP_DAYS: the website's feed goes back that far, no further. */
    public function prune(): void
    {
        $before = now()->subDays(self::KEEP_DAYS);
        CurrentBot::everywhere(static fn() => Notification::query()->where('created_at', '<', $before)->delete());
    }

    /** Whether the customer can be emailed: they have an address and the shop's email works. */
    private function emailable(User $customer): bool
    {
        return $customer->email !== null && $this->mailer->ready();
    }

    /** Email the notice to the customer's address, signed with their shop's name. */
    private function email(User $customer, NoticeType $type, string $text): Delivery
    {
        try {
            $this->mailer->send(NoticeMail::message((string) $customer->email, $this->bots->name($customer->shop()), $type, $text));
        } catch (MailFailedException) {
            // The mail server did not take it (the Mailer logged why): lost to email, still kept on the website.
            return Delivery::Unreachable;
        }

        return Delivery::Emailed;
    }
}
