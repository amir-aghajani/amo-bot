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
 * «✅ تایید» and «❌ رد» under a receipt in the report group, for bot admins only: they act through PaymentActions, as
 * the payments screen does (its rules, the customer told, the admin recorded as the reviewer; an admin's own receipt is
 * another admin's to approve). «رد» asks first — without a reason, or with one the admin writes as a reply to the bot's
 * prompt. The prompt is recorded as it is posted, and a reply counts as a reason only when it answers that very message
 * in the receipts topic — never judged by the replied message's words, which may be a customer's. A decided receipt
 * loses its buttons.
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

    /** The `ref` a recorded prompt for a reason carries: `prompt:<payment id>`. */
    private const PROMPT_REF = 'prompt:';
    private const PROMPT = "✍️ دلیل رد رسید پرداخت \u{200E}#%d را در پاسخ به همین پیام بنویسید؛ مشتری آن را به عنوان توضیح پشتیبانی می‌بیند.%s";

    /** What only the bot's admins may do here, as the refusal says it (GroupButtons::onlyAdmins()). */
    private const MAY = 'رسید را تایید یا رد کنند';
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
     * The two buttons under a receipt waiting for review, «تایید» on a Persian reader's right.
     *
     * @return list<list<array<string, mixed>>>
     */
    public static function buttons(int $paymentId): array
    {
        return InlineKeyboard::make()
            ->row(InlineKeyboard::callback('❌ رد', self::data(self::REJECT, $paymentId), 'danger'), InlineKeyboard::callback('✅ تایید', self::data(self::APPROVE, $paymentId), 'success'))
            ->build()['inline_keyboard'];
    }

    /** A press on one of the buttons. */
    public function handle(Update $update): void
    {
        $args = $update->callbackArgs(self::PREFIX) ?? [];
        if (count($args) !== 2 || !in_array($args[0], [self::APPROVE, self::REJECT, self::REJECT_PLAIN, self::REJECT_WITH_REASON, self::BACK], true)) {
            $this->groupButtons->answer($update);

            return;
        }
        [$action, $paymentId] = $args;

        $admin = $this->groupButtons->presser($update, GroupButtons::onlyAdmins(self::MAY));
        if ($admin === null) {
            return;
        }

        $payment = self::find((int) $paymentId);
        if ($payment === null) {
            $this->groupButtons->dismiss($update, self::NOT_FOUND);

            return;
        }

        match ($action) {
            self::APPROVE => $this->approve($update, $payment, $admin),
            self::REJECT_PLAIN => $this->reject($update, $payment, $admin),
            self::REJECT_WITH_REASON => $this->askReason($update, $payment, $admin),
            // «رد» and «بازگشت» only change the buttons, while there is still something to decide.
            default => $this->showButtons($update, $payment, $action === self::REJECT ? self::rejectButtons($payment->id) : self::buttons($payment->id)),
        };
    }

    /**
     * A message replying to the bot's prompt for a reason, in the receipts topic: the receipt is rejected with it as
     * support's note. False when the message is no such reply (not this class's business).
     */
    public function reason(Update $update): bool
    {
        $message = $update->raw['message'] ?? null;
        $prompt = $this->groupButtons->botReplied($update);
        if (!is_array($message) || $prompt === null) {
            return false;
        }

        $chatId = (int) $update->chatId();
        $promptId = (int) ($prompt['message_id'] ?? 0);
        $paymentId = $this->topics->holds(Topic::Receipts, $chatId, $message) ? self::promptedPayment($chatId, $promptId) : null;
        if ($paymentId === null) {
            return false;
        }

        $admin = $this->groupButtons->writer($chatId, $message, GroupButtons::onlyAdmins(self::MAY));
        if ($admin === null) {
            return true;
        }
        if (trim((string) $update->text()) === '') {
            $this->groupButtons->sayUnder($chatId, $message, self::TEXT_ONLY);

            return true;
        }

        $payment = self::find($paymentId);
        if ($payment === null) {
            $this->groupButtons->sayUnder($chatId, $message, self::NOT_FOUND);
            $this->api->deleteMessage($chatId, $promptId);

            return true;
        }

        try {
            $this->actions->reject($payment, Actor::groupAdmin($admin), Input::note($message, 'text'));
        } catch (ValidationException $e) {
            // Too long: the prompt stays for another try. Decided meanwhile: the prompt is moot.
            $tooLong = $e->errors()['text'][0] ?? null;
            $this->groupButtons->sayUnder($chatId, $message, $tooLong ?? $e->getMessage());
            if ($tooLong === null) {
                $this->api->deleteMessage($chatId, $promptId);
            }

            return true;
        }

        $this->api->deleteMessage($chatId, $promptId);
        $this->logger->info('Payment {id} rejected from the report group by {reviewer}', ['id' => $payment->id, 'reviewer' => $payment->reviewer]);

        return true;
    }

    private function approve(Update $update, Payment $payment, User $admin): void
    {
        try {
            $this->actions->approve($payment, Actor::groupAdmin($admin));
        } catch (ActorRefusedException $e) {
            // Their own receipt: another admin's to decide, so the buttons stay.
            $this->groupButtons->answer($update, $e->getMessage(), alert: true);

            return;
        } catch (ValidationException|OrderNotPayableException $e) {
            $this->groupButtons->dismiss($update, $e->getMessage());

            return;
        }

        $this->groupButtons->buttons($update, null);
        $this->logger->info('Payment {id} approved from the report group by {reviewer}', ['id' => $payment->id, 'reviewer' => $payment->reviewer]);

        // Paid but not delivered (a panel out of reach): said at once; the retry is under the failure's report.
        $order = $payment->order;
        if ($order->status === OrderStatus::Failed) {
            $this->groupButtons->answer($update, sprintf(self::APPROVED_UNDELIVERED, trim((string) ShopReports::failure($order))), alert: true);

            return;
        }
        $this->groupButtons->answer($update, self::APPROVED);
    }

    private function reject(Update $update, Payment $payment, User $admin): void
    {
        try {
            $this->actions->reject($payment, Actor::groupAdmin($admin), null);
        } catch (ValidationException $e) {
            $this->groupButtons->dismiss($update, $e->getMessage());

            return;
        }

        $this->groupButtons->buttons($update, null);
        $this->logger->info('Payment {id} rejected from the report group by {reviewer}', ['id' => $payment->id, 'reviewer' => $payment->reviewer]);
        $this->groupButtons->answer($update, self::REJECTED);
    }

    /**
     * «رد با نوشتن دلیل»: the bot asks under the receipt for the reason as a reply to its prompt (a ForceReply that
     * mentions the admin when they have a @username — after a left-to-right mark, or the Persian words before it would
     * show it «sara@»), recorded so the reply can be told apart; the receipt keeps its two buttons meanwhile.
     */
    private function askReason(Update $update, Payment $payment, User $admin): void
    {
        if (!$this->actions->allowed($payment)['reject']) {
            $this->groupButtons->dismiss($update, $this->actions->refusal($payment, 'reject'));

            return;
        }
        $chatId = (int) $update->chatId();
        $messageId = (int) $update->messageId();

        $mention = $admin->handle() === null ? '' : " \u{200E}@" . $admin->handle();
        $text = sprintf(self::PROMPT, $payment->id, $mention);
        $promptId = $this->groupButtons->say($chatId, $messageId, GroupButtons::threadOf($update->message() ?? []), $text, [
            'force_reply' => true,
            'selective' => true,
            'input_field_placeholder' => 'دلیل رد رسید',
        ]);
        if ($promptId !== null) {
            ReportMessage::posted(Topic::Receipts, $text, self::PROMPT_REF . $payment->id, $chatId, $promptId);
        }
        $this->groupButtons->buttons($update, self::buttons($payment->id));
        $this->groupButtons->answer($update, self::WRITE_REASON);
    }

    /**
     * Put these buttons under the receipt while it still waits for review; one decided meanwhile loses them.
     *
     * @param list<list<array<string, mixed>>> $rows
     */
    private function showButtons(Update $update, Payment $payment, array $rows): void
    {
        if (!$this->actions->allowed($payment)['reject']) {
            $this->groupButtons->dismiss($update, $this->actions->refusal($payment, 'reject'));

            return;
        }

        $this->groupButtons->buttons($update, $rows);
        $this->groupButtons->answer($update);
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

    /** The payment the bot's prompt (its message `$messageId`) asks about, as recorded; null for any other message. */
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
