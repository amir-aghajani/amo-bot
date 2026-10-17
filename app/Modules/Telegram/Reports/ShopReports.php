<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports;

use App\Core\Database\Lease;
use App\Modules\Agency\Enums\AgencyRequestStatus;
use App\Modules\Agency\Models\AgencyRequest;
use App\Modules\Auth\Actor;
use App\Modules\Auth\Services\Reviewers;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Enums\SellingSuspension;
use App\Modules\Bots\Models\Bot;
use App\Modules\Discounts\DTO\DiscountLine;
use App\Modules\Orders\Enums\OrderPricing;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Drivers\Manual\ManualGateway;
use App\Modules\Payments\GatewayRegistry;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\OnlinePayments;
use App\Modules\Providers\Models\Server;
use App\Modules\Referrals\Models\ReferralAlert;
use App\Modules\Referrals\Models\ReferralCommission;
use App\Modules\Reviews\Models\Review;
use App\Modules\Rewards\Models\LotteryDraw;
use App\Modules\Rewards\Models\LotteryWinner;
use App\Modules\Subscriptions\Enums\ExtraKind;
use App\Modules\Subscriptions\Enums\MigrationMode;
use App\Modules\Subscriptions\Enums\MigrationStatus;
use App\Modules\Subscriptions\Enums\RenewalMethod;
use App\Modules\Subscriptions\Models\ServerMigration;
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
use App\Modules\Users\Models\WalletTransfer;
use App\Modules\Users\Services\CustomerPictures;
use App\Support\Money;
use App\Support\Persian;
use App\Support\Text;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Psr\Log\LoggerInterface;

/**
 * Queues what the shop tells its admins' report group (report_messages, sent by ReportSender): sales, renewals,
 * top-ups and wallet transfers, receipts and their verdicts, newcomers, agency requests and agents' selling suspended
 * or resumed, failed deliveries, online payments held for support, panels down and up, servers' services moved, ticket
 * conversations and reviews. Each goes to the group of the bot it happened in (servers and the agency to the main
 * bot's), only while a group is connected and the admin wants the topic. Who of support did something is named as
 * anyone but the owner reads it (bySupport()). Nothing here talks to Telegram.
 *
 * A report is written in a savepoint of the caller's transaction, so it stands or falls with what it reports. Words
 * that cannot be composed are logged and dropped; a database error goes up to the caller. The wording is fixed here (the
 * admins read it, not customers). A row's number after Persian words has a left-to-right mark (U+200E) before it: bare,
 * «سفارش #12» reads «12# سفارش».
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
     * What `ref` every report of a ticket carries: each replies to the last and takes over its «بستن تیکت». Each also
     * names its ticket (`ticket_id`), so a reply to any of them answers the ticket (TicketReplies).
     */
    private const TICKET_REF = 'ticket:';

    /** Under a new ticket's report: how support answers it — here, or on the panel's «پشتیبانی» page. */
    private const TICKET_REPLY = "\n\n↩️ با ریپلای روی همین پیام (یا هر پیام بعدی این تیکت) پاسخ دهید — یا در صفحه «پشتیبانی» در پنل.";

    /** Under a review's report: where support decides it, and that the website shows it only then. */
    private const REVIEW_PENDING = "\n\n⏳ در انتظار بررسی؛ تا در صفحه «نظرات» پنل تایید نشود، در وب‌سایت دیده نمی‌شود.";

    /** A margin under Telegram's limit when a customer's words are cut to fit a report (a ticket's message, a review). */
    private const ROOM = 64;

    /** Whose words follow: the customer's (a receipt's caption, an agency request), support's (a verdict's reason). */
    private const CUSTOMER_NOTE = '📝 توضیح مشتری: ';
    private const SUPPORT_NOTE = '📝 توضیح پشتیبانی: ';

    public function __construct(
        private readonly ReportGroupState $group,
        private readonly ReportSettings $settings,
        private readonly GatewayRegistry $gateways,
        private readonly Reviewers $reviewers,
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

    /** A referrer whose referrals look made up (ReferralAlerts): who, why, and what is held back until support clears it. */
    public function referralAlert(ReferralAlert $alert, int $minutes): void
    {
        $this->report($alert->shop(), Topic::Users, static fn(): array => ['text' => implode("\n", [
            '⚠️ <b>هشدار زیرمجموعه‌گیری</b>',
            '👥 معرف: ' . self::customer($alert->referrer),
            '🔎 ' . $alert->reason->words(Persian::number($alert->count), Persian::number($minutes)),
            'تا این هشدار در صفحه «زیرمجموعه‌گیری» پنل رفع نشود، هدیه شروع و جایزه دعوت به این معرف داده نمی‌شود؛ پورسانت‌ها مثل قبل است.',
        ])]);
    }

    /** A customer asked to become an agent: what they wrote, with the bot admins' «تایید» / «رد» buttons (AgencyReview). */
    public function agencyRequested(AgencyRequest $request): void
    {
        $this->report(Bot::MAIN, Topic::Agency, static function () use ($request): array {
            $lines = [
                sprintf("🤝 <b>درخواست نمایندگی</b> · \u{200E}#%d", $request->id),
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
                ? sprintf("✅ درخواست نمایندگی \u{200E}#%d تایید شد: سطح «%s»، هر گیگابایت %s.", $request->id, self::name($level->name ?? '—'), Money::format($level->price_per_gb ?? '0'))
                : sprintf("❌ درخواست نمایندگی \u{200E}#%d رد شد.", $request->id);

            return [
                'text' => implode("\n", [$text, ...self::note(self::SUPPORT_NOTE, $request->reason)]),
                'reply_ref' => self::AGENCY_REF . $request->id,
                'clears_buttons' => true,
            ];
        });
    }

    /** An agent's bot sells nothing new now (Agency\Services\AgencyStanding): who, which bot, why and what lifts it. */
    public function agencySuspended(Bot $bot, SellingSuspension $reason): void
    {
        $this->report(Bot::MAIN, Topic::Agency, static fn(): array => ['text' => implode("\n", [
            '⛔️ <b>فروش نماینده متوقف شد</b>',
            ...self::agentLines($bot),
            sprintf('📌 %s؛ %s.', $reason->words(), $reason->remedy()),
            'ربات، پنل و سرویس‌های فعلی مشتری‌هایش کار می‌کنند.',
        ])]);
    }

    /** An agent's bot sells again. */
    public function agencyResumed(Bot $bot): void
    {
        $this->report(Bot::MAIN, Topic::Agency, static fn(): array => ['text' => implode("\n", ['✅ <b>فروش نماینده دوباره باز شد</b>', ...self::agentLines($bot)])]);
    }

    /** @return list<string> An agent's lines in an agency report: the agent as the group reads a customer, and their bot. */
    private static function agentLines(Bot $bot): array
    {
        $agent = $bot->agent;

        return [
            '👤 ' . ($agent === null ? '—' : self::customer($agent)),
            '🤖 ' . (($bot->username ?? '') !== '' ? '@' . htmlspecialchars((string) $bot->username) : 'ربات هنوز وصل نشده'),
        ];
    }

    /**
     * A card-to-card receipt: the customer's picture (copied from the bot, or the website's upload) captioned with what
     * it pays for, with «تایید» / «رد» (ReceiptReview); its verdict will reply to it.
     */
    public function receiptSubmitted(Payment $payment): void
    {
        $this->report($payment->shop(), Topic::Receipts, function () use ($payment): array {
            $method = $payment->method;
            $customer = $payment->order->user;
            $lines = [
                sprintf("🧾 <b>رسید جدید</b> · پرداخت \u{200E}#%d", $payment->id),
                '👤 ' . self::customer($customer),
                self::purpose($payment->order),
                sprintf('💳 مبلغ: %s · %s', Money::format($payment->amount), self::name($method->label)),
                // Which of the method's cards it was shown, or support's private chat: where to look for the money.
                ...match (true) {
                    $payment->card !== null => ['🏦 کارت: ' . self::field($payment->card->summary())],
                    $this->gateways->isManual($method) => ['🏦 ' . Payment::TO_SUPPORT],
                    default => [],
                },
                ...self::note(self::CUSTOMER_NOTE, $payment->receipt_note),
            ];
            // Only a receipt sent in the bot is approved on a timer; a website upload always waits for support.
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

    /**
     * An online gateway took the money of a payment whose order had closed meanwhile (paid another way, cancelled, or
     * expired): it went to the customer's wallet (Payments\Services\PaymentService::settleOnline()).
     */
    public function latePayment(Payment $payment): void
    {
        $this->report($payment->shop(), Topic::Wallet, static fn(): array => ['text' => implode("\n", [
            sprintf("💳 <b>پرداخت آنلاین پس از بسته شدن سفارش</b> · پرداخت \u{200E}#%d", $payment->id),
            '👤 ' . self::customer($payment->order->user),
            sprintf('💰 %s · %s', Money::format($payment->amount), self::name($payment->method->label)),
            sprintf("سفارش \u{200E}#%d پیش از رسیدن این پرداخت بسته شده بود؛ مبلغ به کیف پول مشتری رفت.", $payment->order_id),
            '👛 موجودی: ' . Money::format($payment->order->user->balance()),
        ])]);
    }

    /**
     * An online payment held for support (Payments\Services\OnlinePayments; its note one of PaymentService::HELD_NOTES):
     * its gateway answered what does not fit it, kept money on it unsettled too long, or closed it after saying money had
     * come in on it. Who, for what, how much on which method, its id at the gateway (`$gatewayId`), why — its note, then
     * what the gateway said (`$detail`, no secret in it) — and who of support asked, when it was their «استعلام از درگاه»
     * (`$by`).
     */
    public function heldPayment(Payment $payment, string $gatewayId, string $detail, ?string $by): void
    {
        $this->report($payment->shop(), Topic::Errors, fn(): array => ['text' => implode("\n", [
            sprintf("⚠️ <b>پرداخت آنلاین برای بررسی پشتیبانی نگه داشته شد</b> · پرداخت \u{200E}#%d", $payment->id),
            '👤 ' . self::customer($payment->order->user),
            self::purpose($payment->order),
            sprintf('💳 مبلغ: %s · %s', Money::format($payment->amount), self::name($payment->method->label)),
            '🔖 شناسه در درگاه: <code>' . self::field($gatewayId) . '</code>',
            '❗️ علت: ' . self::field((string) $payment->note),
            '🔎 پاسخ درگاه: ' . self::field($detail),
            ...($by === null ? [] : [$this->bySupport($by, 'استعلام توسط پشتیبانی')]),
            // Its order, still open, waits for support (Payments\Services\Checkout, PaymentService::expire()).
            ...(OnlinePayments::awaitsSupport($payment) ? ['تا پشتیبانی آن را در پنل درگاه بررسی نکند، سفارشش پرداخت دیگری نمی‌گیرد و خودکار لغو نمی‌شود؛ در صفحه «پرداخت‌ها» پنل می‌شود آن را لغو کرد.'] : []),
        ])]);
    }

    /** The receipt was accepted — by the admin, or by the timer when nobody looked inside the method's window. */
    public function receiptApproved(Payment $payment): void
    {
        $this->verdict($payment, $payment->wasAutoApproved()
            ? sprintf("✅ رسید پرداخت \u{200E}#%d خودکار تایید شد؛ در مهلت بررسی کسی آن را تایید یا رد نکرد.", $payment->id)
            : sprintf("✅ رسید پرداخت \u{200E}#%d تایید شد.", $payment->id));
    }

    /** The receipt was rejected, with support's reason. */
    public function receiptRejected(Payment $payment, ?string $reason): void
    {
        $this->verdict($payment, sprintf("❌ رسید پرداخت \u{200E}#%d رد شد.", $payment->id), $reason);
    }

    /** The payment was cancelled, with support's note. */
    public function receiptCancelled(Payment $payment, ?string $note): void
    {
        $this->verdict($payment, sprintf("🚫 پرداخت \u{200E}#%d لغو شد.", $payment->id), $note);
    }

    /**
     * Support dismissed `$count` receipts waiting for review at once (Payments\Services\ReceiptDismissals), each
     * refused under its own report already: one line saying how many and who, with their note.
     */
    public function receiptsDismissed(int $count, Actor $by, ?string $note): void
    {
        $this->report(CurrentBot::id(), Topic::Receipts, fn(): array => ['text' => implode("\n", [
            sprintf('🗑 <b>%s رسید در انتظار بررسی یکجا رد شد</b>', Persian::number($count)),
            $this->bySupport($by->reviewer),
            ...self::note(self::SUPPORT_NOTE, $note),
        ])]);
    }

    /**
     * An order was delivered: reported in its topic and, when its delivery had failed before, a word under the failure's
     * report, whose retry button goes.
     */
    public function orderDelivered(Order $order): void
    {
        match ($order->type) {
            OrderType::Purchase => $this->report($order->shop(), Topic::Purchases, fn(): array => ['text' => $this->purchaseText($order)]),
            OrderType::Renewal => $this->report($order->shop(), Topic::Renewals, static fn(): array => ['text' => self::renewalText($order)]),
            OrderType::ExtraTraffic, OrderType::ExtraTime, OrderType::ExtraDevices => $this->report($order->shop(), Topic::Renewals, static fn(): array => ['text' => self::extraText($order)]),
            OrderType::WalletTopUp => $this->report($order->shop(), Topic::Wallet, static fn(): array => ['text' => self::topUpText($order)]),
            // An agent bought traffic for their bot: the main bot's group, under the agency.
            OrderType::Traffic => $this->report(Bot::MAIN, Topic::Agency, static fn(): array => ['text' => self::trafficText($order)]),
        };

        $this->report($order->shop(), Topic::Errors, static fn(): ?array => ReportMessage::query()->where('ref', self::DELIVERY_REF . $order->id)->exists() ? [
            'text' => sprintf("✅ سفارش \u{200E}#%d با تلاش دوباره تحویل شد.", $order->id),
            'reply_ref' => self::DELIVERY_REF . $order->id,
            'clears_buttons' => true,
        ] : null);
    }

    /**
     * A customer added a service to their account by sending its link (ServiceClaims): who, which client and where — so
     * a service claimed by the wrong customer is noticed, and support removes it from the shop for the right one.
     */
    public function serviceClaimed(Subscription $subscription): void
    {
        $this->report($subscription->shop(), Topic::Purchases, static fn(): array => ['text' => implode("\n", [
            sprintf("🔗 <b>سرویس با لینک به حساب مشتری اضافه شد</b> · اشتراک \u{200E}#%d", $subscription->id),
            '👤 ' . self::customer($subscription->user),
            ...self::heldService($subscription),
        ])]);
    }

    /**
     * A service changed hands (ServiceTransfers): from whom to whom, which client and where, and who did it — its customer,
     * or support (bySupport()) — in «خریدها», the topic of who holds which service, so a transfer nobody expected is noticed.
     */
    public function serviceTransferred(Subscription $subscription, User $from, Actor $by): void
    {
        $this->report($subscription->shop(), Topic::Purchases, fn(): array => ['text' => implode("\n", [
            sprintf("🔁 <b>سرویس به مشتری دیگری منتقل شد</b> · اشتراک \u{200E}#%d", $subscription->id),
            '👤 از: ' . self::customer($from),
            '👤 به: ' . self::customer($subscription->user),
            ...self::heldService($subscription),
            $by->isCustomer() ? '✋ به درخواست خود مشتری' : $this->bySupport($by->reviewer),
        ])]);
    }

    /**
     * «انتقال موجودی» (Users\Services\WalletTransfers): who sent how much to whom, and both balances after it, in «شارژ کیف
     * پول» beside the top-ups — money moved between customers is noticed where money comes in.
     */
    public function walletTransferred(WalletTransfer $transfer): void
    {
        $this->report($transfer->shop(), Topic::Wallet, static fn(): array => ['text' => implode("\n", [
            sprintf("💸 <b>انتقال موجودی بین مشتری‌ها</b> · انتقال \u{200E}#%d", $transfer->id),
            '👤 از: ' . self::customer($transfer->sender),
            '👤 به: ' . self::customer($transfer->recipient),
            '💳 مبلغ: ' . Money::format($transfer->amount),
            sprintf('👛 موجودی فرستنده: %s · گیرنده: %s', Money::format($transfer->sender->balance()), Money::format($transfer->recipient->balance())),
        ])]);
    }

    /**
     * Which service a report of its new owner is about (serviceClaimed(), serviceTransferred()): its client's name and its
     * location.
     *
     * @return list<string>
     */
    private static function heldService(Subscription $subscription): array
    {
        return [self::service($subscription), self::location($subscription->server)];
    }

    /** The line naming a service — «🔖 سرویس: <code>amir_1</code>» —, with `$more` after it («· یک‌ماهه · آلمان»). */
    private static function service(Subscription $subscription, string ...$more): string
    {
        return implode(' · ', ['🔖 سرویس: <code>' . htmlspecialchars($subscription->remote_name) . '</code>', ...$more]);
    }

    /** The line naming where a service is: «📍 لوکیشن: آلمان». */
    private static function location(?Server $server): string
    {
        return '📍 لوکیشن: ' . self::name($server->name ?? '—');
    }

    /**
     * A paid order's delivery failed: why (failure()), with the retry button (DeliveryRetry). A repeated failure replies
     * to the earlier report, which loses its button.
     */
    public function orderFailed(Order $order): void
    {
        $this->report($order->shop(), Topic::Errors, static function () use ($order): array {
            $lines = [
                sprintf("⚠️ <b>تحویل سفارش \u{200E}#%d ناموفق بود</b> (%s)", $order->id, $order->type->label()),
                '👤 ' . self::customer($order->user),
            ];
            $server = $order->type->forService() ? self::subscriptionOf($order)?->server : $order->server;
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
     * Why an order's delivery failed, as its group may read it: the main bot's group is the owner's and gets the panel's
     * diagnosis; an agent's group gets the plain summary.
     */
    public static function failure(Order $order): ?string
    {
        return $order->shop()->isMain() ? $order->diagnosis ?? $order->notes : $order->notes;
    }

    /** A ticket opened: who, its subject and service, where from, and its first message, with «بستن تیکت» (TicketClose). */
    public function ticketOpened(Ticket $ticket, TicketMessage $message): void
    {
        $this->report($ticket->shop(), Topic::Tickets, static function () use ($ticket, $message): array {
            $lines = [
                sprintf("🎫 <b>تیکت جدید</b> · \u{200E}#%d", $ticket->id),
                '👤 ' . self::customer($ticket->user),
                '📌 موضوع: ' . self::field($ticket->subject),
            ];
            if ($ticket->subscription !== null) {
                $lines[] = self::service($ticket->subscription);
            }
            $lines[] = '📨 ' . $message->channel->source();

            return self::ticketReport($ticket, $message, $lines, self::TICKET_REPLY) + ['keyboard' => TicketClose::buttons($ticket->id)];
        });
    }

    /**
     * Another message of a ticket (the customer's, or support's from elsewhere than the group) as a reply to its last
     * report — or folded into that report while it still waits in the queue (folded()).
     */
    public function ticketMessage(Ticket $ticket, TicketMessage $message, bool $reopened): void
    {
        $this->report($ticket->shop(), Topic::Tickets, static function () use ($ticket, $message, $reopened): array {
            $lines = [$message->author === TicketAuthor::Customer
                ? sprintf("💬 <b>پیام مشتری</b> · تیکت \u{200E}#%d · %s", $ticket->id, $message->channel->source())
                : sprintf("↩️ <b>پاسخ پشتیبانی</b> · تیکت \u{200E}#%d · %s", $ticket->id, $message->channel->source())];
            if ($reopened) {
                $lines[] = '🔓 تیکت دوباره باز شد.';
            }

            return self::ticketReport($ticket, $message, $lines, '') + ['keyboard' => TicketClose::buttons($ticket->id), 'reply_ref' => self::TICKET_REF . $ticket->id, 'clears_buttons' => true];
        }, fold: true);
    }

    /** The ticket was closed (by the customer or by support), under its last report, which loses its button. */
    public function ticketClosed(Ticket $ticket, bool $byCustomer): void
    {
        $this->ticketNews($ticket, sprintf("🔒 تیکت \u{200E}#%d بسته شد — %s.", $ticket->id, $byCustomer ? 'مشتری آن را بست' : 'پشتیبانی آن را بست'), false);
    }

    /** Support opened the closed ticket again: under its last report, «بستن تیکت» back under this one. */
    public function ticketReopened(Ticket $ticket): void
    {
        $this->ticketNews($ticket, sprintf("🔓 تیکت \u{200E}#%d دوباره باز شد.", $ticket->id), true);
    }

    /** The customer rated their closed ticket (a first rating, or a changed one), under its last report. */
    public function ticketRated(Ticket $ticket): void
    {
        $this->ticketNews($ticket, implode("\n", [
            sprintf("⭐️ امتیاز مشتری به تیکت \u{200E}#%d: %s از ۵", $ticket->id, Persian::digits((int) $ticket->rating)),
            ...self::note(self::CUSTOMER_NOTE, $ticket->rating_note),
        ]), false);
    }

    /** A review written on the website, waiting on support (decided on the panel's «نظرات» page, so no buttons). */
    public function reviewWritten(Review $review): void
    {
        $this->report($review->shop(), Topic::Reviews, static function () use ($review): array {
            $lines = [
                sprintf("⭐️ <b>نظر جدید</b> · \u{200E}#%d", $review->id),
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

    /**
     * The nightly draw among buyers («قرعه‌کشی شبانه»), under «خریدها»: its entries, its prize and its winners.
     *
     * @param list<LotteryWinner> $winners Their customers loaded
     */
    public function lotteryDrawn(LotteryDraw $draw, array $winners): void
    {
        $this->report($draw->shop(), Topic::Purchases, static function () use ($draw, $winners): array {
            $lines = [
                '🎲 <b>قرعه‌کشی شبانه</b> · ' . Persian::date($draw->created_at),
                '🎟 شانس‌ها: ' . Persian::number($draw->entries),
                '🎁 جایزه: ' . self::name($draw->reward()?->words() ?? $draw->prize_kind->label()),
            ];
            if ($winners === []) {
                $lines[] = '⚠️ جایزه به هیچ‌کدام از شرکت‌کننده‌ها داده نشد؛ جزئیات در لاگ برنامه است.';
            } else {
                $lines[] = '🏆 برنده‌ها:';
                foreach ($winners as $winner) {
                    $lines[] = '• ' . self::customer($winner->user);
                }
            }

            return ['text' => implode("\n", $lines)];
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
     * The owner began moving a server's services to another («انتقال سرویس‌ها», ServerMigrations): from where to where,
     * how, how many, and by whom.
     */
    public function migrationStarted(ServerMigration $job): void
    {
        $this->report(Bot::MAIN, Topic::Errors, fn(): array => ['text' => implode("\n", [
            sprintf('🚚 <b>انتقال سرویس‌های سرور «%s» به سرور «%s» شروع شد</b>', self::name($job->source->name ?? '—'), self::name($job->target->name ?? '—')),
            '🧭 حالت: ' . $job->mode->label() . ($job->mode === MigrationMode::Rescue ? ' (بدون تماس با پنل سرور مبدا)' : ''),
            '📦 سرویس‌ها: ' . Persian::number($job->total),
            $this->bySupport($job->reviewer),
        ])]);
    }

    /**
     * …and it ended: through every service, left unfinished because the target could take no more (and why), or
     * cancelled — with how many moved, failed and were passed by, and the clients it left on a panel still there (as
     * the server's card counts them).
     */
    public function migrationEnded(ServerMigration $job): void
    {
        $this->report(Bot::MAIN, Topic::Errors, static function () use ($job): array {
            $left = $job->leftovers()->count();
            [$icon, $end] = match ($job->status) {
                MigrationStatus::Done => ['✅', 'تمام شد'],
                MigrationStatus::Failed => ['⛔️', 'ناتمام ماند'],
                default => ['⏹', 'لغو شد'],
            };

            return ['text' => implode("\n", [
                sprintf('%s <b>انتقال سرویس‌های سرور «%s» به سرور «%s» %s</b>', $icon, self::name($job->source->name ?? '—'), self::name($job->target->name ?? '—'), $end),
                sprintf('📊 منتقل‌شده: %s · ناموفق: %s · ردشده: %s', Persian::number($job->moved), Persian::number($job->failed), Persian::number($job->skipped)),
                ...($job->status === MigrationStatus::Failed ? ['❗️ علت: ' . self::field((string) $job->reason)] : []),
                ...($left > 0 ? [sprintf('🧹 %s کلاینت روی پنل مانده است؛ وقتی پنل جواب بدهد، خودکار حذف می‌شوند.', Persian::number($left))] : []),
            ])];
        });
    }

    /** A message of the group's own (a topic's introduction, the screen's test), queued whatever the topic's switch says. */
    public function notice(Topic $topic, string $text): void
    {
        if ($this->group->chatId() === null) {
            return;
        }

        ReportMessage::query()->create(['topic' => $topic, 'priority' => $topic->priority(), 'text' => $text]);
    }

    /** A receipt's verdict, replying to its report and taking its buttons off; nothing for a payment without a receipt. */
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

    /** A word about a ticket under its last report, carrying «بستن تیکت» on when `$closable` (the ticket is open). */
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
     * A report of one ticket message: `$lines`, then its text cut to the room left (a caption holds a quarter of a
     * message), then `$footer`; an uploaded picture goes as the report's photo.
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
     * Queue one report in `$bot`'s group, unless none is connected or the topic is switched off. Words that cannot be
     * composed are logged and the report dropped — never what it reports. The row goes in a savepoint of the caller's
     * transaction; a database error goes up to the caller, whose transaction it is.
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
     * Fold a ticket message's report into the ticket's last report while that one still waits unsent and unheld, has no
     * picture, the same buttons, and room — so a burst of messages is one report, not a queue crowding out the rest.
     * One conditional update of the report as read: one taken or changed meanwhile is left, and false is returned.
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

    private function purchaseText(Order $order): string
    {
        $subscription = self::subscriptionOf($order);
        $lines = [
            sprintf("🛍️ <b>خرید جدید</b> · سفارش \u{200E}#%d", $order->id),
            '👤 ' . self::customer($order->user),
            // A service the customer built has no plan: its GB and days say what it is.
            ($order->isBuilt() ? '📦 ' : '📦 پلن: ') . self::name($order->packageName() ?? '—'),
            self::location($subscription->server ?? $order->server),
        ];
        if ($subscription !== null) {
            $lines[] = self::service($subscription);
        }

        return implode("\n", [...$lines, ...self::takings($order), ...$this->madeBySupport($order)]);
    }

    /**
     * @return list<string> Under an order support made by hand for its customer (Orders\Services\ManualOrders): who made it
     *                      (bySupport()) and how it was paid, then their note; nothing for an order the customer made
     */
    private function madeBySupport(Order $order): array
    {
        $payment = $order->manualPayment();
        if ($payment === null) {
            return [];
        }

        return [
            $this->bySupport($order->created_by, 'ثبت دستی توسط پشتیبانی') . ' · ' . $payment->label(),
            ...self::note(self::SUPPORT_NOTE, $order->support_note),
        ];
    }

    /**
     * «🎧 توسط پشتیبانی» — what support did, and who of them: an admin or an agent by name, the owner (or nobody's name)
     * as plain «پشتیبانی» (Reviewers::shared()), so the owner's panel login reaches no group. The admin («@sara»,
     * «tg:…», «user#…», «agent#…») comes after a left-to-right mark: after the Persian words a handle would read «sara@».
     */
    private function bySupport(?string $reviewer, string $did = 'توسط پشتیبانی'): string
    {
        $by = $this->reviewers->shared($reviewer) ?? Reviewers::SUPPORT;

        return '🎧 ' . $did . ($by === Reviewers::SUPPORT ? '' : ": \u{200E}" . self::name($by));
    }

    /** A renewal: the service renewed, its new end — and its method, unless the usual «تمدید پلن فعلی». */
    private static function renewalText(Order $order): string
    {
        $subscription = self::subscriptionOf($order);
        $method = $order->renewal_method;
        $lines = [
            sprintf("♻️ <b>تمدید سرویس</b> · سفارش \u{200E}#%d", $order->id) . ($method === null || $method === RenewalMethod::Extend ? '' : ' · ' . $method->label()),
            '👤 ' . self::customer($order->user),
        ];
        if ($subscription !== null) {
            $lines[] = self::service($subscription, self::name($order->packageName() ?? $subscription->plan->name ?? '—'), self::name($subscription->server->name));
            $lines[] = '📅 پایان: ' . Messages::expiry($subscription->expires_at, $subscription->duration_hours);
        }

        return implode("\n", [...$lines, ...self::takings($order)]);
    }

    /** Extra traffic, time or devices bought for a service: what, onto which service, and the service now. */
    private static function extraText(Order $order): string
    {
        $subscription = self::subscriptionOf($order);
        $lines = [
            sprintf("%s <b>%s</b> · سفارش \u{200E}#%d", self::extraIcon($order), $order->type->label(), $order->id),
            '👤 ' . self::customer($order->user),
        ];
        if ($subscription !== null) {
            $lines[] = self::service($subscription, self::name($subscription->server->name));
        }
        $lines[] = '➕ ' . self::quantity($order);
        if ($subscription !== null) {
            $remaining = $subscription->remainingBytes();
            $lines[] = '📥 حجم باقی‌مانده: ' . ($remaining === null ? Messages::UNLIMITED : Messages::bytes($remaining));
            $lines[] = '📅 پایان: ' . Messages::expiry($subscription->expires_at, $subscription->duration_hours);
            if ($order->type === OrderType::ExtraDevices) {
                $lines[] = '👥 تعداد کاربر: ' . Messages::devices($subscription->ip_limit);
            }
        }

        return implode("\n", [...$lines, ...self::takings($order, pricing: false)]);
    }

    /**
     * @return list<string> What was paid and how (with the price it was made at, unless `$pricing` is false), the
     *                      discounts, and the referrer's commission
     */
    private static function takings(Order $order, bool $pricing = true): array
    {
        $payment = self::paymentOf($order);

        return [self::paid($order, $payment) . ($pricing ? self::pricing($order) : ''), ...self::discounts($order), ...self::commission($payment)];
    }

    /** How much an order of extra traffic, time or devices brings, in words: «۱۰ گیگابایت», «۷ روز», «۲ کاربر». */
    private static function quantity(Order $order): string
    {
        $kind = ExtraKind::of($order->type) ?? throw new \LogicException("Order #{$order->id} buys no extra.");

        return $kind->words($kind->quantityOf($order));
    }

    private static function extraIcon(Order $order): string
    {
        return match ($order->type) {
            OrderType::ExtraTraffic => '🔋',
            OrderType::ExtraDevices => '👥',
            default => '⏳',
        };
    }

    private static function trafficText(Order $order): string
    {
        $payment = self::paymentOf($order);
        $bot = $order->user->ownBot;
        $lines = [
            sprintf("💾 <b>خرید حجم نمایندگی</b> · سفارش \u{200E}#%d", $order->id),
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
            sprintf("💰 <b>شارژ کیف پول</b> · سفارش \u{200E}#%d", $order->id),
            '👤 ' . self::customer($order->user),
            self::paid($order, $payment),
            '👛 موجودی: ' . Money::format($order->user->balance()),
        ];

        return implode("\n", [...$lines, ...self::commission($payment)]);
    }

    /** « · 🎁 قیمت خرید اول» or « · 🤝 قیمت نماینده» for an order made at another price than the plan's; '' otherwise. */
    private static function pricing(Order $order): string
    {
        $label = $order->pricing->label();

        return $label === null ? '' : ' · ' . ($order->pricing === OrderPricing::FirstPurchase ? '🎁 ' : '🤝 ') . $label;
    }

    /**
     * «🏷 تخفیف: ۲۴٬۰۰۰ تومان · فروش ویژه نوروز ۲۰٪ · کد NOWRUZ»: what the order's discounts took off; none without any.
     *
     * @return list<string>
     */
    private static function discounts(Order $order): array
    {
        $lines = $order->discountLines();
        if ($lines === []) {
            return [];
        }

        return ['🏷 تخفیف: ' . Money::format($order->discount_amount) . ' · ' . implode(' · ', array_map(static fn(DiscountLine $line): string => self::name($line->label), $lines))];
    }

    /** "💳 مبلغ: ۱۲۰٬۰۰۰ تومان · کارت به کارت (ملت)" — what was paid and how. */
    private static function paid(Order $order, ?Payment $payment): string
    {
        $method = $payment?->method->label;

        return '💳 مبلغ: ' . Money::format($payment->amount ?? $order->amount) . ($method !== null ? ' · ' . self::name($method) : '');
    }

    /** @return list<string> The line saying what the payment earned the customer's referrer, when it earned anything. */
    private static function commission(?Payment $payment): array
    {
        $commission = $payment === null ? null : ReferralCommission::query()->with('referrer')->where('payment_id', $payment->id)->first();
        if ($commission === null) {
            return [];
        }

        return [sprintf('👥 پورسانت معرف: %s برای %s', Money::format($commission->commission), self::customer($commission->referrer))];
    }

    /** What an order is for, on one line: the plan and the server, the service renewed or given extras, or the wallet. */
    private static function purpose(Order $order): string
    {
        return match ($order->type) {
            OrderType::Purchase => sprintf("📦 خرید %s · %s · سفارش \u{200E}#%d", self::name($order->packageName() ?? '—'), self::name($order->server->name ?? '—'), $order->id),
            OrderType::Renewal => sprintf("♻️ تمدید <code>%s</code> · سفارش \u{200E}#%d", htmlspecialchars(self::subscriptionOf($order)->remote_name ?? '—'), $order->id),
            OrderType::ExtraTraffic, OrderType::ExtraTime, OrderType::ExtraDevices => sprintf("%s %s %s برای <code>%s</code> · سفارش \u{200E}#%d", self::extraIcon($order), $order->type->label(), self::quantity($order), htmlspecialchars(self::subscriptionOf($order)->remote_name ?? '—'), $order->id),
            OrderType::WalletTopUp => sprintf("💰 شارژ کیف پول · سفارش \u{200E}#%d", $order->id),
            OrderType::Traffic => sprintf("💾 خرید حجم نمایندگی (%s) · سفارش \u{200E}#%d", Messages::bytes((int) $order->traffic_bytes), $order->id),
        };
    }

    /**
     * A customer as the group reads them: the name (a profile link), the handle and the Telegram id side by side. One
     * without Telegram shows the name, their email and their number in the shop («#12»). The handle comes after the name,
     * a left-to-right mark before it as before the number: after a Persian name «@sara» would read «sara@».
     */
    private static function customer(User $user): string
    {
        $name = self::name($user->name() ?? 'بدون نام');
        if ($user->telegram_id === null) {
            return sprintf("%s · %s · \u{200E}<code>#%d</code>", $name, $user->email !== null ? htmlspecialchars($user->email) : 'بدون ایمیل', $user->id);
        }
        $handle = $user->handle() === null ? 'بدون نام کاربری' : "\u{200E}@" . htmlspecialchars($user->handle());

        return sprintf('<a href="tg://user?id=%d">%s</a> · %s · <code>%d</code>', $user->telegram_id, $name, $handle, $user->telegram_id);
    }

    /** The service the order delivered or renewed, read afresh: delivery has just written it. */
    private static function subscriptionOf(Order $order): ?Subscription
    {
        return $order->subscription_id === null ? null : Subscription::query()->with(['plan', 'server'])->find($order->subscription_id);
    }

    /** The payment that paid the order — never one an online gateway took after it closed, which went to the wallet. */
    private static function paymentOf(Order $order): ?Payment
    {
        return $order->load('payments')->paidBy();
    }

    private static function name(string $name): string
    {
        return htmlspecialchars(mb_substr(trim($name), 0, self::NAME_MAX));
    }

    private static function field(string $text): string
    {
        return htmlspecialchars(Text::fit(trim($text), self::FIELD_MAX));
    }

    /** @return list<string> Someone's words on a line of their own, after whose they are (`$label`); none when there are none. */
    private static function note(string $label, ?string $text): array
    {
        $text = trim((string) $text);

        return $text === '' ? [] : [$label . self::field($text)];
    }
}
