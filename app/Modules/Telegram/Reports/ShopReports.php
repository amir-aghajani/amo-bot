<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports;

use App\Core\Database\Lease;
use App\Modules\Agency\Enums\AgencyRequestStatus;
use App\Modules\Agency\Models\AgencyRequest;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Drivers\Manual\ManualGateway;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\GatewayRegistry;
use App\Modules\Payments\Models\Payment;
use App\Modules\Providers\Models\Server;
use App\Modules\Referrals\Models\ReferralCommission;
use App\Modules\Reviews\Models\Review;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Support\Enums\TicketAuthor;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketMessage;
use App\Modules\Telegram\Api\Limits;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Texts\TelegramHtml;
use App\Modules\Users\Enums\PictureFolder;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\CustomerPictures;
use App\Support\Money;
use App\Support\Persian;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Psr\Log\LoggerInterface;

/**
 * What the shop tells its admins' report group — a sale delivered, a renewal, a wallet charged, a card-to-card receipt
 * and what was decided about it, a newcomer, a request to become an agent and its verdict, an agent's traffic bought,
 * a delivery that failed, a panel that stopped answering, a support ticket and its conversation (every report of it a
 * reply to the last; a burst of its messages one report while the last still waits — folded()), a review written on the
 * shop's website — each queued as a report_messages row for its topic, at its topic's place in the queue
 * (Topic::priority()), in the group of the bot it happened in (an agent's sales go to the agent's own group; the
 * servers and the agency are the main bot's), which
 * ReportSender delivers at Telegram's pace. A row is written only while a group is connected and the admin wants that
 * topic, in a savepoint of the caller's transaction when there is one, so a report stands or falls with what it reports.
 * Nothing here talks to Telegram. A report whose words cannot be composed is logged and the flow that reported goes on;
 * a database that fails it — reading for its words, or writing it — fails that flow too: inside its transaction, the
 * transaction is the database's to keep or lose, never carried on as if it stood. The words are the admin's own
 * reading, not a customer's — fixed here, not BotTexts.
 */
final class ShopReports
{
    /** A customer's note, a reason, a panel's error: at most this many characters of it in a report. */
    private const FIELD_MAX = 300;

    /** A name in a report — a customer's, a plan's, a server's. */
    private const NAME_MAX = 64;

    /** What `ref` a receipt's report carries, so its verdict replies to it. */
    private const RECEIPT_REF = 'receipt:';

    /** What `ref` a failed delivery's report carries, so a later failure or the delivery answers it. */
    private const DELIVERY_REF = 'delivery:';

    /** What `ref` an agency request's report carries, so its verdict replies to it. */
    private const AGENCY_REF = 'agency:';

    /**
     * What `ref` every report of a ticket carries: the next replies to the last (and takes its «بستن تیکت» over). Each
     * names its ticket too (`ticket_id`): a bot admin's reply to any of them is support's answer (TicketReplies), and they
     * are kept while the ticket is not closed (ReportSender::pruneTickets()).
     */
    private const TICKET_REF = 'ticket:';

    /** Under a new ticket's report: how support answers it — here, or on the panel's «پشتیبانی» page. */
    private const TICKET_REPLY = "\n\n↩️ با ریپلای روی همین پیام (یا هر پیام بعدی این تیکت) پاسخ دهید — یا در صفحه «پشتیبانی» در پنل.";

    /** Under a review's report: where support decides it, and that the website shows it only then. */
    private const REVIEW_PENDING = "\n\n⏳ در انتظار بررسی؛ تا در صفحه «نظرات» پنل تایید نشود، در وب‌سایت دیده نمی‌شود.";

    /**
     * A margin kept under Telegram's limit, beside what it counts (Limits counts as it does: an emoji two units), when a
     * customer's own words are cut to the room a report has: a ticket's message, a review.
     */
    private const ROOM = 64;

    /** Whose words follow: the customer's (a receipt's caption, an agency request), support's (a verdict's reason). */
    private const CUSTOMER_NOTE = '📝 توضیح مشتری: ';
    private const SUPPORT_NOTE = '📝 توضیح پشتیبانی: ';

    public function __construct(
        private readonly ReportGroupState $group,
        private readonly ReportSettings $settings,
        private readonly GatewayRegistry $gateways,
        private readonly ConnectionInterface $db,
        private readonly LoggerInterface $logger,
    ) {}

    /** Someone started the bot for the first time — with the customer whose link brought them, if one did. */
    public function newCustomer(User $user, ?User $referrer): void
    {
        $this->report($user->shop(), Topic::Users, static function () use ($user, $referrer): array {
            $lines = ['👤 <b>کاربر جدید</b>', self::customer($user)];
            if ($referrer !== null) {
                $lines[] = '👥 معرف: ' . self::customer($referrer);
            }

            return ['text' => implode("\n", $lines)];
        });
    }

    /** A customer asked to become an agent: what they wrote, with the bot admins' «تایید» / «رد» buttons (AgencyReview). */
    public function agencyRequested(AgencyRequest $request): void
    {
        $this->report(Bot::MAIN, Topic::Agency, static function () use ($request): array {
            $lines = [
                sprintf('🤝 <b>درخواست نمایندگی</b> · #%d', $request->id),
                '👤 ' . self::customer($request->user),
                ...self::note(self::CUSTOMER_NOTE, $request->note),
                '⏳ در انتظار بررسی — همین‌جا، یا در صفحه «نمایندگان» پنل',
            ];

            return [
                'text' => implode("\n", $lines),
                'keyboard' => AgencyReview::buttons($request->id),
                'ref' => self::AGENCY_REF . $request->id,
            ];
        });
    }

    /** The request was decided — here or on the screen: a reply to its report, which loses its buttons. */
    public function agencyDecided(AgencyRequest $request): void
    {
        $this->report(Bot::MAIN, Topic::Agency, static function () use ($request): array {
            $level = $request->level;
            $text = $request->status === AgencyRequestStatus::Approved
                ? sprintf('✅ درخواست نمایندگی #%d تایید شد: سطح «%s»، هر گیگابایت %s.', $request->id, self::name($level->name ?? '—'), Money::format($level->price_per_gb ?? '0'))
                : sprintf('❌ درخواست نمایندگی #%d رد شد.', $request->id);

            return [
                'text' => implode("\n", [$text, ...self::note(self::SUPPORT_NOTE, $request->reason)]),
                'reply_ref' => self::AGENCY_REF . $request->id,
                'clears_buttons' => true,
            ];
        });
    }

    /**
     * A customer sent the receipt of a card-to-card payment: their picture — a copy of their message in the bot, or the
     * one they uploaded from the website, sent from the shop's own files —, with what it pays for under it and the bot
     * admins' «تایید» / «رد» buttons (ReceiptReview); the verdict will be a reply to it.
     */
    public function receiptSubmitted(Payment $payment): void
    {
        $this->report($payment->shop(), Topic::Receipts, function () use ($payment): array {
            $method = $payment->method;
            $customer = $payment->order->user;
            $lines = [
                sprintf('🧾 <b>رسید جدید</b> · پرداخت #%d', $payment->id),
                '👤 ' . self::customer($customer),
                self::purpose($payment->order),
                sprintf('💳 مبلغ: %s · %s', Money::format($payment->amount), self::name($method->label)),
                ...self::note(self::CUSTOMER_NOTE, $payment->receipt_note),
            ];
            // The method's review window takes a receipt sent in the bot alone: one uploaded from the website waits for support.
            $window = $this->gateways->isManual($method) ? ManualGateway::reviewWindow($method) : 0;
            $lines[] = match (true) {
                $window === 0 => '⏳ در انتظار بررسی در پنل مدیریت',
                $payment->receipt_file_id === null => '⏳ در انتظار بررسی؛ رسیدی که از وب‌سایت می‌رسد خودکار تایید نمی‌شود.',
                default => sprintf('⏳ در انتظار بررسی؛ اگر تا %s بررسی نشود، خودکار تایید می‌شود.', Persian::minutes($window)),
            };

            return [
                'text' => implode("\n", $lines),
                'copy_chat_id' => $payment->receipt_message_id !== null ? $customer->telegram_id : null,
                'copy_message_id' => $payment->receipt_message_id,
                'photo_path' => $payment->receipt_path !== null ? CustomerPictures::reference(PictureFolder::Receipts, $payment->receipt_path) : null,
                'keyboard' => ReceiptReview::buttons($payment->id),
                'ref' => self::RECEIPT_REF . $payment->id,
            ];
        });
    }

    /** The receipt was accepted — by the admin, or by the timer when nobody looked inside the method's window. */
    public function receiptApproved(Payment $payment): void
    {
        $this->verdict($payment, $payment->wasAutoApproved()
            ? sprintf('✅ رسید پرداخت #%d خودکار تایید شد؛ در مهلت بررسی کسی آن را تایید یا رد نکرد.', $payment->id)
            : sprintf('✅ رسید پرداخت #%d تایید شد.', $payment->id));
    }

    public function receiptRejected(Payment $payment, ?string $reason): void
    {
        $this->verdict($payment, sprintf('❌ رسید پرداخت #%d رد شد.', $payment->id), $reason);
    }

    public function receiptCancelled(Payment $payment, ?string $note): void
    {
        $this->verdict($payment, sprintf('🚫 پرداخت #%d لغو شد.', $payment->id), $note);
    }

    /**
     * An order was delivered: the sale, the renewal or the charged wallet, each in its topic — and, when its delivery had
     * failed before, that it was delivered after all, under the failure's report, whose retry button goes.
     */
    public function orderDelivered(Order $order): void
    {
        match ($order->type) {
            OrderType::Purchase => $this->report($order->shop(), Topic::Purchases, static fn(): array => ['text' => self::purchaseText($order)]),
            OrderType::Renewal => $this->report($order->shop(), Topic::Renewals, static fn(): array => ['text' => self::renewalText($order)]),
            OrderType::WalletTopUp => $this->report($order->shop(), Topic::Wallet, static fn(): array => ['text' => self::topUpText($order)]),
            // An agent bought traffic for their bot: the shop's own group, under the agency.
            OrderType::Traffic => $this->report(Bot::MAIN, Topic::Agency, static fn(): array => ['text' => self::trafficText($order)]),
        };

        $this->report($order->shop(), Topic::Errors, static fn(): ?array => ReportMessage::query()->where('ref', self::DELIVERY_REF . $order->id)->exists() ? [
            'text' => sprintf('✅ سفارش #%d با تلاش دوباره تحویل شد.', $order->id),
            'reply_ref' => self::DELIVERY_REF . $order->id,
            'clears_buttons' => true,
        ] : null);
    }

    /**
     * An order was paid but its delivery failed: why (failure()), with the bot admins' retry button (DeliveryRetry). A
     * failure after an earlier one is a reply to that one's report, which loses its button to this.
     */
    public function orderFailed(Order $order): void
    {
        $this->report($order->shop(), Topic::Errors, static function () use ($order): array {
            $lines = [
                sprintf('⚠️ <b>تحویل سفارش #%d ناموفق بود</b> (%s)', $order->id, $order->type->label()),
                '👤 ' . self::customer($order->user),
            ];
            $server = $order->type === OrderType::Renewal ? self::subscriptionOf($order)?->server : $order->server;
            if ($server !== null) {
                $lines[] = '📍 سرور: ' . self::name($server->name);
            }
            $lines[] = '❗️ علت: ' . self::field(self::failure($order) ?? 'نامشخص');
            $lines[] = 'پول این سفارش پرداخت شده است؛ وقتی مشکل برطرف شد، «تلاش دوباره برای تحویل» را بزنید — همین‌جا یا در صفحه «سفارش‌ها» در پنل.';

            return [
                'text' => implode("\n", $lines),
                'keyboard' => DeliveryRetry::buttons($order->id),
                'ref' => self::DELIVERY_REF . $order->id,
                'reply_ref' => self::DELIVERY_REF . $order->id,
                'clears_buttons' => true,
            ];
        });
    }

    /**
     * Why an order's delivery failed, as its shop's group reads it — this report, a retry's or an approval's popup: the
     * main bot's group is the owner's, which reads the diagnosis of a panel that failed it; an agent's, the words anyone
     * of the shop may read (the panels decide by who reads the screen: OrderDirectory::notes()).
     */
    public static function failure(Order $order): ?string
    {
        return $order->shop()->isMain() ? $order->diagnosis ?? $order->notes : $order->notes;
    }

    /**
     * A customer opened a ticket: who, about what — its subject, the service —, where from, and its first message (its
     * picture as the photo the report captions), with the bot admins' «بستن تیکت» (TicketClose). A bot admin's reply to it,
     * or to any of the ticket's reports, is support's answer (TicketReplies).
     */
    public function ticketOpened(Ticket $ticket, TicketMessage $message): void
    {
        $this->report($ticket->shop(), Topic::Tickets, static function () use ($ticket, $message): array {
            $lines = [
                sprintf('🎫 <b>تیکت جدید</b> · #%d', $ticket->id),
                '👤 ' . self::customer($ticket->user),
                '📌 موضوع: ' . self::field($ticket->subject),
            ];
            if ($ticket->subscription !== null) {
                $lines[] = '🔖 سرویس: <code>' . htmlspecialchars($ticket->subscription->remote_name) . '</code>';
            }
            $lines[] = '📨 ' . $message->channel->source();

            return self::ticketReport($ticket, $message, $lines, self::TICKET_REPLY) + ['keyboard' => TicketClose::buttons($ticket->id)];
        });
    }

    /**
     * Another message of a ticket — the customer's (one that opened a closed ticket again says so), or support's answer
     * written elsewhere than in the group — under the ticket's last report, which hands its «بستن تیکت» down to this one;
     * or, while that report still waits in the queue, in it (folded()).
     */
    public function ticketMessage(Ticket $ticket, TicketMessage $message, bool $reopened): void
    {
        $this->report($ticket->shop(), Topic::Tickets, static function () use ($ticket, $message, $reopened): array {
            $lines = [$message->author === TicketAuthor::Customer
                ? sprintf('💬 <b>پیام مشتری</b> · تیکت #%d · %s', $ticket->id, $message->channel->source())
                : sprintf('↩️ <b>پاسخ پشتیبانی</b> · تیکت #%d · %s', $ticket->id, $message->channel->source())];
            if ($reopened) {
                $lines[] = '🔓 تیکت دوباره باز شد.';
            }

            return self::ticketReport($ticket, $message, $lines, '') + ['keyboard' => TicketClose::buttons($ticket->id), 'reply_ref' => self::TICKET_REF . $ticket->id, 'clears_buttons' => true];
        }, fold: true);
    }

    /** The ticket was closed — by the customer, or by support —: under its last report, which loses its button. */
    public function ticketClosed(Ticket $ticket, bool $byCustomer): void
    {
        $this->ticketNews($ticket, sprintf('🔒 تیکت #%d بسته شد — %s.', $ticket->id, $byCustomer ? 'مشتری آن را بست' : 'پشتیبانی آن را بست'), false);
    }

    /** Support opened the closed ticket again: under its last report, «بستن تیکت» back under this one. */
    public function ticketReopened(Ticket $ticket): void
    {
        $this->ticketNews($ticket, sprintf('🔓 تیکت #%d دوباره باز شد.', $ticket->id), true);
    }

    /** The customer rated their closed ticket — a first rating, or one that changed (Tickets::rate()) —: under its last report. */
    public function ticketRated(Ticket $ticket): void
    {
        $this->ticketNews($ticket, implode("\n", [
            sprintf('⭐️ امتیاز مشتری به تیکت #%d: %s از ۵', $ticket->id, Persian::digits((int) $ticket->rating)),
            ...self::note(self::CUSTOMER_NOTE, $ticket->rating_note),
        ]), false);
    }

    /**
     * A review written on the shop's website, waiting on support: the name it is signed with, its stars, where they use
     * the service from, who wrote it — the customer signed in, or a guest — and its words, as many as a message holds
     * with the rest. No buttons: support decides it on the panel's «نظرات» page.
     */
    public function reviewWritten(Review $review): void
    {
        $this->report($review->shop(), Topic::Reviews, static function () use ($review): array {
            $lines = [
                sprintf('⭐️ <b>نظر جدید</b> · #%d', $review->id),
                '✍️ ' . self::name($review->name),
                sprintf('%s%s (%s از ۵)', str_repeat('★', $review->rating), str_repeat('☆', 5 - $review->rating), Persian::digits($review->rating)),
            ];
            if ($review->context !== null) {
                $lines[] = '📱 ' . self::field($review->context);
            }
            $lines[] = '👤 ' . ($review->user !== null ? self::customer($review->user) : 'مهمان، بدون ورود به حساب');
            $lines[] = '📨 از وب‌سایت';
            $head = implode("\n", $lines) . "\n\n";
            $room = Limits::MESSAGE - self::ROOM - TelegramHtml::visibleLength($head . self::REVIEW_PENDING);

            return ['text' => $head . htmlspecialchars(Limits::fit($review->body, $room)) . self::REVIEW_PENDING];
        });
    }

    /** A server's panel that answered stopped answering. */
    public function serverDown(Server $server, string $reason): void
    {
        $this->report(Bot::MAIN, Topic::Errors, static fn(): array => [
            'text' => sprintf("🔴 <b>پنل سرور «%s» جواب نمی‌دهد</b>\n❗️ علت: %s", self::name($server->name), self::field($reason)),
        ]);
    }

    /** …and answers again. */
    public function serverUp(Server $server): void
    {
        $this->report(Bot::MAIN, Topic::Errors, static fn(): array => [
            'text' => sprintf('🟢 <b>پنل سرور «%s» دوباره جواب می‌دهد</b>', self::name($server->name)),
        ]);
    }

    /**
     * A message of the group's own — a topic's introduction, the screen's test — queued whatever the topic's switch
     * says, while a group is connected.
     */
    public function notice(Topic $topic, string $text): void
    {
        if ($this->group->chatId() === null) {
            return;
        }

        ReportMessage::query()->create(['topic' => $topic, 'priority' => $topic->priority(), 'text' => $text]);
    }

    /**
     * A verdict on a receipt, as a reply to the receipt's report that takes its buttons off — whoever decided, here or
     * on the screen or by the timer; a payment that had no receipt has nothing to answer.
     */
    private function verdict(Payment $payment, string $text, ?string $note = null): void
    {
        if ($payment->receipt_at === null) {
            return;
        }

        $this->report($payment->shop(), Topic::Receipts, static fn(): array => [
            'text' => implode("\n", [$text, ...self::note(self::SUPPORT_NOTE, $note)]),
            'reply_ref' => self::RECEIPT_REF . $payment->id,
            'clears_buttons' => true,
        ]);
    }

    /**
     * A word about a ticket under its last report — which loses its «بستن تیکت», or hands it down to this one
     * (`$closable`, the ticket open again).
     */
    private function ticketNews(Ticket $ticket, string $text, bool $closable): void
    {
        $this->report($ticket->shop(), Topic::Tickets, static fn(): array => [
            'text' => $text,
            'ref' => self::TICKET_REF . $ticket->id,
            'ticket_id' => $ticket->id,
            'reply_ref' => self::TICKET_REF . $ticket->id,
            'clears_buttons' => true,
        ] + ($closable ? ['keyboard' => TicketClose::buttons($ticket->id)] : []));
    }

    /**
     * A report of one of a ticket's messages: `$lines` above its words — as many of them as Telegram takes with the rest
     * (a photo's caption holds a quarter of a message), cut short with «…»: the whole is the panels' — and `$footer` under
     * them; its picture, uploaded, as the photo the report captions; the ticket's `ref`, which every report of it carries.
     *
     * @param list<string> $lines
     * @return array<string, mixed>
     */
    private static function ticketReport(Ticket $ticket, TicketMessage $message, array $lines, string $footer): array
    {
        $photo = $message->attachment_path !== null ? CustomerPictures::reference(PictureFolder::Tickets, $message->attachment_path) : null;
        if ($message->attachment_file_id !== null) {
            $lines[] = '🖼 همراه یک تصویر؛ در پنل دیده می‌شود.';
        }
        $head = implode("\n", $lines) . "\n\n";
        $room = ($photo !== null ? Limits::CAPTION : Limits::MESSAGE) - self::ROOM - TelegramHtml::visibleLength($head . $footer);

        return [
            'text' => $head . htmlspecialchars(Limits::fit(trim($message->body), $room)) . $footer,
            'photo_path' => $photo,
            'ref' => self::TICKET_REF . $ticket->id,
            'ticket_id' => $ticket->id,
        ];
    }

    /**
     * Queue one report in the group of the bot it is about — a sale in an agent's bot goes to the agent's group, a
     * server's or the agency's to the shop's own: nothing while no group is connected or the admin switched the topic
     * off. Words that cannot be composed are logged, and the report is let go — never what it reports. The row is written
     * in a savepoint of the caller's transaction (its own transaction when there is none); what the database refuses —
     * there, or as the words read it — goes up to the caller, whose transaction it is.
     *
     * @param \Closure(): (array<string, mixed>|null) $compose The row's attributes (text, copy_*, keyboard, ref, reply_ref,
     *                                                         clears_buttons), or null when there is nothing to say after all
     * @param bool $fold A ticket's message: folded into the ticket's last report while that one still waits (folded())
     * @throws QueryException
     */
    private function report(Bot|int $bot, Topic $topic, \Closure $compose, bool $fold = false): void
    {
        CurrentBot::run($bot, function () use ($topic, $compose, $fold): void {
            if ($this->group->chatId() === null || !$this->settings->enabled($topic)) {
                return;
            }

            try {
                $attributes = $compose();
            } catch (QueryException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $this->logger->error('A report for the {topic} topic was not queued: {message}', ['topic' => $topic->value, 'message' => $e->getMessage(), 'exception' => $e]);

                return;
            }
            if ($attributes === null) {
                return;
            }

            $this->db->transaction(static function () use ($topic, $attributes, $fold): void {
                if (!$fold || !self::folded($attributes)) {
                    ReportMessage::query()->create(['topic' => $topic, 'priority' => $topic->priority()] + $attributes);
                }
            });
        });
    }

    /**
     * A ticket's message (its report's `$attributes`) folded into the ticket's last report while that one still waits in
     * the queue — no sender holds it, it carries no picture and has the close button, the two fit one message —: a burst
     * of a ticket's messages is one report, not a queue of them crowding the rest of the group's out. One conditional
     * update of the report as it was read: one sent, taken by a sender or changed meanwhile is left as it is, and the
     * message goes as a report of its own. False when it was not folded.
     *
     * @param array<string, mixed> $attributes
     */
    private static function folded(array $attributes): bool
    {
        if ($attributes['photo_path'] !== null) {
            return false;
        }

        $last = ReportMessage::query()->where('ticket_id', $attributes['ticket_id'])->latest('id')->first();
        if ($last === null || $last->sent_at !== null || $last->failed_at !== null || $last->lease_token !== null || $last->photo_path !== null || $last->keyboard !== $attributes['keyboard']) {
            return false;
        }
        $text = $last->text . "\n\n" . $attributes['text'];
        if (TelegramHtml::visibleLength($text) > Limits::MESSAGE - self::ROOM) {
            return false;
        }

        return ReportMessage::waiting()->whereKey($last->id)->whereNull(Lease::TOKEN)->where('text', $last->text)->update(['text' => $text]) === 1;
    }

    private static function purchaseText(Order $order): string
    {
        $subscription = self::subscriptionOf($order);
        $payment = self::paymentOf($order);
        $lines = [
            sprintf('🛍️ <b>خرید جدید</b> · سفارش #%d', $order->id),
            '👤 ' . self::customer($order->user),
            '📦 پلن: ' . self::name($order->plan->name ?? '—'),
            '📍 لوکیشن: ' . self::name(($subscription->server ?? $order->server)->name ?? '—'),
        ];
        if ($subscription !== null) {
            $lines[] = '🔖 سرویس: <code>' . htmlspecialchars($subscription->remote_name) . '</code>';
        }
        $lines[] = self::paid($order, $payment);

        return implode("\n", [...$lines, ...self::commission($payment)]);
    }

    private static function renewalText(Order $order): string
    {
        $subscription = self::subscriptionOf($order);
        $payment = self::paymentOf($order);
        $lines = [
            sprintf('♻️ <b>تمدید سرویس</b> · سفارش #%d', $order->id),
            '👤 ' . self::customer($order->user),
        ];
        if ($subscription !== null) {
            $lines[] = sprintf('🔖 سرویس: <code>%s</code> · %s · %s', htmlspecialchars($subscription->remote_name), self::name($order->plan->name ?? $subscription->plan->name ?? '—'), self::name($subscription->server->name));
            $lines[] = '📅 پایان: ' . Messages::expiry($subscription->expires_at, $subscription->duration_days);
        }
        $lines[] = self::paid($order, $payment);

        return implode("\n", [...$lines, ...self::commission($payment)]);
    }

    private static function trafficText(Order $order): string
    {
        $payment = self::paymentOf($order);
        $bot = $order->user->ownBot;
        $lines = [
            sprintf('💾 <b>خرید حجم نمایندگی</b> · سفارش #%d', $order->id),
            '👤 ' . self::customer($order->user),
            '📦 حجم: ' . Messages::bytes((int) $order->traffic_bytes),
            self::paid($order, $payment),
        ];
        if ($bot !== null) {
            $lines[] = '🗄 حجم باقی‌مانده نماینده: ' . Messages::bytes($bot->trafficBalance());
        }

        return implode("\n", $lines);
    }

    private static function topUpText(Order $order): string
    {
        $payment = self::paymentOf($order);
        $lines = [
            sprintf('💰 <b>شارژ کیف پول</b> · سفارش #%d', $order->id),
            '👤 ' . self::customer($order->user),
            self::paid($order, $payment),
            '👛 موجودی: ' . Money::format($order->user->balance()),
        ];

        return implode("\n", [...$lines, ...self::commission($payment)]);
    }

    /** "💳 مبلغ: ۱۲۰٬۰۰۰ تومان · کارت به کارت (ملت)" — what was paid and how. */
    private static function paid(Order $order, ?Payment $payment): string
    {
        $method = $payment?->method->label;

        return '💳 مبلغ: ' . Money::format($payment->amount ?? $order->amount) . ($method !== null ? ' · ' . self::name($method) : '');
    }

    /** @return list<string> The line saying what the payment earned the customer's referrer — whom it was credited to —, when it earned anything. */
    private static function commission(?Payment $payment): array
    {
        $commission = $payment === null ? null : ReferralCommission::query()->with('referrer')->where('payment_id', $payment->id)->first();
        if ($commission === null) {
            return [];
        }

        return [sprintf('👥 پورسانت معرف: %s برای %s', Money::format($commission->commission), self::customer($commission->referrer))];
    }

    /** What an order is for, on one line: the plan and the server, the service renewed, or the wallet. */
    private static function purpose(Order $order): string
    {
        return match ($order->type) {
            OrderType::Purchase => sprintf('📦 خرید %s · %s · سفارش #%d', self::name($order->plan->name ?? '—'), self::name($order->server->name ?? '—'), $order->id),
            OrderType::Renewal => sprintf('♻️ تمدید <code>%s</code> · سفارش #%d', htmlspecialchars(self::subscriptionOf($order)->remote_name ?? '—'), $order->id),
            OrderType::WalletTopUp => sprintf('💰 شارژ کیف پول · سفارش #%d', $order->id),
            OrderType::Traffic => sprintf('💾 خرید حجم نمایندگی (%s) · سفارش #%d', Messages::bytes((int) $order->traffic_bytes), $order->id),
        };
    }

    /**
     * A customer as the group reads them: the name (a link that opens their profile), the handle and the Telegram id —
     * three identifiers side by side, never one standing in for another. One without Telegram (who signed up on the
     * website) has no profile to open: the name alone, their email, and their number in the shop («#12», as the panel's
     * search finds them).
     */
    private static function customer(User $user): string
    {
        $name = self::name($user->name() ?? 'بدون نام');
        if ($user->telegram_id === null) {
            return sprintf('%s · %s · <code>#%d</code>', $name, $user->email !== null ? htmlspecialchars($user->email) : 'بدون ایمیل', $user->id);
        }
        $handle = $user->username !== null && $user->username !== '' ? '@' . htmlspecialchars($user->username) : 'بدون نام کاربری';

        return sprintf('<a href="tg://user?id=%d">%s</a> · %s · <code>%d</code>', $user->telegram_id, $name, $handle, $user->telegram_id);
    }

    /** The service the order delivered or renewed, read afresh: delivery has just written it. */
    private static function subscriptionOf(Order $order): ?Subscription
    {
        return $order->subscription_id === null ? null : Subscription::query()->with(['plan', 'server'])->find($order->subscription_id);
    }

    /** The payment that paid the order. */
    private static function paymentOf(Order $order): ?Payment
    {
        return Payment::query()->where('order_id', $order->id)->where('status', PaymentStatus::Paid->value)->latest('id')->first();
    }

    private static function name(string $name): string
    {
        return htmlspecialchars(mb_substr(trim($name), 0, self::NAME_MAX));
    }

    private static function field(string $text): string
    {
        $text = trim($text);

        return htmlspecialchars(mb_strlen($text) > self::FIELD_MAX ? mb_substr($text, 0, self::FIELD_MAX) . '…' : $text);
    }

    /** @return list<string> Someone's words on a line of their own, after whose they are (`$label`); none when there are none. */
    private static function note(string $label, ?string $text): array
    {
        $text = trim((string) $text);

        return $text === '' ? [] : [$label . self::field($text)];
    }
}
