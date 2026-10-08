<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Core\Database\Transitions;
use App\Modules\Bots\CurrentBot;
use App\Modules\Notifications\Enums\Delivery;
use App\Modules\Notifications\Enums\NoticeType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\GatewayRegistry;
use App\Modules\Payments\Models\Payment;
use App\Modules\Referrals\Models\ReferralCommission;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketMessage;
use App\Modules\Support\Services\TicketAttachments;
use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Api\Limits;
use App\Modules\Telegram\Handlers\PurchaseHandler;
use App\Modules\Telegram\Handlers\ReceiptHandler;
use App\Modules\Telegram\Handlers\SubscriptionHandler;
use App\Modules\Telegram\Handlers\TicketHandler;
use App\Modules\Telegram\Handlers\TicketMessageHandler;
use App\Modules\Telegram\Keyboard\Buttons;
use App\Modules\Telegram\Keyboard\InlineKeyboard;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Notifications\ServiceCard;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Texts\Html;
use App\Modules\Telegram\Texts\TelegramHtml;
use App\Modules\Users\Models\User;
use App\Support\Money;

/**
 * What the shop tells a customer on its own, outside a conversation, once something was decided: a payment settled,
 * rejected, cancelled, refunded or still waiting; a service switched off, back on, moved (with the new link), deleted,
 * given days and traffic; «تمدید خودکار» done or short of money; «یادآوری»: a service ending soon, its traffic running
 * low; a referral's arrival and its commission; «نمایندگی» approved, rejected, changed, ended, or short of traffic; how
 * their account on the website is signed in to changed — a way in added or taken away, a password set, two-factor
 * sign-in on or off (by them, or by support), another account of theirs made one with it, its second step failed until
 * it waits, the codes emailed to its address failed until none goes for a while —, told on every door it has
 * (tellAccount()); support's answer to their ticket, and its closing.
 *
 * Each notice is composed in the shop of the bot the customer talks to — its wording, its token — whoever decided (a
 * panel, a task going through every server's services): its words first (the admin's, BotTexts), then how Telegram is
 * sent them; Notices keeps it for their website and sends it on every door they have — their Telegram chat (none to a
 * customer who turned the bot away), else their email. A message about a payment is a bare reply to the receipt it is
 * about (a plain message without one); none has buttons, the customer is not being asked anything — but for the payment
 * reminder (send the receipt, or pay again), the short wallet (top it up), the service reminders (open the service) and
 * a ticket's (answer support, rate the ticket, open it — each as a message of its own, the notice left as it is). The
 * service itself is sent by Telegram\Notifications\ServiceCard, its caption what the website keeps.
 */
final class CustomerNotifier
{
    /** A margin kept under Telegram's limit, beside what it counts (Limits counts as it does: an emoji two units). */
    private const MESSAGE_ROOM = 96;

    public function __construct(
        private readonly BotApi $api,
        private readonly ServiceCard $card,
        private readonly Notices $notices,
        private readonly BotTexts $texts,
        private readonly Buttons $buttons,
        private readonly TicketAttachments $attachments,
        private readonly GatewayRegistry $gateways,
    ) {}

    /**
     * A payment was settled after the fact: what came of it — the service or the charged wallet, or that delivery failed
     * and support will finish it — and, when it earned their referrer a commission, the referrer hears it too.
     */
    public function paymentSettled(Payment $payment): void
    {
        $order = $payment->order;
        if ($order->status === OrderStatus::Fulfilled) {
            $this->tell($payment, NoticeType::PaymentSettled, function () use ($payment, $order): array {
                $text = $this->card->settledText($order);

                return [$text, fn(int $chat): array => $this->card->sendSettled($chat, $order, self::replyingToReceipt($payment), $text)];
            });
        } elseif ($order->status === OrderStatus::Failed) {
            $this->tell($payment, NoticeType::PaymentSettled, fn(): array => $this->message($this->card->failedText($order), self::replyingToReceipt($payment)));
        }

        CurrentBot::run($payment->shop(), fn() => $this->commissionEarned($payment));
    }

    /** Support refused the receipt: why (the payment's note) — the order stays open, the menu is the way back. */
    public function paymentRejected(Payment $payment): void
    {
        $this->tell($payment, NoticeType::PaymentRejected, fn(): array => $this->message($this->texts->render(BotText::ReceiptRejected, [
            'order' => $payment->order_id,
            'note' => $this->adminNote($payment->note),
        ]), self::replyingToReceipt($payment)));
    }

    /** Support dropped the order, with the note they left on the payment, if any. */
    public function orderCancelled(Payment $payment): void
    {
        $this->tell($payment, NoticeType::OrderCancelled, fn(): array => $this->message($this->texts->render(BotText::OrderCancelledByAdmin, [
            'order' => $payment->order_id,
            'note' => $this->adminNote($payment->note),
        ]), self::replyingToReceipt($payment)));
    }

    /**
     * The payment was refunded, with support's note: a purchase's money came back as balance; a wallet top-up's charge
     * went out of the wallet again — or, never delivered, left it as it was.
     */
    public function paymentRefunded(Payment $payment): void
    {
        $order = $payment->order;
        [$text, $type] = match (true) {
            $order->type !== OrderType::WalletTopUp => [BotText::PaymentRefunded, NoticeType::PaymentRefunded],
            // Its charge never landed (the delivery failed, or never ran): nothing went out of the wallet.
            $order->fulfilled_at === null => [BotText::TopupRefundedUndelivered, NoticeType::TopUpRefunded],
            default => [BotText::TopupRefunded, NoticeType::TopUpRefunded],
        };

        $this->tell($payment, $type, fn(): array => $this->message($this->texts->render($text, [
            'amount' => Money::format($payment->amount),
            'order' => $payment->order_id,
            'note' => $this->adminNote($payment->note),
        ] + ($text === BotText::TopupRefundedUndelivered ? [] : ['balance' => Messages::balance($order->user->balance())])), self::replyingToReceipt($payment)));
    }

    /**
     * Support nudges a customer whose payment never completed: one still waiting for its receipt gets "send it" and
     * "pay again", a rejected one only "pay again" — its receipt was refused. Telling them is all it does: whether it
     * did, the panel says.
     */
    public function paymentReminder(Payment $payment): Delivery
    {
        return $this->tell($payment, NoticeType::PaymentReminder, function () use ($payment): array {
            $awaitingReceipt = $payment->status === PaymentStatus::Pending && $this->gateways->isManual($payment->method);
            $keyboard = InlineKeyboard::make();
            if ($awaitingReceipt) {
                $keyboard->row(InlineKeyboard::callback($this->texts->get(BotText::SendReceipt), ReceiptHandler::stateFor($payment)));
            }
            $keyboard->row(InlineKeyboard::callback($this->texts->get(BotText::PayAgain), PurchaseHandler::reopenCallback($payment->order_id)));

            return $this->message($this->texts->render($awaitingReceipt ? BotText::PaymentReminder : BotText::PaymentReminderRejected, [
                'order' => $payment->order_id,
                'amount' => Money::format($payment->amount),
            ]), ['reply_markup' => $keyboard->build()]);
        });
    }

    /** Support switched the service off, with their note if they left one. */
    public function serviceDisabled(Subscription $subscription, ?string $note): void
    {
        $this->tell($subscription, NoticeType::ServiceDisabled, fn(): array => $this->serviceChanged($subscription, BotText::ServiceDisabledBySupport, ['note' => $this->adminNote($note)]));
    }

    /** Support switched it back on. */
    public function serviceEnabled(Subscription $subscription): void
    {
        $this->tell($subscription, NoticeType::ServiceEnabled, fn(): array => $this->serviceChanged($subscription, BotText::ServiceEnabledBySupport));
    }

    /**
     * Support deleted the service, with their note if they left one. It is gone from the shop by now: the notice is about
     * their account, with nothing for their website to open.
     */
    public function serviceDeleted(Subscription $subscription, ?string $note): void
    {
        $this->tell($subscription->user, NoticeType::ServiceDeleted, fn(): array => $this->serviceChanged($subscription, BotText::ServiceDeletedBySupport, ['note' => $this->adminNote($note)]));
    }

    /** The service moved to another server: the new link, like a delivery (the QR card or the text). */
    public function serviceMoved(Subscription $subscription): void
    {
        $this->tell($subscription, NoticeType::ServiceMoved, function () use ($subscription): array {
            $caption = $this->card->text($subscription, BotText::ServiceMoved);

            return [$caption, fn(int $chat): array => $this->card->send($chat, $subscription, $caption)];
        });
    }

    /**
     * The service got days and traffic — its share of a grant, or support's extension of it alone —: what it got, the
     * word support gave with it, and where it stands now.
     */
    public function serviceGranted(Subscription $subscription, int $days, int $bytes, ?string $note): void
    {
        $this->tell($subscription, NoticeType::ServiceGranted, fn(): array => $this->message($this->card->text($subscription, BotText::ServiceGranted, [
            'gift' => Messages::gift($days, $bytes),
            'note' => $this->adminNote($note),
        ])));
    }

    /**
     * «یادآوری»: the service ends soon — its deadline and what is left, and, when it could renew itself
     * (`$autoRenewHint`), the switch that would; the button opens its screen.
     */
    public function expiryReminder(Subscription $subscription, bool $autoRenewHint): void
    {
        $this->tell($subscription, NoticeType::ExpiryReminder, fn(): array => $this->message($this->card->text($subscription, BotText::ExpiryReminder, [
            'hint' => $autoRenewHint ? $this->texts->part(BotText::ReminderAutoRenewHint) : '',
        ]), ['reply_markup' => $this->serviceButton($subscription)]));
    }

    /** «یادآوری»: most of the service's traffic is used — how much, and what is left; the button opens its screen. */
    public function trafficReminder(Subscription $subscription): void
    {
        $this->tell($subscription, NoticeType::TrafficReminder, fn(): array => $this->message($this->card->text($subscription, BotText::TrafficReminder, [
            'percent' => Messages::percent($subscription->usedBytes(), $subscription->traffic_limit_bytes),
        ]), ['reply_markup' => $this->serviceButton($subscription)]));
    }

    /** «تمدید خودکار» renewed the service: what the wallet paid, what is left in it, the new deadline — the link has not changed. */
    public function autoRenewed(Order $renewal): void
    {
        $this->tell($renewal, NoticeType::AutoRenewed, fn(): array => $this->message($this->card->renewalText($renewal, BotText::AutoRenewed, [
            'amount' => Money::format($renewal->amount),
            'balance' => Messages::balance($renewal->user->balance()),
        ])));
    }

    /**
     * «تمدید خودکار» found the wallet short: the price, the balance and the deadline, with the button that tops the
     * wallet up — the next run renews once it can pay.
     */
    public function autoRenewShort(Subscription $subscription, string $price): void
    {
        $this->tell($subscription, NoticeType::AutoRenewShort, fn(): array => $this->message($this->texts->render(BotText::AutoRenewShort, [
            'client' => $subscription->remote_name,
            'amount' => Money::format($price),
            'balance' => Messages::balance($subscription->user->balance()),
            'expires' => Messages::expiry($subscription->expires_at, $subscription->duration_days),
        ]), ['reply_markup' => InlineKeyboard::make()->row($this->buttons->topUp())->build()]));
    }

    /** A renewal was paid but not delivered: support finishes it from the payments screen. */
    public function renewalFailed(Order $renewal): void
    {
        $this->tell($renewal, NoticeType::RenewalFailed, fn(): array => $this->message($this->card->failedText($renewal)));
    }

    /** Someone's link brought a newcomer: its owner hears it. */
    public function referralJoined(User $referrer, User $newcomer): void
    {
        $this->tell($referrer, NoticeType::ReferralJoined, fn(): array => $this->message($this->texts->render(BotText::ReferralJoined, [
            'name' => $newcomer->name() ?? '',
        ])));
    }

    /** Support approved the customer's request to become an agent: their level, its price per GB and their credit. */
    public function agencyApproved(User $agent): void
    {
        // An agency ended in the same moment was told its end instead.
        if ($agent->agencyLevel !== null) {
            $this->agencyTerms($agent, BotText::AgencyApproved, NoticeType::AgencyApproved);
        }
    }

    /** Support moved an agent — one still — to another level, or changed their credit: whether they were told, the panel says. */
    public function agencyChanged(User $agent): Delivery
    {
        return $this->agencyTerms($agent, BotText::AgencyChanged, NoticeType::AgencyChanged);
    }

    /** Support rejected the request to become an agent, with their note if they left one. */
    public function agencyRejected(User $user, ?string $reason): void
    {
        $this->tell($user, NoticeType::AgencyRejected, fn(): array => $this->message($this->texts->render(BotText::AgencyRejected, ['note' => $this->adminNote($reason)])));
    }

    /** Support ended the agency, with their note if they left one. */
    public function agencyRevoked(User $user, ?string $note): void
    {
        $this->tell($user, NoticeType::AgencyRevoked, fn(): array => $this->message($this->texts->render(BotText::AgencyRevoked, ['note' => $this->adminNote($note)])));
    }

    /** Support turned off the two-factor sign-in of the customer's account on the website: the password alone signs them in now. */
    public function twoFactorDisabled(User $user): void
    {
        $this->tellAccount($user, NoticeType::TwoFactorDisabled, BotText::TwoFactorDisabled);
    }

    /** The customer turned the two-factor sign-in of their account on the website on. */
    public function twoFactorEnabled(User $user): void
    {
        $this->tellAccount($user, NoticeType::TwoFactorEnabled, BotText::TwoFactorTurnedOn);
    }

    /** The customer turned it off themselves, with their password. */
    public function twoFactorTurnedOff(User $user): void
    {
        $this->tellAccount($user, NoticeType::TwoFactorDisabled, BotText::TwoFactorTurnedOff);
    }

    /** A way into the customer's account on the website was added — `$way` names its kind (Telegram, Google, an email). */
    public function wayInAdded(User $user, string $way): void
    {
        $this->tellAccount($user, NoticeType::WayInAdded, BotText::WayInAdded, ['way' => $way]);
    }

    /** A way into the customer's account on the website was taken away — `$way` names its kind. */
    public function wayInRemoved(User $user, string $way): void
    {
        $this->tellAccount($user, NoticeType::WayInRemoved, BotText::WayInRemoved, ['way' => $way]);
    }

    /** The password of the customer's account on the website was set or changed — from their account, or by a reset. */
    public function passwordChanged(User $user): void
    {
        $this->tellAccount($user, NoticeType::PasswordChanged, BotText::PasswordChanged);
    }

    /** Another account of the customer's and this one — the account that stayed — were made one. */
    public function accountMerged(User $user): void
    {
        $this->tellAccount($user, NoticeType::AccountMerged, BotText::AccountMerged);
    }

    /** The second step of the account's password sign-in was failed until it waits: whoever had the password, it was right. */
    public function secondStepLocked(User $user): void
    {
        $this->tellAccount($user, NoticeType::SecondStepLocked, BotText::SecondStepLocked);
    }

    /** The codes emailed to the account's address were failed until no new one goes to it for a while: someone is guessing at them. */
    public function emailCodesFailed(User $user): void
    {
        $this->tellAccount($user, NoticeType::EmailCodesFailed, BotText::EmailCodesFailed);
    }

    /**
     * Support answered the customer's ticket: which ticket, and the answer — as much of it as a message holds (the whole is
     * on their website) —, a picture with it said so and sent: the picture, the notice its caption, while it fits one and
     * Telegram takes the picture (the words alone otherwise — the ticket's screen sends the picture); «✍️ پاسخ» and
     * «🗂️ مشاهده تیکت» under it.
     */
    public function ticketAnswered(Ticket $ticket, TicketMessage $answer): void
    {
        $this->tell($ticket, NoticeType::TicketAnswered, function () use ($ticket, $answer): array {
            $words = fn(string $body): string => $this->texts->render(BotText::TicketAnswered, [
                'ticket' => $ticket->id,
                'subject' => $ticket->subject,
                'answer' => $body,
                'picture' => $answer->hasPicture() ? $this->texts->part(BotText::TicketAnswerPicture) : '',
            ]);
            $text = $words($answer->body);
            $over = TelegramHtml::visibleLength($text) - (Limits::MESSAGE - self::MESSAGE_ROOM);
            if ($over > 0) {
                $text = $words(Limits::fit($answer->body, Limits::length($answer->body) - $over));
            }
            $options = ['reply_markup' => $this->ticketButtons($ticket, InlineKeyboard::callback($this->texts->get(BotText::TicketReply), TicketHandler::replyFromNotice($ticket->id), 'primary'))];

            return [$text, $this->ticketNotice($ticket, function (int $chat) use ($answer, $text, $options): mixed {
                $captioned = $answer->keepsPicture() && TelegramHtml::visibleLength($text) <= Limits::CAPTION;

                return ($captioned && $this->attachments->send($chat, $answer, ['caption' => $text] + $options, fetch: false)) ?: $this->api->sendMessage($chat, $text, $options);
            })];
        });
    }

    /**
     * Support closed the customer's ticket: a message to it opens it again; «⭐ امتیاز» under it while they have not rated
     * it, and «🗂️ مشاهده تیکت».
     */
    public function ticketClosed(Ticket $ticket): void
    {
        $this->tell($ticket, NoticeType::TicketClosed, function () use ($ticket): array {
            [$text, $send] = $this->message($this->texts->render(BotText::TicketClosed, [
                'ticket' => $ticket->id,
                'subject' => $ticket->subject,
            ]), ['reply_markup' => $this->ticketButtons($ticket, ...($ticket->rating === null ? [InlineKeyboard::callback($this->texts->get(BotText::TicketRate), TicketHandler::rateFromNotice($ticket->id), 'success')] : []))]);

            return [$text, $this->ticketNotice($ticket, $send)];
        });
    }

    /**
     * An agent's bot could not deliver an order: their traffic does not cover it. The agent hears it in the main bot,
     * with the button that buys more — the order waits for the retry on their panel. It is their customer's order, in
     * their bot's shop: nothing their own website opens.
     */
    public function agencyTrafficShort(Order $order): void
    {
        $bot = $order->shop();
        $agent = $bot->agent;
        if ($bot->isMain() || $agent === null) {
            return;
        }

        $this->tell($agent, NoticeType::AgencyTrafficShort, fn(): array => $this->message($this->texts->render(BotText::AgencyTrafficShort, [
            'order' => $order->id,
            'traffic' => Messages::bytes(max(0, $bot->trafficBalance())),
        ]), ['reply_markup' => InlineKeyboard::make()->row($this->buttons->buyTraffic())->build()]));
    }

    /**
     * The commission the payment earned the customer's referrer (ReferralService::reward()) — the one it was credited to
     * —, told once: the first notice claims the row, so a retry that tells the customer again does not tell the referrer
     * twice. The payment is their referral's: nothing their own website opens.
     */
    private function commissionEarned(Payment $payment): void
    {
        $commission = ReferralCommission::query()->with('referrer')->where('payment_id', $payment->id)->first();
        if ($commission === null || !Transitions::claim($commission, 'notified_at')) {
            return;
        }
        $referrer = $commission->referrer;

        $this->tell($referrer, NoticeType::ReferralCommission, fn(): array => $this->message($this->texts->render(BotText::ReferralCommission, [
            'commission' => Money::format($commission->commission),
            'paid' => Money::format($payment->amount),
            'balance' => Messages::balance($referrer->balance()),
        ])));
    }

    /** An agent's terms in one of the texts that tell them: level, price per GB, credit. */
    private function agencyTerms(User $agent, BotText $text, NoticeType $type): Delivery
    {
        $level = $agent->agencyLevel ?? throw new \LogicException("User #{$agent->id} is not an agent.");

        return $this->tell($agent, $type, fn(): array => $this->message($this->texts->render($text, [
            'level' => $level->name,
            'price' => Money::format($level->price_per_gb),
            'credit' => Messages::credit($agent->credit_limit),
        ])));
    }

    /**
     * Support's change of a service, worded: its client's name on the panel and the text's own values.
     *
     * @param array<string, scalar|Html> $values the text's own, besides the client's name
     * @return array{string, \Closure(int): array<string, mixed>}
     */
    private function serviceChanged(Subscription $subscription, BotText $text, array $values = []): array
    {
        return $this->message($this->texts->render($text, ['client' => $subscription->remote_name] + $values));
    }

    /**
     * A notice that goes as a plain message: its words, and how Telegram is sent them — with `$options` (a reply target,
     * buttons).
     *
     * @param array<string, mixed> $options
     * @return array{string, \Closure(int): array<string, mixed>}
     */
    private function message(string $text, array $options = []): array
    {
        return [$text, fn(int $chat): array => $this->api->sendMessage($chat, $text, $options)];
    }

    /**
     * How a notice of the ticket goes to the customer's chat: `$send`, once a chat left on another of their tickets left it
     * (TicketMessageHandler::leaveOtherTicket()) — what they type next answers no ticket by mistake; the notice's own
     * «✍️ پاسخ» is the way to this one.
     *
     * @param \Closure(int): mixed $send
     * @return \Closure(int): mixed
     */
    private function ticketNotice(Ticket $ticket, \Closure $send): \Closure
    {
        return static function (int $chat) use ($ticket, $send): mixed {
            TicketMessageHandler::leaveOtherTicket($chat, $ticket);

            return $send($chat);
        };
    }

    /**
     * The buttons under a notice of the ticket, in a row: what the notice asks of the customer first (`$first`, on the
     * right — Telegram lays a row out left to right), then «🗂️ مشاهده تیکت». Each opens what it does as a message of its
     * own, so the notice — support's words — stays (TicketHandler).
     *
     * @param array<string, mixed> ...$first
     * @return array{inline_keyboard: list<list<array<string, mixed>>>}
     */
    private function ticketButtons(Ticket $ticket, array ...$first): array
    {
        return InlineKeyboard::make()->row(InlineKeyboard::callback($this->texts->get(BotText::TicketView), TicketHandler::viewFromNotice($ticket->id)), ...$first)->build();
    }

    /** @return array{inline_keyboard: list<list<array<string, mixed>>>} The one button under a reminder: the service's screen. */
    private function serviceButton(Subscription $subscription): array
    {
        return InlineKeyboard::make()->row(InlineKeyboard::callback($this->texts->get(BotText::ReminderOpenService), SubscriptionHandler::serviceCallback($subscription->id)))->build();
    }

    /** Support's word to the customer on its own line (BotText::AdminNote), or nothing when they left none. */
    private function adminNote(?string $note): Html|string
    {
        $note = trim((string) $note);

        return $note === '' ? '' : $this->texts->part(BotText::AdminNote, ['comment' => $note]);
    }

    /**
     * Tell the customer `$about` concerns, in the shop of the bot they talk to — its texts, its rules, its token: an
     * agent's customer hears from the agent's bot, whoever acted. `$compose` words the notice there — its text, and how
     * Telegram is sent it, given their chat — and Notices delivers it: kept for their website (what it is about: the
     * order of a payment, an order, a service — nothing for their account), then to their Telegram chat or their email.
     * What came of it is the answer — the screens that tell for telling's sake say it (a reminder, an agent's new terms).
     *
     * @param \Closure(): array{string, \Closure(int): mixed} $compose
     */
    private function tell(Order|Payment|Subscription|Ticket|User $about, NoticeType $type, \Closure $compose): Delivery
    {
        return CurrentBot::run($about->shop(), function () use ($about, $type, $compose): Delivery {
            [$customer, $subject] = match (true) {
                $about instanceof User => [$about, null],
                $about instanceof Payment => [$about->order->user, $about->order],
                $about instanceof Order => [$about->user, $about],
                $about instanceof Ticket => [$about->user, $about],
                default => [$about->user, $about],
            };
            [$text, $telegram] = $compose();

            return $this->notices->deliver($customer, $type, $text, $subject, $telegram);
        });
    }

    /**
     * A change of how the customer's account is signed in to, worded in their shop and told as a plain message on every
     * door it has — their Telegram chat and their email both (Notices' `everyDoor`): whoever made the change may hold one.
     *
     * @param array<string, scalar> $values
     */
    private function tellAccount(User $user, NoticeType $type, BotText $text, array $values = []): void
    {
        CurrentBot::run($user->shop(), function () use ($user, $type, $text, $values): Delivery {
            $words = $this->texts->render($text, $values);

            return $this->notices->deliver($user, $type, $words, null, fn(int $chat): array => $this->api->sendMessage($chat, $words), everyDoor: true);
        });
    }

    /**
     * A message about a payment quotes the receipt it is about: a reply to that message when we know it (a receipt sent
     * through the bot), a plain message otherwise (no receipt, or the customer deleted theirs —
     * `allow_sending_without_reply`).
     *
     * @return array<string, mixed>
     */
    private static function replyingToReceipt(Payment $payment): array
    {
        return $payment->receipt_message_id === null ? [] : ['reply_parameters' => ['message_id' => $payment->receipt_message_id, 'allow_sending_without_reply' => true]];
    }
}
