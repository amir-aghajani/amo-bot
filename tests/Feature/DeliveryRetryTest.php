<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Exceptions\ValidationException;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderActions;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentActions;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Notifications\ServiceCard;
use App\Modules\Telegram\Reports\DeliveryRetry;
use App\Modules\Telegram\Reports\ReportSender;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Update\Update;
use Tests\BotTestCase;
use Tests\Fakes\FakeProvider;
use Tests\Support\FakeTelegram;

/**
 * «تلاش دوباره برای تحویل» under a failed delivery in the errors topic: only the bot's admins may press it, and it does
 * what the orders screen's retry does — the customer told only when it worked; failed again, the popup says why and a
 * new failure report takes the button over; delivered, the failure is answered and its button goes. Of two retries at
 * once, the one that found the order taken tells no one.
 */
final class DeliveryRetryTest extends BotTestCase
{
    /** The failure report's message in the group, as the button's message. */
    private const FAILURE_POST = 5050;

    /** @var array<string, int> */
    private array $threads;

    private Order $order;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutQr();
        $this->threads = $this->reportGroup();
        $this->admin(['telegram_id' => self::GROUP_ADMIN, 'username' => 'boss']);

        $server = $this->sellingServer();
        $this->order = $this->purchaseOrder($this->customer(['username' => 'ali']), $this->plan([], $server), $server);
        FakeProvider::$unreachable = true;
        $this->payment = $this->paidByCard($this->order);
        self::assertSame(OrderStatus::Failed, $this->order->refresh()->status, 'paid, and its delivery failed');
    }

    public function testABotAdminRetriesFromTheErrorsTopicAndTheCustomerGetsTheirService(): void
    {
        FakeProvider::$unreachable = false;

        $this->send($this->press());

        self::assertSame(OrderStatus::Fulfilled, $this->order->refresh()->status);
        $service = $this->order->subscription ?? self::fail('nothing delivered');
        self::assertSame([self::text(BotText::PaySuccess, $this->service(ServiceCard::class)->values($service))], $this->telegram()->sentTo(self::CHAT), 'the customer got their service, as from the screen');
        self::assertSame([], $this->buttonsUnder(self::FAILURE_POST));
        self::assertSame('✅ سفارش تحویل شد.', $this->popup());

        $answered = ReportMessage::query()->where('topic', 'errors')->latest('id')->firstOrFail();
        self::assertSame(["✅ سفارش #{$this->order->id} با تلاش دوباره تحویل شد.", "delivery:{$this->order->id}", true], [$answered->text, $answered->reply_ref, $answered->clears_buttons]);
        self::assertSame(1, ReportMessage::query()->where('topic', 'purchases')->count(), 'and the sale is reported');
    }

    public function testOnlyTheBotsAdminsMayRetry(): void
    {
        FakeProvider::$unreachable = false;

        foreach ([self::CHAT, self::GROUP_MEMBER] as $who) {
            $this->send($this->press($who));

            self::assertSame(['answerCallbackQuery'], $this->calls(), "{$who} may not: the press is only answered");
            self::assertSame('true', $this->params(0)['show_alert']);
            self::assertStringContainsString('فقط مدیرهای ربات', (string) $this->popup());
        }

        self::assertSame(OrderStatus::Failed, $this->order->refresh()->status);
    }

    public function testARetryThatFailsAgainSaysWhyAndItsNewReportTakesTheButtonOver(): void
    {
        $sender = $this->service(ReportSender::class);
        $sender->flush(30);
        $failure = ReportMessage::query()->where('ref', "delivery:{$this->order->id}")->firstOrFail();
        self::assertSame(["delivery:{$this->order->id}", true], [$failure->reply_ref, $failure->clears_buttons], 'a failure answers an earlier one and takes its button over');
        $firstFailure = (int) $failure->message_id;

        $this->send($this->press(post: $firstFailure));

        self::assertSame(OrderStatus::Failed, $this->order->refresh()->status);
        self::assertSame([], $this->telegram()->sentTo(self::CHAT), 'the customer is told only when it works');
        self::assertNull($this->buttonsUnder($firstFailure), 'the button stays for another try');
        self::assertSame('true', $this->params(0)['show_alert']);
        self::assertStringStartsWith('تحویل باز هم ناموفق بود. علت: ', (string) $this->popup());

        $this->telegram()->reset();
        $sender->flush(30);
        $again = $this->telegram()->params(0);
        self::assertSame((string) $this->threads['errors'], $again['message_thread_id']);
        self::assertSame($firstFailure, $this->telegram()->replyTarget(0), 'under the first failure');
        self::assertSame(DeliveryRetry::buttons($this->order->id), FakeTelegram::markupOf($again)['inline_keyboard'] ?? null, 'with the button');
        self::assertSame([], $this->buttonsUnder($firstFailure), 'which the first one loses');
    }

    public function testAStaleRetryIsRefusedAndLosesItsButton(): void
    {
        FakeProvider::$unreachable = false;
        $this->service(OrderActions::class)->retry(Order::query()->findOrFail($this->order->id));

        $this->send($this->press());

        self::assertSame([], $this->telegram()->sentTo(self::CHAT), 'nothing delivered twice, nobody told twice');
        self::assertSame([], $this->buttonsUnder(self::FAILURE_POST));
        self::assertSame('true', $this->params(1)['show_alert']);
        self::assertSame('این سفارش تحویل شده است.', $this->popup(), 'what it came to: delivered from elsewhere');

        $this->send($this->groupTap('rt:999999', self::FAILURE_POST, $this->threads['errors']));
        self::assertSame([], $this->buttonsUnder(self::FAILURE_POST));
        self::assertSame('این سفارش پیدا نشد.', $this->popup());
    }

    public function testOfRetriesMadeAtOnceTheOneThatFoundTheOrderTakenTellsNoOne(): void
    {
        FakeProvider::$unreachable = false;
        // Two admins read the order as failed — the group, the orders screen, the payments screen — before one of them retried.
        $order = Order::query()->with('payments')->findOrFail($this->order->id);
        $payment = Payment::query()->with('order')->findOrFail($this->payment->id);
        $this->service(OrderActions::class)->retry(Order::query()->findOrFail($this->order->id));

        foreach (['orders' => fn() => $this->service(OrderActions::class)->retry($order), 'payments' => fn() => $this->service(PaymentActions::class)->retry($payment)] as $screen => $retry) {
            try {
                $retry();
                self::fail("the {$screen} screen's retry that found the order taken went through");
            } catch (ValidationException $e) {
                self::assertSame(OrderService::DELIVERY_TAKEN, $e->getMessage(), $screen);
            }
        }

        self::assertCount(1, FakeProvider::$created, 'delivered once');
        self::assertCount(1, $this->telegram()->sentTo(self::CHAT), 'and the customer told once');
    }

    /** A press on the retry button under a failure report in the errors topic — the first one's, by the bot admin, unless told otherwise. */
    private function press(int $from = self::GROUP_ADMIN, int $post = self::FAILURE_POST): Update
    {
        return $this->groupTap(DeliveryRetry::buttons($this->order->id)[0][0]['callback_data'], $post, $this->threads['errors'], $from);
    }
}
