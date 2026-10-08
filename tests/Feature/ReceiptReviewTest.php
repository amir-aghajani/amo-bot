<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Exceptions\ValidationException;
use App\Modules\Agency\Services\AgencyActions;
use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\OrderNotPayableException;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentActions;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Support\Enums\TicketAuthor;
use App\Modules\Support\Enums\TicketChannel;
use App\Modules\Support\Models\TicketMessage;
use App\Modules\Support\Services\Tickets;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Notifications\ServiceCard;
use App\Modules\Telegram\Reports\ReceiptReview;
use App\Modules\Telegram\Reports\ReportSender;
use App\Modules\Telegram\Reports\ShopReports;
use App\Modules\Telegram\Reports\Topic;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Update\Update;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\UserActions;
use App\Support\Input;
use App\Support\Validation;
use Tests\BotTestCase;
use Tests\Fakes\FakeProvider;
use Tests\Support\FakeTelegram;

/**
 * «تایید» and «رد» under a receipt in the report group: only the bot's admins may press them — told by the account that
 * pressed, not banned —, and they do what the payments screen does — the payment settled or refused under the same
 * rules, the customer told the same way, the admin's @username (or Telegram id) as the reviewer. «رد» asks first: no
 * reason, or one written as a reply to the bot's prompt. A receipt decided — here, on the screen, by the timer — or
 * gone loses its buttons, and a stale button decides nothing.
 */
final class ReceiptReviewTest extends BotTestCase
{
    /** The receipt's copy in the group, as the buttons' message. */
    private const RECEIPT_POST = 3030;

    /** The bot's prompt for a reason, as the admin's reply quotes it. */
    private const PROMPT_POST = 4040;

    /** @var array<string, int> */
    private array $threads;

    private User $boss;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutQr();
        $this->threads = $this->reportGroup();
        $this->boss = $this->admin(['telegram_id' => self::GROUP_ADMIN, 'username' => 'boss']);

        $server = $this->sellingServer();
        $order = $this->purchaseOrder($this->customer(['username' => 'ali']), $this->plan([], $server), $server);
        $this->payment = $this->receipt($this->cardPayment($order, $this->cardMethod()));
    }

    public function testABotAdminApprovesAReceiptFromTheGroupAndTheCustomerGetsTheirService(): void
    {
        $this->send($this->press('ok'));

        $payment = $this->payment->refresh();
        self::assertSame([PaymentStatus::Paid, '@boss', OrderStatus::Fulfilled], [$payment->status, $payment->reviewer, $payment->order->status], "the admin's account decided");
        $service = $payment->order->subscription ?? self::fail('nothing delivered');
        self::assertSame([self::text(BotText::PaySuccess, $this->service(ServiceCard::class)->values($service))], $this->telegram()->sentTo(self::CHAT), 'the customer got their service, as from the screen');
        self::assertSame(self::RECEIPT_MESSAGE, $this->telegram()->replyTarget(0), 'as a reply to their receipt');
        self::assertSame([], $this->buttonsUnder(self::RECEIPT_POST), 'nothing left to press');
        self::assertSame('✅ رسید تایید شد.', $this->popup());

        self::assertSame(1, ReportMessage::query()->where('reply_ref', "receipt:{$payment->id}")->where('clears_buttons', true)->count(), 'its verdict goes to the group like any other');
    }

    /** A bot admin is the shop's customer too: their own receipt is another admin's to approve — its buttons stay for them. */
    public function testAnAdminsOwnReceiptIsAnotherAdminsToApprove(): void
    {
        $own = $this->receipt($this->cardPayment($this->topUpOrder($this->boss, '10000.00'), $this->cardMethod('کارت دیگر')));

        $this->send($this->groupTap("rv:ok:{$own->id}", self::RECEIPT_POST, $this->threads['receipts']));

        self::assertSame(PaymentStatus::AwaitingReview, $own->refresh()->status);
        self::assertSame(['answerCallbackQuery'], $this->calls(), 'its buttons stay');
        self::assertSame(ActorRefusedException::OWN_PAYMENT, $this->popup());

        $this->admin(['telegram_id' => 7070, 'username' => 'reza']);
        $this->send($this->groupTap("rv:ok:{$own->id}", self::RECEIPT_POST, $this->threads['receipts'], 7070));
        self::assertSame([PaymentStatus::Paid, '@reza'], [$own->refresh()->status, $own->reviewer]);
    }

    public function testAnAdminWithoutAUsernameDecidesUnderTheirTelegramId(): void
    {
        $this->admin(['telegram_id' => 7070]);

        $this->send($this->press('nx', 7070));

        self::assertSame([PaymentStatus::Failed, 'tg:7070'], [$this->payment->refresh()->status, $this->payment->reviewer]);
    }

    public function testOnlyTheBotsAdminsMayDecide(): void
    {
        foreach ([self::CHAT, self::GROUP_MEMBER] as $who) {
            $this->send($this->press('ok', $who));

            self::assertSame(['answerCallbackQuery'], $this->calls(), "{$who} may not: the press is only answered");
            self::assertSame('true', $this->params(0)['show_alert']);
            self::assertStringContainsString('فقط مدیرهای ربات', (string) $this->popup());
        }

        $this->service(UserActions::class)->setStatus($this->boss, $this->panelActor(), ['status' => 'banned']);
        $this->send($this->press('nx'));
        self::assertSame(['answerCallbackQuery'], $this->calls(), 'nor a banned admin');

        self::assertSame(PaymentStatus::AwaitingReview, $this->payment->refresh()->status);
    }

    public function testRejectingAsksFirstAndBackReturnsToTheTwoButtons(): void
    {
        $this->send($this->press('no'));

        self::assertSame([
            [['text' => '✍️ رد با نوشتن دلیل', 'callback_data' => "rv:nw:{$this->payment->id}", 'style' => 'danger'], ['text' => '❌ رد بدون توضیح', 'callback_data' => "rv:nx:{$this->payment->id}", 'style' => 'danger']],
            [['text' => '⬅️ بازگشت', 'callback_data' => "rv:bk:{$this->payment->id}"]],
        ], $this->buttonsUnder(self::RECEIPT_POST));
        self::assertNull($this->popup(), 'only the buttons change');

        $this->send($this->press('bk'));
        self::assertSame(ReceiptReview::buttons($this->payment->id), $this->buttonsUnder(self::RECEIPT_POST));
        self::assertSame(PaymentStatus::AwaitingReview, $this->payment->refresh()->status, 'nothing decided yet');
    }

    public function testARejectionWithoutAReason(): void
    {
        $this->send($this->press('nx'));

        $payment = $this->payment->refresh();
        self::assertSame([PaymentStatus::Failed, '@boss'], [$payment->status, $payment->reviewer]);
        self::assertSame([self::text(BotText::ReceiptRejected, ['order' => $payment->order_id, 'note' => ''])], $this->telegram()->sentTo(self::CHAT), 'the customer told, as from the screen');
        self::assertSame([], $this->buttonsUnder(self::RECEIPT_POST));
        self::assertSame('❌ رسید رد شد.', $this->popup());
    }

    public function testARejectionWithTheReasonTheAdminWritesAsAReplyToTheBotsPrompt(): void
    {
        $this->askForAReason();

        $prompt = $this->params(0);
        self::assertSame([$prompt['text']], $this->telegram()->sentTo(self::REPORT_GROUP), 'the bot asks in the group');
        self::assertSame((string) $this->threads['receipts'], $prompt['message_thread_id']);
        self::assertSame(self::RECEIPT_POST, $this->telegram()->replyTarget(0), 'under the receipt');
        self::assertSame(['force_reply' => true, 'selective' => true, 'input_field_placeholder' => 'دلیل رد رسید'], FakeTelegram::markupOf($prompt));
        self::assertStringContainsString("دلیل رد رسید پرداخت #{$this->payment->id} را در پاسخ به همین پیام بنویسید", $prompt['text']);
        self::assertStringEndsWith('@boss', $prompt['text'], 'mentioned, so the reply box opens for them alone');
        self::assertSame(ReceiptReview::buttons($this->payment->id), $this->buttonsUnder(self::RECEIPT_POST), 'the receipt keeps its two buttons meanwhile');
        self::assertSame(PaymentStatus::AwaitingReview, $this->payment->refresh()->status);

        $this->send($this->reason($prompt['text'], 'مبلغ واریزی کمتر از سفارش است'));

        $payment = $this->payment->refresh();
        self::assertSame([PaymentStatus::Failed, 'مبلغ واریزی کمتر از سفارش است'], [$payment->status, $payment->note]);
        self::assertSame([self::text(BotText::ReceiptRejected, [
            'order' => $payment->order_id,
            'note' => self::text(BotText::AdminNote, ['comment' => 'مبلغ واریزی کمتر از سفارش است']),
        ])], $this->telegram()->sentTo(self::CHAT));
        self::assertTrue($this->promptDeleted(), 'the prompt is answered');
        self::assertSame(1, ReportMessage::query()->where('reply_ref', "receipt:{$payment->id}")->where('text', 'like', '%مبلغ واریزی کمتر از سفارش است%')->count());
    }

    public function testAReasonFromSomeoneElseAnonymouslyOrNotInWordsDecidesNothing(): void
    {
        $prompt = $this->promptText();

        $this->send($this->reason($prompt, 'تایید نکنید', self::GROUP_MEMBER));
        self::assertStringContainsString('فقط مدیرهای ربات', $this->said()[0]);

        $this->send($this->reason($prompt, 'تایید نکنید', extra: ['from' => ['id' => 8008, 'is_bot' => true, 'first_name' => 'OtherBot']]));
        self::assertStringContainsString('فقط مدیرهای ربات', $this->said()[0], 'nor another bot');

        $this->send($this->reason($prompt, 'ناشناس', extra: ['sender_chat' => ['id' => self::REPORT_GROUP, 'type' => 'supergroup']]));
        self::assertStringContainsString('ناشناس', $this->said()[0]);

        $this->send($this->reason($prompt, null, extra: ['sticker' => ['file_id' => 'x']]));
        self::assertStringContainsString('به صورت متن', $this->said()[0]);

        $this->send($this->reason($prompt, str_repeat('ب', Input::NOTE_MAX + 1)));
        self::assertSame([Validation::tooLong('توضیح', Input::NOTE_MAX)], $this->said());
        self::assertFalse($this->promptDeleted(), 'too long: the prompt stays for another try');
        self::assertSame(77, $this->telegram()->replyTarget(0), "said under the admin's own message");

        self::assertSame(PaymentStatus::AwaitingReview, $this->payment->refresh()->status);
    }

    public function testAReceiptDecidedElsewhereRefusesItsStaleButtonsAndLosesThem(): void
    {
        $prompt = $this->promptText();
        $this->service(PaymentService::class)->approve($this->payment, 'admin');

        $this->send($this->press('ok'));
        self::assertSame([], $this->telegram()->sentTo(self::CHAT), 'no second delivery');
        self::assertSame([], $this->buttonsUnder(self::RECEIPT_POST));
        self::assertSame('true', $this->params(1)['show_alert']);
        // What it came to, whichever button was pressed.
        $approved = 'این پرداخت تایید شده است.';
        self::assertSame($approved, $this->popup());

        foreach (['no', 'nw', 'bk'] as $action) {
            $this->send($this->press($action));
            self::assertSame($approved, $this->popup(), $action);
            self::assertSame([], $this->buttonsUnder(self::RECEIPT_POST), $action);
        }
        $this->send($this->press('nx'));
        self::assertSame($approved, $this->popup(), 'the rule refuses the rejection');
        self::assertSame([], $this->buttonsUnder(self::RECEIPT_POST));

        $this->send($this->reason($prompt, 'دیر شد'));
        self::assertSame([$approved], $this->said(), 'decided meanwhile: said…');
        self::assertTrue($this->promptDeleted(), '…and the prompt taken away');
        self::assertSame('admin', $this->payment->refresh()->reviewer, "the screen's decision stands");
    }

    public function testAReceiptThatIsGoneLosesItsButtonsAndItsPrompt(): void
    {
        $prompt = $this->promptText();

        $this->send($this->groupTap('rv:ok:999999', self::RECEIPT_POST, $this->threads['receipts']));
        self::assertSame('این پرداخت پیدا نشد.', $this->popup());
        self::assertSame([], $this->buttonsUnder(self::RECEIPT_POST));

        // The payment the prompt asked about is gone since.
        Payment::query()->whereKey($this->payment->id)->delete();
        $this->send($this->reason($prompt, 'ناخوانا'));
        self::assertSame(['این پرداخت پیدا نشد.'], $this->said());
        self::assertTrue($this->promptDeleted());
    }

    public function testOfDecisionsMadeAtOnceTheOnesThatLostAreRefusedSoTheCustomerHearsOnlyTheOneThatStands(): void
    {
        // Three admins read the receipt as waiting — in the group, on the screen — before one of them decided.
        [$approve, $reject, $cancel] = array_map(fn(): Payment => Payment::query()->with('order')->findOrFail($this->payment->id), range(1, 3));
        $actions = $this->service(PaymentActions::class);
        $actions->approve($this->payment, $this->groupAdminActor());

        // Each copy still reads as waiting, so its rule lets it through — and its compare-and-swap is what refuses it.
        $owner = $this->panelActor();
        foreach (['approve' => static fn() => $actions->approve($approve, $owner), 'reject' => static fn() => $actions->reject($reject, $owner, 'دیر'), 'cancel' => static fn() => $actions->cancel($cancel, $owner, null)] as $what => $decide) {
            try {
                $decide();
                self::fail("the {$what} that lost went through");
            } catch (ValidationException $e) {
                self::assertSame(PaymentActions::DECIDED_MEANWHILE, $e->getMessage(), $what);
            }
        }

        $payment = $this->payment->refresh();
        self::assertSame([PaymentStatus::Paid, '@boss', OrderStatus::Fulfilled], [$payment->status, $payment->reviewer, $payment->order->status]);
        self::assertCount(1, $this->telegram()->sentTo(self::CHAT), 'the customer heard the approval alone');
    }

    public function testAnApprovalThatMeetsItsOrderClosedInTheSameMomentIsRefusedWithTheReason(): void
    {
        $this->whileTheOrderClosesOnceRead(fn() => $this->send($this->press('ok')));

        self::assertSame((new OrderNotPayableException($this->payment->order))->getMessage(), $this->popup());
        self::assertSame([], $this->buttonsUnder(self::RECEIPT_POST));
        self::assertSame([PaymentStatus::AwaitingReview, null], [$this->payment->refresh()->status, $this->payment->reviewer], 'nothing was decided');
        self::assertSame([], $this->telegram()->sentTo(self::CHAT));
    }

    public function testAnApprovalWhoseDeliveryFailsSaysSoAtOnce(): void
    {
        FakeProvider::$unreachable = true;

        $this->send($this->press('ok'));

        self::assertSame(OrderStatus::Failed, $this->payment->refresh()->order->status);
        $popup = (string) $this->popup();
        self::assertStringStartsWith('رسید تایید شد ولی تحویل ناموفق بود؛ «تلاش دوباره برای تحویل» در تاپیک «خطاها» است. علت: ', $popup, 'the retry first');
        self::assertLessThanOrEqual(200, mb_strlen($popup), "Telegram's cap on a popup");
    }

    public function testRepliesToTheBotsOtherMessagesAreNotReasons(): void
    {
        $this->send($this->reason("❌ رسید پرداخت #{$this->payment->id} رد شد.", 'این هم دلیل'));
        self::assertSame([], $this->calls(), "a report of the bot's");

        // A message in the topic is a reply to the topic's root, the bot's own (it made the topic), which has no words.
        $this->send($this->groupReply('', 'سلام', $this->threads['receipts'], $this->threads['receipts']));
        self::assertSame([], $this->calls(), "the topic's root");

        $prompt = $this->promptText();
        $this->send($this->reason($prompt, 'از یک کاربر', repliedFrom: self::GROUP_MEMBER));
        self::assertSame([], $this->calls(), "someone else's message quoting the prompt");

        $this->send($this->groupReply($prompt, 'دلیل', $this->threads['users'], self::PROMPT_POST));
        self::assertSame([], $this->calls(), 'a reply in another topic');

        self::assertSame(PaymentStatus::AwaitingReview, $this->payment->refresh()->status);
    }

    /**
     * The prompt's words are no key: a customer who writes them into a ticket — and another customer's payment number with
     * them — gets support's answer to their ticket, and nobody's receipt is touched.
     */
    public function testThePromptsWordsInATicketNeverTurnSupportsAnswerIntoARejection(): void
    {
        $mallory = $this->customer(['telegram_id' => 5252, 'username' => 'mallory']);
        $ticket = $this->service(Tickets::class)->open($mallory, ['subject' => 'مشکل اتصال دارم', 'body' => "سلام\nدلیل رد رسید پرداخت #{$this->payment->id}"], null, TicketChannel::Bot);
        $this->service(ReportSender::class)->flush();
        $report = ReportMessage::query()->where('ticket_id', $ticket->id)->sole();

        // Support answers the ticket by replying to its report, as the report tells them to.
        $this->send($this->groupReply(self::plain($report->text), 'سرویس شما درست شد.', $this->threads['tickets'], (int) $report->message_id));

        self::assertSame(PaymentStatus::AwaitingReview, $this->payment->refresh()->status, "another customer's receipt stands");
        self::assertSame(['سرویس شما درست شد.'], TicketMessage::query()->where('ticket_id', $ticket->id)->where('author', TicketAuthor::Support->value)->pluck('body')->all(), 'the ticket got its answer');
        self::assertSame(['✅ پاسخ برای مشتری فرستاده شد.'], $this->telegram()->sentTo(self::REPORT_GROUP));
        self::assertNotContains('deleteMessage', $this->calls(), 'no report taken out of the group');
    }

    /** Nor are they in a customer's name, on every report about them: a bot admin's reply to one rejects nothing. */
    public function testThePromptsWordsInACustomersNameRejectNothing(): void
    {
        $newcomer = $this->customer(['telegram_id' => 5252, 'first_name' => "دلیل رد رسید پرداخت #{$this->payment->id}"]);
        $this->service(ShopReports::class)->newCustomer($newcomer, null);

        $this->assertAReplyToTheReportRejectsNothing(Topic::Users);
    }

    /** Nor in what a customer wrote asking to become an agent. */
    public function testThePromptsWordsInAnAgencyRequestRejectNothing(): void
    {
        $this->agencyProgram();
        $this->service(AgencyActions::class)->request($this->customer(['telegram_id' => 5252]), "کانال فروش دارم\nدلیل رد رسید پرداخت #{$this->payment->id}");

        $this->assertAReplyToTheReportRejectsNothing(Topic::Agency);
    }

    public function testAButtonThatIsNotOursIsOnlyAcknowledgedAndOneInAPrivateChatDecidesNothing(): void
    {
        $this->send($this->groupTap('something:else', self::RECEIPT_POST, $this->threads['receipts']));
        self::assertSame(['answerCallbackQuery'], $this->calls());
        self::assertNull($this->popup());

        $this->send($this->tap("rv:ok:{$this->payment->id}", self::GROUP_ADMIN));
        self::assertSame(PaymentStatus::AwaitingReview, $this->payment->refresh()->status, 'the buttons work in a group only');
    }

    /** A press on one of the buttons under the receipt's copy in the receipts topic (`rv:<action>:<payment>`) — by the bot admin unless told otherwise. */
    private function press(string $action, int $from = self::GROUP_ADMIN): Update
    {
        return $this->groupTap("rv:{$action}:{$this->payment->id}", self::RECEIPT_POST, $this->threads['receipts'], $from);
    }

    /**
     * A message in the receipts topic answering the bot's prompt (`$prompt`, its text as Telegram hands it back) — or a
     * message of someone else's (`$repliedFrom`).
     *
     * @param array<string, mixed> $extra Other fields of the message
     */
    private function reason(string $prompt, ?string $text, int $from = self::GROUP_ADMIN, array $extra = [], int $repliedFrom = FakeTelegram::BOT_ID): Update
    {
        return $this->groupReply($prompt, $text, $this->threads['receipts'], self::PROMPT_POST, $from, $extra, $repliedFrom);
    }

    /** «رد با نوشتن دلیل» pressed: the bot's prompt for a reason is its message PROMPT_POST in the group. */
    private function askForAReason(): void
    {
        $this->telegram()->reply(['message_id' => self::PROMPT_POST]);
        $this->send($this->press('nw'));
    }

    /** The prompt the bot writes for «رد با نوشتن دلیل», as Telegram hands it back in a reply. */
    private function promptText(): string
    {
        $this->askForAReason();

        return $this->said()[0];
    }

    /**
     * The topic's one report — carrying the prompt's words and the receipt's payment number, a customer's — sent, and a
     * bot admin's reply to it: the receipt stands, and the bot says nothing.
     */
    private function assertAReplyToTheReportRejectsNothing(Topic $topic): void
    {
        $this->service(ReportSender::class)->flush();
        $report = ReportMessage::query()->where('topic', $topic->value)->sole();
        self::assertStringContainsString("دلیل رد رسید پرداخت #{$this->payment->id}", self::plain($report->text));

        $this->send($this->groupReply(self::plain($report->text), 'بررسی شد', $this->threads[$topic->value], (int) $report->message_id));

        self::assertSame(PaymentStatus::AwaitingReview, $this->payment->refresh()->status, 'the receipt stands');
        self::assertSame([], $this->calls(), "none of the replies' readers' business");
    }

    /** A report as Telegram hands it back in a reply: its words, without their markup. */
    private static function plain(string $html): string
    {
        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** Whether the bot took its prompt for a reason away since the last send(). */
    private function promptDeleted(): bool
    {
        foreach ($this->telegram()->history as $call) {
            if ($call['method'] === 'deleteMessage' && $call['params'] === ['chat_id' => (string) self::REPORT_GROUP, 'message_id' => (string) self::PROMPT_POST]) {
                return true;
            }
        }

        return false;
    }
}
