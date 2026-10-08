<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Tasks\ExpireOrdersTask;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Tasks\AutoApproveReceiptsTask;
use App\Modules\Telegram\Handlers\PurchaseHandler;
use App\Modules\Telegram\Handlers\TopUpHandler;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Users\Models\User;
use App\Modules\Users\Models\WalletTransaction;
use App\Modules\Users\Services\WalletService;
use App\Modules\Users\Services\WalletSettings;
use App\Support\Money;
use App\Support\Persian;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use Tests\BotTestCase;

/**
 * The wallet in the bot: the balance and ledger screen, charging it by a preset or a typed amount held to the bot
 * settings' bounds, paying the top-up by card — every way to pay but the wallet itself —, and the balance landing once
 * the receipt is accepted; a top-up left unpaid expires.
 */
final class BotWalletTest extends BotTestCase
{
    private User $ali;
    private PaymentMethod $card;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ali = $this->customer();
        // The wallet row exists in every shop; a card row is what a top-up is paid with.
        $this->card = $this->cardMethod(autoApproveAfter: 30);
    }

    public function testTheWalletScreenShowsTheBalanceAndTheLastMovements(): void
    {
        $this->send($this->tap(MainMenu::WALLET));
        self::assertStringEndsWith(ltrim(self::text(BotText::WalletNoHistory), "\n"), $this->said()[0], 'a fresh wallet says so');

        $wallet = $this->service(WalletService::class);
        $wallet->credit($this->ali, 80000, 'هدیه <ثبت‌نام>');
        $wallet->debit($this->ali, 30000, 'خرید پلن یک‌ماهه');
        $this->send($this->tap(MainMenu::WALLET));

        self::assertSame(['editMessageText', 'answerCallbackQuery'], $this->calls(), 'the screen in place, then the tap acknowledged');
        $text = $this->said()[0];
        self::assertStringStartsWith(self::text(BotText::WalletBalance, ['balance' => Messages::balance('50000.00')]), $text);
        $today = Persian::date(now());
        self::assertStringContainsString(self::text(BotText::WalletHistoryDebit, ['amount' => Money::format('30000'), 'entry' => 'خرید پلن یک‌ماهه', 'when' => $today]), $text);
        self::assertStringContainsString(self::text(BotText::WalletHistoryCredit, ['amount' => Money::format('80000'), 'entry' => 'هدیه &lt;ثبت‌نام&gt;', 'when' => $today]), $text, "the ledger's words escaped once");
        self::assertTrue(strpos($text, '➖') < strpos($text, '➕'), 'newest first');
        self::assertSame([[['text' => self::text(BotText::WalletTopup), 'callback_data' => TopUpHandler::START, 'style' => 'success']]], $this->inlineKeyboard(0));
    }

    public function testAPresetAmountLeadsToACheckoutWithoutTheWalletAsAMethod(): void
    {
        $this->send($this->tap(TopUpHandler::START));
        self::assertSame([self::text(BotText::TopupPick, ['min' => Money::format(10000)])], $this->said());
        $keyboard = $this->inlineKeyboard(0);
        self::assertSame([TopUpHandler::checkoutCallback(50000), TopUpHandler::checkoutCallback(100000)], array_column($keyboard[0], 'callback_data'), 'presets two per row');
        self::assertSame([TopUpHandler::checkoutCallback(200000), TopUpHandler::checkoutCallback(500000)], array_column($keyboard[1], 'callback_data'));
        self::assertSame(self::text(BotText::TopupCustom), $keyboard[2][0]['text']);
        self::assertSame(MainMenu::WALLET, $keyboard[3][0]['callback_data']);

        $this->send($this->tap(TopUpHandler::checkoutCallback(100000)));

        self::assertSame([self::text(BotText::TopupCheckout, ['amount' => Money::format(100000)])], $this->said());
        self::assertSame([$this->pay(100000), TopUpHandler::START], $this->callbacks(0), 'the card, not the wallet itself — and back to the amounts');
        self::assertSame(0, Order::query()->count(), 'nothing is ordered before a way to pay is picked');
    }

    public function testATypedAmountIsHeldToTheBotsBoundsAndPersianDigitsAreFine(): void
    {
        $this->send($this->tap(TopUpHandler::START));
        $this->send($this->tap($this->inlineKeyboard(0)[2][0]['callback_data']));
        self::assertSame([self::text(BotText::TopupAsk, ['min' => Money::format(10000)])], $this->said());
        self::assertSame([TopUpHandler::START], $this->callbacks(0), 'back to the amounts');

        $invalid = [self::text(BotText::TopupInvalid, ['min' => Money::format(10000)])];
        foreach (['words' => 'پنج هزار', 'below the minimum' => '5000', 'above the most a top-up takes' => (string) (WalletSettings::TOPUP_MAX + 1)] as $why => $typed) {
            $this->send($this->message($typed));
            self::assertSame($invalid, $this->said(), $why);
        }

        $this->send($this->message('۱۵۰٬۰۰۰ تومان'));
        self::assertSame([self::text(BotText::TopupCheckout, ['amount' => Money::format(150000)])], $this->said(), 'still awaited, and read');
        self::assertSame([$this->pay(150000), TopUpHandler::START], $this->callbacks(0), "that amount's checkout");

        $this->send($this->message('200000'));
        self::assertNotContains(self::text(BotText::TopupCheckout, ['amount' => Money::format(200000)]), $this->said(), 'an amount is no longer awaited');
    }

    public function testAnAmountTypedOnTheAmountsScreenIsTakenAsItInvites(): void
    {
        $this->send($this->tap(TopUpHandler::START));
        self::assertSame([self::text(BotText::TopupPick, ['min' => Money::format(10000)])], $this->said(), 'a button, or an amount typed');

        $this->send($this->message('۷۵٬۰۰۰'));

        self::assertSame([self::text(BotText::TopupCheckout, ['amount' => Money::format(75000)])], $this->said(), 'its checkout, without «مبلغ دلخواه» first');
        self::assertSame([$this->pay(75000), TopUpHandler::START], $this->callbacks(0));
    }

    public function testTheMinimumAndPresetsComeFromTheBotSettings(): void
    {
        $this->botSettings('wallet', ['topup_min' => '25000', 'topup_presets' => '25000, ۷۵۰۰۰']);
        $this->send($this->tap(TopUpHandler::START));

        self::assertSame([self::text(BotText::TopupPick, ['min' => Money::format(25000)])], $this->said());
        self::assertSame([TopUpHandler::checkoutCallback(25000), TopUpHandler::checkoutCallback(75000)], array_column($this->inlineKeyboard(0)[0], 'callback_data'));

        // A preset below the minimum (an old button) shows the amounts again, and nothing is ordered.
        $this->send($this->tap($this->pay(20000)));
        self::assertSame([self::text(BotText::TopupPick, ['min' => Money::format(25000)])], $this->said());
        self::assertSame(0, Order::query()->count());
    }

    public function testPayingByCardAndTheBalanceLandingOnceTheReceiptIsAccepted(): void
    {
        Carbon::setTestNow('2026-09-18 10:00:00');
        $this->send($this->tap(TopUpHandler::checkoutCallback(50000)));

        // Pay with the card: the order is made, the card details shown, then the receipt.
        $this->send($this->tap($this->pay(50000)));
        self::assertStringContainsString('<code>6037997700001119</code>', $this->said()[0]);
        $order = Order::query()->where('type', OrderType::WalletTopUp->value)->sole();
        self::assertSame(['50000.00', OrderStatus::Pending], [$order->amount, $order->status]);
        $payment = Payment::query()->where('order_id', $order->id)->sole();

        // A picture sent as a file counts as a photo too — once its bytes say it is one.
        $this->telegram()->reply(['file_path' => 'documents/IMG_0001.png']);
        $this->telegram()->raw(new Response(200, [], (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true)));
        $this->send($this->message(null, ['document' => ['file_id' => 'receipt-1', 'file_name' => 'IMG_0001.png', 'mime_type' => 'image/png']]));
        self::assertSame([self::text(BotText::ReceiptReceivedTimed, ['outcome' => self::text(BotText::ReceiptOutcomeTopup), 'wait' => Persian::minutes(30)])], $this->said(), 'the customer is told what the receipt brings, and the longest wait');
        self::assertSame([PaymentStatus::AwaitingReview, 'receipt-1', 'IMG_0001.png'], [$payment->refresh()->status, $payment->receipt_file_id, $payment->receipt_name], 'what was sent, for the review screen');
        self::assertSame('0.00', $this->ali->balance(), 'nothing lands before the review');

        // The review window passes with nobody looking: accepted, credited, and the customer hears about it.
        Carbon::setTestNow('2026-09-18 10:31:00');
        $this->telegram()->reset();
        $this->service(AutoApproveReceiptsTask::class)->run();

        self::assertSame(OrderStatus::Fulfilled, $order->refresh()->status);
        self::assertSame('50000.00', $this->ali->balance());
        self::assertSame([self::text(BotText::WalletCharged, ['balance' => Messages::balance('50000.00')])], $this->telegram()->sentTo(self::CHAT));
        $line = WalletTransaction::query()->where('user_id', $this->ali->id)->sole();
        self::assertSame(WalletService::describeTopUp($order), $line->description);
    }

    public function testAReminderPayAgainOpensTheTopUpsCheckout(): void
    {
        $order = $this->topUpOrder($this->ali, '70000.00');

        $this->send($this->tap(PurchaseHandler::reopenCallback($order->id)));

        self::assertSame([self::text(BotText::TopupCheckout, ['amount' => Money::format(70000)])], $this->said());
        self::assertSame([$this->pay(70000), TopUpHandler::START], $this->callbacks(0));
    }

    public function testPickingTheCardAgainRestartsTheExpiryAndALateReceiptFindsThePaymentGone(): void
    {
        Carbon::setTestNow('2026-09-18 10:00:00');
        $this->send($this->tap($this->pay(50000)));
        $order = Order::query()->sole();
        $payment = Payment::query()->sole();

        // Picked again a day later: the same order, and its expiry counts from then.
        Carbon::setTestNow('2026-09-19 10:00:00');
        $this->send($this->tap($this->pay(50000)));
        self::assertSame([1, 1], [Order::query()->count(), Payment::query()->count()]);

        Carbon::setTestNow('2026-09-21 09:00:00');
        $this->service(ExpireOrdersTask::class)->run();
        self::assertSame(OrderStatus::Pending, $order->refresh()->status, 'not 48 hours since it was last picked');

        Carbon::setTestNow('2026-09-21 10:00:00');
        $this->service(ExpireOrdersTask::class)->run();
        self::assertSame([OrderStatus::Cancelled, OrderService::NOTE_EXPIRED], [$order->refresh()->status, $order->notes]);

        // A receipt sent now, the chat still waiting for one, finds the payment gone.
        $this->send($this->message(null, ['photo' => [['file_id' => 'late']]]));
        self::assertContains(self::text(BotText::OrderNotPending), $this->said());
        self::assertSame([PaymentStatus::Cancelled, null], [$payment->refresh()->status, $payment->receipt_file_id]);
    }

    /** The top-up checkout's button that pays `$amount` Toman with the card. */
    private function pay(int $amount): string
    {
        return CallbackData::build(TopUpHandler::checkoutCallback($amount), $this->card->id);
    }
}
