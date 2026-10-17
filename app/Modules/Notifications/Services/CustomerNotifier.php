<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Core\Database\Transitions;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Enums\SellingSuspension;
use App\Modules\Discounts\Models\LoyaltyTier;
use App\Modules\Notifications\Enums\Delivery;
use App\Modules\Notifications\Enums\NoticeType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\GatewayRegistry;
use App\Modules\Payments\Models\Payment;
use App\Modules\Referrals\Models\ReferralCommission;
use App\Modules\Rewards\Models\Cashback;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketMessage;
use App\Modules\Support\Services\TicketAttachments;
use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Api\Limits;
use App\Modules\Telegram\BotSettings;
use App\Modules\Telegram\Handlers\PurchaseHandler;
use App\Modules\Telegram\Handlers\ReceiptHandler;
use App\Modules\Telegram\Handlers\TicketHandler;
use App\Modules\Telegram\Handlers\TicketMessageHandler;
use App\Modules\Telegram\Keyboard\Buttons;
use App\Modules\Telegram\Keyboard\InlineKeyboard;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Notifications\AgencyTermLines;
use App\Modules\Telegram\Notifications\ServiceCard;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Texts\Html;
use App\Modules\Telegram\Texts\TelegramHtml;
use App\Modules\Users\Models\User;
use App\Modules\Users\Models\WalletTransfer;
use App\Support\JalaliMonth;
use App\Support\Money;
use App\Support\Persian;

/**
 * Everything the shop tells a customer on its own, once something was decided — a method per notice: payments (settled,
 * rejected, cancelled, refunded, reminded), money sent from one wallet to another, services (switched off or on, moved,
 * deleted, given days and traffic, handed to another customer),
 * renewals («تمدید خودکار» done or short), reminders («یادآوری»), referrals, the agency, tickets, cashback and loyalty,
 * the nightly draw, and every change of how their website account is signed in to.
 *
 * Each notice is composed inside the recipient's shop (tell()), whoever decided: an agent's customer hears from the
 * agent's bot, in the agent's wording, with balances read in that shop. That is why every method hands tell() a closure
 * instead of finished words. Notices then keeps it for their website and sends it to their Telegram chat or, failing
 * that, their email. A payment's message replies to the customer's receipt when there is one. Buttons only where the
 * customer is asked something: the payment reminder, the short wallet, the service reminders, a service handed to them
 * and a ticket's notices.
 * A service itself is sent by Telegram\Notifications\ServiceCard, its caption what the website keeps.
 */
final class CustomerNotifier
{
    /** A margin kept under Telegram's message limit (Limits counts as Telegram does: an emoji is two units). */
    private const MESSAGE_ROOM = 96;

    public function __construct(
        private readonly BotApi $api,
        private readonly ServiceCard $card,
        private readonly AgencyTermLines $termLines,
        private readonly Notices $notices,
        private readonly BotTexts $texts,
        private readonly Buttons $buttons,
        private readonly BotSettings $bot,
        private readonly TicketAttachments $attachments,
        private readonly GatewayRegistry $gateways,
    ) {}

    /**
     * A payment was settled after the fact: the service or the charged wallet, or that delivery failed and support will
     * finish it. A referrer it earned a commission is notified too.
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

        $this->commissionSettled($payment);
    }

    /**
     * The referrer of the payment's customer told what its settlement earned them, once — on its own where the customer
     * is told otherwise (a checkout that found an online page paid shows them the order: Payments\Services\
     * OnlinePayments::paidBefore()).
     */
    public function commissionSettled(Payment $payment): void
    {
        CurrentBot::run($payment->shop(), fn() => $this->commissionEarned($payment));
    }

    /**
     * The commission a referral's wallet top-up earned the referrer, taken back out of their wallet by the top-up's refund
     * (Referrals\Services\ReferralService::takeBack()): how much, and their balance now.
     */
    public function commissionTakenBack(ReferralCommission $commission): void
    {
        $referrer = $commission->referrer;
        $this->tell($referrer, NoticeType::ReferralCommissionTakenBack, fn(): array => $this->message($this->texts->render(BotText::ReferralCommissionTakenBack, [
            'commission' => Money::format($commission->commission),
            'balance' => Messages::balance($referrer->balance()),
        ])));
    }

    /**
     * An online payment came in after its order had closed (Payments\Services\PaymentService::settleOnline()): its money
     * went to the wallet.
     */
    public function paymentCredited(Payment $payment): void
    {
        $this->tell($payment, NoticeType::PaymentCredited, fn(): array => $this->message($this->texts->render(BotText::PaymentCredited, [
            'amount' => Money::format($payment->amount),
            'order' => $payment->order_id,
            'balance' => Messages::balance($payment->order->user->balance()),
        ])));
    }

    /** Support refused the receipt, with the reason (the payment's note); the order stays open. */
    public function paymentRejected(Payment $payment): void
    {
        $this->tell($payment, NoticeType::PaymentRejected, fn(): array => $this->message($this->texts->render(BotText::ReceiptRejected, [
            'order' => $payment->order_id,
            'note' => $this->adminNote($payment->note),
        ]), self::replyingToReceipt($payment)));
    }

    /** Support cancelled the order, with the note they left on the payment, if any. */
    public function orderCancelled(Payment $payment): void
    {
        $this->tell($payment, NoticeType::OrderCancelled, fn(): array => $this->message($this->texts->render(BotText::OrderCancelledByAdmin, [
            'order' => $payment->order_id,
            'note' => $this->adminNote($payment->note),
        ]), self::replyingToReceipt($payment)));
    }

    /**
     * The payment was refunded, with support's note. A service's money came back as balance, and what the refund took
     * back of the service (`$service`, its panel name; null for nothing) is said: a purchase's service removed, a
     * renewal's or an extra's term and traffic, an extra's devices. A top-up's charge went out of the wallet again (or,
     * never delivered, the wallet is as it was). Cashback the order earned is said taken back.
     */
    public function paymentRefunded(Payment $payment, ?string $service = null): void
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
        ] + ($text === BotText::TopupRefundedUndelivered ? [] : ['balance' => Messages::balance($order->user->balance())])) . $this->cashbackTakenBack($order) . $this->takenBack($order, $service), self::replyingToReceipt($payment)));
    }

    /** The line under a refund saying what it took back of `$service`: a purchase's service, another's gift; '' for none. */
    private function takenBack(Order $order, ?string $service): string
    {
        if ($service === null) {
            return '';
        }

        return (string) $this->texts->part(match ($order->type) {
            OrderType::Purchase => BotText::PaymentRefundedService,
            OrderType::ExtraDevices => BotText::PaymentRefundedDevicesTakenBack,
            default => BotText::PaymentRefundedTakenBack,
        }, ['client' => $service]);
    }

    /**
     * Support nudges a customer whose payment never completed: one still waiting for its receipt gets "send receipt" and
     * "pay again" — one shown no card, taken in support's private chat (Payment::TO_SUPPORT), the way there instead of
     * "send receipt", as the payment's own message said, whatever the shop does now —, a rejected one only "pay again".
     * Returns the delivery, which the panel reports.
     */
    public function paymentReminder(Payment $payment): Delivery
    {
        return $this->tell($payment, NoticeType::PaymentReminder, function () use ($payment): array {
            $values = ['order' => $payment->order_id, 'amount' => Money::format($payment->amount)];
            $keyboard = InlineKeyboard::make();
            $text = BotText::PaymentReminderRejected;
            if ($payment->status === PaymentStatus::Pending && $this->gateways->isManual($payment->method)) {
                if ($payment->card_id === null) {
                    $text = BotText::PaymentReminderToSupport;
                    $values['contact'] = $this->bot->supportContact();
                    $keyboard->row(...$this->buttons->supportChat());
                } else {
                    $text = BotText::PaymentReminder;
                    $keyboard->row(InlineKeyboard::callback($this->texts->get(BotText::SendReceipt), ReceiptHandler::stateFor($payment)));
                }
            }
            $keyboard->row(InlineKeyboard::callback($this->texts->get(BotText::PayAgain), PurchaseHandler::reopenCallback($payment->order_id)));

            return $this->message($this->texts->render($text, $values), ['reply_markup' => $keyboard->build()]);
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
     * Support deleted the service, with their note if they left one. The row is gone, so the notice is about their
     * account, with nothing for their website to open.
     */
    public function serviceDeleted(Subscription $subscription, ?string $note): void
    {
        $this->tell($subscription->user, NoticeType::ServiceDeleted, fn(): array => $this->serviceChanged($subscription, BotText::ServiceDeletedBySupport, ['note' => $this->adminNote($note)]));
    }

    /** The service moved to another server: the new link, sent like a delivery (the QR card or the text). */
    public function serviceMoved(Subscription $subscription): void
    {
        $this->tell($subscription, NoticeType::ServiceMoved, function () use ($subscription): array {
            $caption = $this->card->text($subscription, BotText::ServiceMoved);

            return [$caption, fn(int $chat): array => $this->card->send($chat, $subscription, $caption)];
        });
    }

    /**
     * Support handed the service to another customer (ServiceTransfers): its previous owner hears it, and which of their
     * unpaid orders of it went with it (`$cancelled`, their ids). It is theirs no more, so the notice is about their
     * account, with nothing for their website to open.
     *
     * @param list<int> $cancelled
     */
    public function serviceTransferred(Subscription $subscription, User $from, array $cancelled): void
    {
        $this->tell($from, NoticeType::ServiceTransferred, fn(): array => $this->serviceChanged($subscription, BotText::ServiceTransferredBySupport, [
            'cancelled' => $cancelled === [] ? '' : $this->texts->part(BotText::ServiceTransferredOrders, [
                // A left-to-right mark before each: after the Persian words, «#142» would read «142#».
                'orders' => implode('، ', array_map(static fn(int $id): string => "\u{200E}#{$id}", $cancelled)),
            ]),
        ]));
    }

    /**
     * A service was handed to the customer (ServiceTransfers), by its previous owner or by support: what it is and where it
     * stands now, with «📊 مشاهده سرویس» — its screen has the link. Its previous owner keeps that link: where its panel can
     * change it (`$rotates`, ProvisioningService::rotates()), the notice says «تغییر لینک» cuts their devices off.
     */
    public function serviceReceived(Subscription $subscription, bool $rotates): void
    {
        $this->tell($subscription, NoticeType::ServiceReceived, fn(): array => $this->message($this->card->text($subscription, BotText::ServiceReceived, [
            'link_change' => $rotates ? $this->texts->part(BotText::ServiceReceivedLinkChange) : '',
        ]), ['reply_markup' => $this->buttons->serviceOnly($subscription->id)]));
    }

    /** The service got days and traffic (a grant's share, or support's extension): what, support's note, and where it stands now. */
    public function serviceGranted(Subscription $subscription, int $days, int $bytes, ?string $note): void
    {
        $this->tell($subscription, NoticeType::ServiceGranted, fn(): array => $this->message($this->card->text($subscription, BotText::ServiceGranted, [
            'gift' => Messages::gift($days, $bytes),
            'note' => $this->adminNote($note),
        ])));
    }

    /**
     * «یادآوری»: the service ends soon — its deadline and what is left, plus a hint about «تمدید خودکار» when it could
     * renew itself (`$autoRenewHint`); the button opens its screen.
     */
    public function expiryReminder(Subscription $subscription, bool $autoRenewHint): void
    {
        $this->tell($subscription, NoticeType::ExpiryReminder, fn(): array => $this->message($this->card->text($subscription, BotText::ExpiryReminder, [
            'hint' => $autoRenewHint ? $this->texts->part(BotText::ReminderAutoRenewHint) : '',
        ]), ['reply_markup' => $this->buttons->serviceOnly($subscription->id)]));
    }

    /** «یادآوری»: most of the service's traffic is used — how much and what is left; the button opens its screen. */
    public function trafficReminder(Subscription $subscription): void
    {
        $this->tell($subscription, NoticeType::TrafficReminder, fn(): array => $this->message($this->card->text($subscription, BotText::TrafficReminder, [
            'percent' => Messages::percent($subscription->usedBytes(), $subscription->traffic_limit_bytes),
        ]), ['reply_markup' => $this->buttons->serviceOnly($subscription->id)]));
    }

    /** «تمدید خودکار» renewed the service: what the wallet paid, its balance, the new deadline (the link is unchanged). */
    public function autoRenewed(Order $renewal): void
    {
        $this->tell($renewal, NoticeType::AutoRenewed, fn(): array => $this->message($this->card->renewalText($renewal, BotText::AutoRenewed, [
            'amount' => Money::format($renewal->amount),
            'balance' => Messages::balance($renewal->user->balance()),
        ])));
    }

    /**
     * «تمدید خودکار» found the wallet short: the price, the balance and the deadline, with a top-up button; the next run
     * renews once it can pay.
     */
    public function autoRenewShort(Subscription $subscription, string $price): void
    {
        $this->tell($subscription, NoticeType::AutoRenewShort, fn(): array => $this->message($this->texts->render(BotText::AutoRenewShort, [
            'client' => $subscription->remote_name,
            'amount' => Money::format($price),
            'balance' => Messages::balance($subscription->user->balance()),
            'expires' => Messages::expiry($subscription->expires_at, $subscription->duration_hours),
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

    /**
     * «هدیه شروع» landed in the customer's wallet: a referrer's for the newcomer their link brought (`$newcomer`), or the
     * newcomer's own (null) — the amount and the balance now.
     */
    public function referralStartGift(User $user, string $amount, ?User $newcomer): void
    {
        $this->tell($user, NoticeType::ReferralStartGift, fn(): array => $this->message($newcomer !== null
            ? $this->texts->render(BotText::ReferralStartGiftReferrer, ['amount' => Money::format($amount), 'name' => $newcomer->name() ?? '', 'balance' => Messages::balance($user->balance())])
            : $this->texts->render(BotText::ReferralStartGiftInvitee, ['amount' => Money::format($amount), 'balance' => Messages::balance($user->balance())])));
    }

    /**
     * «جایزه دعوت»: `$buyers` of the referrer's referrals bought, so a service of the plan was given to them free (the
     * service itself comes as any delivered order).
     */
    public function referralRewarded(User $referrer, string $plan, int $buyers): void
    {
        $this->tell($referrer, NoticeType::ReferralReward, fn(): array => $this->message($this->texts->render(BotText::ReferralRewarded, [
            'buyers' => Persian::number($buyers),
            'plan' => $plan,
        ])));
    }

    /** Support approved the customer's request to become an agent: their level, its price per GB and their credit. */
    public function agencyApproved(User $agent): void
    {
        // An agency revoked in the same moment was told its end instead.
        if ($agent->agencyLevel !== null) {
            $this->agencyTerms($agent, BotText::AgencyApproved, NoticeType::AgencyApproved);
        }
    }

    /** Support changed an agent's level or credit; returns the delivery, which the panel reports. */
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

    /** An agent's agency ends soon (Agency\Services\AgencyStanding): when, and that their bot's selling stops then. */
    public function agencyExpiring(User $agent, \DateTimeInterface $expiresAt): void
    {
        $this->tell($agent, NoticeType::AgencyExpiring, fn(): array => $this->message($this->texts->render(BotText::AgencyExpiring, [
            'date' => Persian::date($expiresAt, withTime: true),
        ])));
    }

    /** A Jalali month nears its end with the agent's traffic bought below their monthly minimum, with «💾 خرید حجم». */
    public function agencyMinimumShort(User $agent, JalaliMonth $month, string $purchased, string $minimum, int $days): void
    {
        $this->tell($agent, NoticeType::AgencyMinimumShort, fn(): array => $this->message($this->texts->render(BotText::AgencyMinimumShort, [
            'month' => $month->name(),
            'days' => Persian::number($days),
            'purchased' => Money::format($purchased),
            'minimum' => Money::format($minimum),
        ]), $this->buyTrafficKeyboard()));
    }

    /**
     * The agent's bot sells nothing new now: why, the owner's note when they suspended it by hand — and «💾 خرید حجم» when
     * buying is the way back.
     */
    public function agencySuspended(User $agent, SellingSuspension $reason, ?string $note): void
    {
        $this->tell($agent, NoticeType::AgencySuspended, fn(): array => $this->message(
            $this->texts->render(BotText::AgencySuspended, ['reason' => $reason->words(), 'note' => $this->adminNote($note)]),
            $reason === SellingSuspension::Minimum ? $this->buyTrafficKeyboard() : [],
        ));
    }

    /** The agent's bot sells again. */
    public function agencyResumed(User $agent): void
    {
        $this->tell($agent, NoticeType::AgencyResumed, fn(): array => $this->message($this->texts->get(BotText::AgencyResumed)));
    }

    /** Support turned off the two-factor sign-in of the customer's website account. */
    public function twoFactorDisabled(User $user): void
    {
        $this->tellAccount($user, NoticeType::TwoFactorDisabled, BotText::TwoFactorDisabled);
    }

    /** The customer turned on two-factor sign-in for their website account. */
    public function twoFactorEnabled(User $user): void
    {
        $this->tellAccount($user, NoticeType::TwoFactorEnabled, BotText::TwoFactorTurnedOn);
    }

    /** The customer turned two-factor sign-in off themselves, with their password. */
    public function twoFactorTurnedOff(User $user): void
    {
        $this->tellAccount($user, NoticeType::TwoFactorDisabled, BotText::TwoFactorTurnedOff);
    }

    /** A way into the customer's website account was added; `$way` names its kind (Telegram, Google, an email). */
    public function wayInAdded(User $user, string $way): void
    {
        $this->tellAccount($user, NoticeType::WayInAdded, BotText::WayInAdded, ['way' => $way]);
    }

    /** A way into the customer's website account was removed; `$way` names its kind. */
    public function wayInRemoved(User $user, string $way): void
    {
        $this->tellAccount($user, NoticeType::WayInRemoved, BotText::WayInRemoved, ['way' => $way]);
    }

    /** The password of the customer's website account was set or changed (from their account, or by a reset). */
    public function passwordChanged(User $user): void
    {
        $this->tellAccount($user, NoticeType::PasswordChanged, BotText::PasswordChanged);
    }

    /** Another account of the customer's was merged into this one, the account that stayed. */
    public function accountMerged(User $user): void
    {
        $this->tellAccount($user, NoticeType::AccountMerged, BotText::AccountMerged);
    }

    /** The second step of a password sign-in was failed until it locked: whoever tried had the right password. */
    public function secondStepLocked(User $user): void
    {
        $this->tellAccount($user, NoticeType::SecondStepLocked, BotText::SecondStepLocked);
    }

    /** The codes emailed to the account's address were failed until none is sent for a while: someone is guessing. */
    public function emailCodesFailed(User $user): void
    {
        $this->tellAccount($user, NoticeType::EmailCodesFailed, BotText::EmailCodesFailed);
    }

    /**
     * Support answered the customer's ticket: which ticket and the answer, cut to what a message holds (the whole is on
     * the website). An answer's picture goes as a photo with the notice as its caption, while the notice fits a caption
     * and Telegram takes the picture; otherwise the words go alone (the ticket's screen sends the picture). Buttons:
     * «✍️ پاسخ» and «🗂️ مشاهده تیکت».
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

    /** «انتقال موجودی»: another customer sent money to the customer's wallet — how much, from whom, and their balance now. */
    public function walletReceived(WalletTransfer $transfer): void
    {
        $this->tell($transfer->recipient, NoticeType::WalletReceived, fn(): array => $this->message($this->texts->render(BotText::WalletReceived, [
            'amount' => Money::format($transfer->amount),
            'name' => $transfer->sender->shownAs(),
            'balance' => Messages::balance($transfer->recipient->balance()),
        ])));
    }

    /**
     * «انتقال موجودی»: the money the customer sent went — how much, to whom, and their balance now. Kept among their
     * website's notices whichever door they used; sent to their chat only when they were not `$answered` there (they
     * sent it from the website): the bot said it in place of its question.
     */
    public function walletSent(WalletTransfer $transfer, bool $answered): void
    {
        $this->tell($transfer->sender, NoticeType::WalletSent, function () use ($transfer, $answered): array {
            [$text, $send] = $this->message($this->texts->render(BotText::WalletSent, [
                'amount' => Money::format($transfer->amount),
                'name' => $transfer->recipient->shownAs(),
                'balance' => Messages::balance($transfer->sender->balance()),
            ]));

            return [$text, $answered ? static fn(int $chat): null => null : $send];
        });
    }

    /** A delivered order earned the customer cashback («کش‌بک»): how much, and their balance now. */
    public function cashbackEarned(Cashback $cashback, Order $order): void
    {
        $this->tell($order, NoticeType::CashbackEarned, fn(): array => $this->message($this->texts->render(BotText::CashbackEarned, [
            'order' => $order->id,
            'cashback_amount' => Money::format($cashback->amount),
            'balance' => Messages::balance($order->user->balance()),
        ])));
    }

    /** A delivered order moved the customer up a loyalty tier: its name and its perks, a line each. */
    public function loyaltyTierUp(User $user, LoyaltyTier $tier): void
    {
        $this->tell($user, NoticeType::LoyaltyTierUp, function () use ($tier): array {
            $percents = ['tier_discount' => Persian::digits($tier->discount_percent) . '٪', 'tier_cashback' => Persian::digits($tier->cashback_percent) . '٪'];
            $perks = [];
            if ($tier->discount_percent > 0) {
                $perks[] = $this->texts->part(BotText::LoyaltyPerkDiscount, ['tier_discount' => $percents['tier_discount']]);
            }
            if ($tier->cashback_percent > 0) {
                $perks[] = $this->texts->part(BotText::LoyaltyPerkCashback, ['tier_cashback' => $percents['tier_cashback']]);
            }

            // Without a perk, the line for them leaves no blank behind.
            return $this->message(rtrim($this->texts->render(BotText::LoyaltyTierUp, ['loyalty_tier' => $tier->name, 'tier_perks' => $perks === [] ? '' : Html::lines(...$perks)] + $percents)));
        });
    }

    /** The customer won the shop's nightly draw and was given `$prize` (already worded). */
    public function lotteryWon(User $user, string $prize): void
    {
        $this->tell($user, NoticeType::LotteryWon, fn(): array => $this->message($this->texts->render(BotText::LotteryWon, ['draw_prize' => $prize])));
    }

    /**
     * Support closed the customer's ticket (a message to it opens it again). Buttons: «⭐ امتیاز» while unrated, and
     * «🗂️ مشاهده تیکت».
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
     * An agent's bot could not deliver an order because their traffic does not cover it. The agent hears it in the main
     * bot with a button to buy more; the order waits for a retry. The order is their customer's, so nothing their own
     * website opens.
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
        ]), $this->buyTrafficKeyboard()));
    }

    /**
     * The commission the payment earned the referrer it was credited to (ReferralService::reward()), announced once:
     * the first notice claims the row, so a retry that notifies the customer again does not notify the referrer twice.
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

    /** The line under a refund saying the order's cashback was taken back (BotText::CashbackTakenBack); '' for none. */
    private function cashbackTakenBack(Order $order): string
    {
        $cashback = Cashback::query()->where('order_id', $order->id)->whereNotNull('reversed_at')->first();

        return $cashback === null ? '' : (string) $this->texts->part(BotText::CashbackTakenBack, ['cashback_amount' => Money::format($cashback->amount)]);
    }

    /** Tell an agent their terms in `$text`: level, price per GB, credit — and under them their bot's end and monthly minimum. */
    private function agencyTerms(User $agent, BotText $text, NoticeType $type): Delivery
    {
        $level = $agent->agencyLevel ?? throw new \LogicException("User #{$agent->id} is not an agent.");

        return $this->tell($agent, $type, fn(): array => $this->message(BotTexts::paragraphs($this->texts->render($text, [
            'level' => $level->name,
            'price' => Money::format($level->price_per_gb),
            'credit' => Messages::credit($agent->credit_limit),
        ]), $this->termLines->terms($agent->ownBot))));
    }

    /** @return array<string, mixed> An agent's notice with «💾 خرید حجم» under it, where buying traffic is the way on. */
    private function buyTrafficKeyboard(): array
    {
        return ['reply_markup' => InlineKeyboard::make()->row($this->buttons->buyTraffic())->build()];
    }

    /**
     * A plain message about support's change of a service: its client's name on the panel and the text's own values.
     *
     * @param array<string, scalar|Html> $values the text's own, besides the client's name
     * @return array{string, \Closure(int): array<string, mixed>}
     */
    private function serviceChanged(Subscription $subscription, BotText $text, array $values = []): array
    {
        return $this->message($this->texts->render($text, ['client' => $subscription->remote_name] + $values));
    }

    /**
     * A notice that goes as a plain message: its text, and how to send it to a chat with `$options` (a reply target,
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
     * Send a ticket's notice with `$send`, first taking the chat off another ticket it was writing into
     * (TicketMessageHandler::leaveOtherTicket()), so what the customer types next answers no ticket by mistake; the
     * notice's own «✍️ پاسخ» leads to this one.
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
     * The buttons under a ticket's notice, one row: what the notice asks first (`$first`, on the right — Telegram lays a
     * row out left to right), then «🗂️ مشاهده تیکت». Each opens a message of its own, so the notice stays (TicketHandler).
     *
     * @param array<string, mixed> ...$first
     * @return array{inline_keyboard: list<list<array<string, mixed>>>}
     */
    private function ticketButtons(Ticket $ticket, array ...$first): array
    {
        return InlineKeyboard::make()->row(InlineKeyboard::callback($this->texts->get(BotText::TicketView), TicketHandler::viewFromNotice($ticket->id)), ...$first)->build();
    }

    /** Support's note on its own line (BotText::AdminNote), or '' when they left none. */
    private function adminNote(?string $note): Html|string
    {
        $note = trim((string) $note);

        return $note === '' ? '' : $this->texts->part(BotText::AdminNote, ['comment' => $note]);
    }

    /**
     * Tell the customer `$about` concerns, inside the shop of the bot they talk to (its texts, rules and token), whoever
     * acted. `$compose` runs there and returns the notice's text and how to send it to a chat; Notices keeps it for the
     * website — linked to the order (a payment's order), service or ticket it is about, nothing for an account — and
     * sends it. Returns the delivery, which some screens report (a reminder, an agent's new terms).
     *
     * @param \Closure(): array{string, \Closure(int): mixed} $compose
     */
    private function tell(Order|Payment|Subscription|Ticket|User $about, NoticeType $type, \Closure $compose): Delivery
    {
        return CurrentBot::run($about->shop(), function () use ($about, $type, $compose): Delivery {
            [$customer, $subject] = match (true) {
                $about instanceof User => [$about, null],
                $about instanceof Payment => [$about->order->user, $about->order],
                default => [$about->user, $about],
            };
            [$text, $telegram] = $compose();

            return $this->notices->deliver($customer, $type, $text, $subject, $telegram);
        });
    }

    /**
     * A change of how the customer's account is signed in to, worded in their shop and sent as a plain message to their
     * Telegram chat and their email both (Notices' `everyDoor`): whoever made the change may control one of them.
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
     * A message about a payment replies to the receipt it is about when the bot knows it (a receipt sent in the bot); a
     * receipt deleted since still lets the message go (`allow_sending_without_reply`).
     *
     * @return array<string, mixed>
     */
    private static function replyingToReceipt(Payment $payment): array
    {
        return $payment->receipt_message_id === null ? [] : ['reply_parameters' => ['message_id' => $payment->receipt_message_id, 'allow_sending_without_reply' => true]];
    }
}
