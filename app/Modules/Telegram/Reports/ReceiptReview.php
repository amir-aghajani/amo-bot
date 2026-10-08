<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports;

use App\Core\Exceptions\ValidationException;
use App\Modules\Auth\Actor;
use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\OrderNotPayableException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentActions;
use App\Modules\Payments\Services\PaymentDirectory;
use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Keyboard\InlineKeyboard;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Telegram\Update\GroupHandler;
use App\Modules\Telegram\Update\Update;
use App\Modules\Users\Models\User;
use App\Support\Input;
use Psr\Log\LoggerInterface;

/**
 * The buttons under a receipt in the report group — «✅ تایید» and «❌ رد» — for the bot's admins only (GroupButtons::
 * admin()): they do what the payments screen does, through PaymentActions — its rules (a receipt decided here, on the
 * screen or by the timer is not decided again; an admin's own receipt is another admin's to approve), the customer told
 * by it, the admin's account the one who decided (Auth\Actor::groupAdmin()). «رد» asks first: without a reason, or with one the admin writes as a reply to the bot's prompt (a ForceReply), which
 * the customer reads as support's note. The prompt is recorded as the bot posts it (ReportMessage::posted(): the group,
 * its message, the payment it asks about), and a reply is a reason only when it answers that very message, in the
 * receipts topic — never by what the replied message's words say: customers' words are in the group's reports (a
 * ticket's, their names, an agency request), and would name another customer's receipt. A decided receipt loses its
 * buttons — here at once, and by its verdict's report (ReportSender) whoever decided.
 */
final class ReceiptReview implements GroupHandler
{
    /** What the buttons' callback data starts with: `rv:<action>:<payment id>`. */
    public const PREFIX = 'rv:';

    private const APPROVE = 'ok';
    private const REJECT = 'no';
    private const REJECT_PLAIN = 'nx';
    private const REJECT_WITH_REASON = 'nw';
    private const BACK = 'bk';

    /** What `ref` the record of a prompt for a reason carries: `prompt:<payment id>`, the payment it asks about. */
    private const PROMPT_REF = 'prompt:';
    private const PROMPT = '✍️ دلیل رد رسید پرداخت #%d را در پاسخ به همین پیام بنویسید؛ مشتری آن را به عنوان توضیح پشتیبانی می‌بیند.%s';

    private const NOT_ADMIN = 'فقط مدیرهای ربات می‌توانند رسید را تایید یا رد کنند؛ نقش «مدیر ربات» در صفحه کاربران پنل داده می‌شود.';
    private const NOT_FOUND = 'این پرداخت پیدا نشد.';
    private const APPROVED = '✅ رسید تایید شد.';
    /** The retry first: Telegram cuts a popup at 200 characters, and the reason is in the errors topic in full. */
    private const APPROVED_UNDELIVERED = 'رسید تایید شد ولی تحویل ناموفق بود؛ «تلاش دوباره برای تحویل» در تاپیک «خطاها» است. علت: %s';
    private const REJECTED = '❌ رسید رد شد.';
    private const WRITE_REASON = 'دلیل را در پاسخ به پیام ربات بنویسید.';
    private const TEXT_ONLY = 'دلیل رد را به صورت متن بنویسید.';

    public function __construct(
        private readonly BotApi $api,
        private readonly GroupButtons $groupButtons,
        private readonly ReportTopics $topics,
        private readonly PaymentActions $actions,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * The two buttons under a receipt that waits for review — «تایید» on a Persian reader's right (Telegram lays a row
     * out left to right).
     *
     * @return list<list<array<string, mixed>>>
     */
    public static function buttons(int $paymentId): array
    {
        return InlineKeyboard::make()
            ->row(InlineKeyboard::callback('❌ رد', self::data(self::REJECT, $paymentId), 'danger'), InlineKeyboard::callback('✅ تایید', self::data(self::APPROVE, $paymentId), 'success'))
            ->build()['inline_keyboard'];
    }

    /** A press on one of the buttons, from a group. */
    public function handle(Update $update): void
    {
        $callbackId = (string) $update->callbackId();
        $args = $update->callbackArgs(self::PREFIX) ?? [];
        if (count($args) !== 2 || !in_array($args[0], [self::APPROVE, self::REJECT, self::REJECT_PLAIN, self::REJECT_WITH_REASON, self::BACK], true)) {
            $this->api->answerCallbackQuery($callbackId);

            return;
        }
        [$action, $paymentId] = $args;

        $admin = GroupButtons::admin($update->from());
        if ($admin === null) {
            $this->api->answerCallbackQuery($callbackId, self::NOT_ADMIN, alert: true);

            return;
        }

        $chatId = (int) $update->chatId();
        $messageId = (int) $update->messageId();
        $payment = self::find((int) $paymentId);
        if ($payment === null) {
            $this->groupButtons->set($chatId, $messageId, null);
            $this->api->answerCallbackQuery($callbackId, self::NOT_FOUND, alert: true);

            return;
        }

        match ($action) {
            self::APPROVE => $this->approve($callbackId, $chatId, $messageId, $payment, $admin),
            self::REJECT_PLAIN => $this->reject($callbackId, $chatId, $messageId, $payment, $admin),
            self::REJECT_WITH_REASON => $this->askReason($update, $payment, $admin),
            // «رد» and «بازگشت» only change the buttons, while there is still something to decide.
            default => $this->showButtons($callbackId, $chatId, $messageId, $payment, $action === self::REJECT ? self::rejectButtons($payment->id) : self::buttons($payment->id)),
        };
    }

    /**
     * A message in the receipts topic that replies to the bot's prompt for a reason — that very message, as the bot
     * recorded it —: the receipt is rejected with it, as the payments screen's note. False when the message is not such a
     * reply (it is none of this class's business).
     */
    public function reason(Update $update): bool
    {
        $message = $update->raw['message'] ?? null;
        $prompt = is_array($message) ? ($message['reply_to_message'] ?? null) : null;
        if (!is_array($message) || !is_array($prompt) || (int) ($prompt['from']['id'] ?? 0) !== $this->api->botId()) {
            return false;
        }

        $chatId = (int) $update->chatId();
        $promptId = (int) ($prompt['message_id'] ?? 0);
        $paymentId = $this->topics->holds(Topic::Receipts, $chatId, $message) ? self::promptedPayment($chatId, $promptId) : null;
        if ($paymentId === null) {
            return false;
        }

        $reply = fn(string $text) => $this->groupButtons->say($chatId, (int) ($message['message_id'] ?? 0), GroupButtons::threadOf($message), $text);

        // An admin writing as the group (anonymous) has no account to authorise.
        if (isset($message['sender_chat'])) {
            $reply(GroupButtons::ANONYMOUS);

            return true;
        }
        $admin = GroupButtons::admin($update->from());
        if ($admin === null) {
            $reply(self::NOT_ADMIN);

            return true;
        }
        if (trim((string) ($message['text'] ?? '')) === '') {
            $reply(self::TEXT_ONLY);

            return true;
        }

        $payment = self::find($paymentId);
        if ($payment === null) {
            $reply(self::NOT_FOUND);
            $this->api->deleteMessage($chatId, $promptId);

            return true;
        }

        try {
            $this->actions->reject($payment, Actor::groupAdmin($admin), Input::note($message, 'text'));
        } catch (ValidationException $e) {
            // Too long: the prompt stays for another try. Decided meanwhile: the prompt is moot.
            $tooLong = $e->errors()['text'][0] ?? null;
            $reply($tooLong ?? $e->getMessage());
            if ($tooLong === null) {
                $this->api->deleteMessage($chatId, $promptId);
            }

            return true;
        }

        $this->api->deleteMessage($chatId, $promptId);
        $this->logger->info('Payment {id} rejected from the report group by {reviewer}', ['id' => $payment->id, 'reviewer' => $payment->reviewer]);

        return true;
    }

    private function approve(string $callbackId, int $chatId, int $messageId, Payment $payment, User $admin): void
    {
        try {
            $this->actions->approve($payment, Actor::groupAdmin($admin));
        } catch (ActorRefusedException $e) {
            // Their own receipt: another admin's to decide — its buttons stay for them.
            $this->api->answerCallbackQuery($callbackId, $e->getMessage(), alert: true);

            return;
        } catch (ValidationException|OrderNotPayableException $e) {
            $this->groupButtons->set($chatId, $messageId, null);
            $this->api->answerCallbackQuery($callbackId, $e->getMessage(), alert: true);

            return;
        }

        $this->groupButtons->set($chatId, $messageId, null);
        $this->logger->info('Payment {id} approved from the report group by {reviewer}', ['id' => $payment->id, 'reviewer' => $payment->reviewer]);

        // Paid but not delivered (a panel out of reach): said at once — the retry is under the failure's report.
        $order = $payment->order;
        if ($order->status === OrderStatus::Failed) {
            $this->api->answerCallbackQuery($callbackId, sprintf(self::APPROVED_UNDELIVERED, trim((string) ShopReports::failure($order))), alert: true);

            return;
        }
        $this->api->answerCallbackQuery($callbackId, self::APPROVED);
    }

    private function reject(string $callbackId, int $chatId, int $messageId, Payment $payment, User $admin): void
    {
        try {
            $this->actions->reject($payment, Actor::groupAdmin($admin), null);
        } catch (ValidationException $e) {
            $this->groupButtons->set($chatId, $messageId, null);
            $this->api->answerCallbackQuery($callbackId, $e->getMessage(), alert: true);

            return;
        }

        $this->groupButtons->set($chatId, $messageId, null);
        $this->logger->info('Payment {id} rejected from the report group by {reviewer}', ['id' => $payment->id, 'reviewer' => $payment->reviewer]);
        $this->api->answerCallbackQuery($callbackId, self::REJECTED);
    }

    /**
     * «رد با نوشتن دلیل»: the bot asks, under the receipt, for the reason as a reply to its message — a ForceReply for
     * the admin who pressed (when they have a @username to mention) — and the receipt keeps its two buttons meanwhile.
     */
    private function askReason(Update $update, Payment $payment, User $admin): void
    {
        $callbackId = (string) $update->callbackId();
        $chatId = (int) $update->chatId();
        $messageId = (int) $update->messageId();
        if (!$this->actions->allowed($payment)['reject']) {
            $this->groupButtons->set($chatId, $messageId, null);
            $this->api->answerCallbackQuery($callbackId, $this->actions->refusal($payment, 'reject'), alert: true);

            return;
        }

        $mention = $admin->username !== null && $admin->username !== '' ? ' @' . $admin->username : '';
        $text = sprintf(self::PROMPT, $payment->id, $mention);
        $promptId = $this->groupButtons->say($chatId, $messageId, GroupButtons::threadOf($update->message() ?? []), $text, [
            'force_reply' => true,
            'selective' => true,
            'input_field_placeholder' => 'دلیل رد رسید',
        ]);
        if ($promptId !== null) {
            ReportMessage::posted(Topic::Receipts, $text, self::PROMPT_REF . $payment->id, $chatId, $promptId);
        }
        $this->groupButtons->set($chatId, $messageId, self::buttons($payment->id));
        $this->api->answerCallbackQuery($callbackId, self::WRITE_REASON);
    }

    /**
     * Put these buttons under the receipt — while it still waits for review; one decided meanwhile loses them.
     *
     * @param list<list<array<string, mixed>>> $rows
     */
    private function showButtons(string $callbackId, int $chatId, int $messageId, Payment $payment, array $rows): void
    {
        if (!$this->actions->allowed($payment)['reject']) {
            $this->groupButtons->set($chatId, $messageId, null);
            $this->api->answerCallbackQuery($callbackId, $this->actions->refusal($payment, 'reject'), alert: true);

            return;
        }

        $this->groupButtons->set($chatId, $messageId, $rows);
        $this->api->answerCallbackQuery($callbackId);
    }

    /** @return list<list<array<string, mixed>>> «رد» opened: without a reason, with one, or back. */
    private static function rejectButtons(int $paymentId): array
    {
        return InlineKeyboard::make()
            ->row(
                InlineKeyboard::callback('✍️ رد با نوشتن دلیل', self::data(self::REJECT_WITH_REASON, $paymentId), 'danger'),
                InlineKeyboard::callback('❌ رد بدون توضیح', self::data(self::REJECT_PLAIN, $paymentId), 'danger'),
            )
            ->row(InlineKeyboard::callback('⬅️ بازگشت', self::data(self::BACK, $paymentId)))
            ->build()['inline_keyboard'];
    }

    private static function data(string $action, int $paymentId): string
    {
        return CallbackData::build(self::PREFIX, $action, $paymentId);
    }

    /** The payment the bot's prompt — its message `$messageId` in the group — asks about, as the prompt was recorded; null for any other message. */
    private static function promptedPayment(int $chatId, int $messageId): ?int
    {
        $ref = ReportMessage::query()->where('chat_id', $chatId)->where('message_id', $messageId)->latest('id')->value('ref');
        if (!is_string($ref) || !str_starts_with($ref, self::PROMPT_REF)) {
            return null;
        }

        return (int) substr($ref, strlen(self::PROMPT_REF));
    }

    private static function find(int $id): ?Payment
    {
        return Payment::query()->with(PaymentDirectory::RELATIONS)->find($id);
    }
}
