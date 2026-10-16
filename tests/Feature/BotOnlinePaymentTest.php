<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Notifications\Enums\NoticeType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Drivers\CubePay\CubePayGateway;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\GatewayUnavailableException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\OnlinePayments;
use App\Modules\Payments\Services\PaymentActions;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Telegram\Handlers\OnlinePaymentHandler;
use App\Modules\Telegram\Handlers\PurchaseHandler;
use App\Modules\Telegram\Handlers\ReceiptHandler;
use App\Modules\Telegram\Handlers\TopUpHandler;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Models\TelegramSession;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Users\Models\User;
use App\Support\Money;
use App\Support\Persian;
use Illuminate\Support\Carbon;
use Tests\BotTestCase;
use Tests\Support\FakeAban;
use Tests\Support\FakeAtlasPay;
use Tests\Support\FakeCubePay;

/**
 * An online gateway's payment in the bot, mostly through CubePay: a way to pay picked shows the page — «💳 پرداخت» to it,
 * the exact sum it asks (a few Toman over the order's, a fee the customer pays), until when it takes money — with
 * «🔄 بررسی پرداخت» and «بازگشت»; the card a gateway gives to show in the bot comes under it, protected, with its copy
 * buttons, its holder and bank when named. «بررسی پرداخت» asks the gateway — once in a few seconds —: nothing yet, paid
 * (settled once, the customer told once by the notice, the page left without its buttons), paid already, held for
 * support (never asked again, the order paid no other way), the gateway out of reach, or the page's time over (a word,
 * with the way back to the order's checkout and a new page — or, its order closed, a word saying so). Nobody else's
 * payment is asked about, and no receipt is ever taken for one. Zarinpal's, AtlasPay's and AbanGateway's pages too.
 */
final class BotOnlinePaymentTest extends BotTestCase
{
    private Server $server;
    private Plan $plan;
    private User $ali;
    private PaymentMethod $online;
    private FakeCubePay $cubePay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['app.url' => 'https://shop.example']);
        $this->cubePay = $this->cubePay();
        $this->server = $this->sellingServer();
        $this->plan = $this->plan(on: $this->server);
        $this->ali = $this->customer();
        $this->online = $this->cubePayMethod();
    }

    public function testAPlansPageSaysTheExactSumItAsksAndUntilWhen(): void
    {
        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->online)));

        $payment = Payment::query()->sole();
        self::assertSame('editMessageText', $this->calls()[0], 'in place of the checkout');
        self::assertArrayNotHasKey('protect_content', $this->params(0));
        self::assertSame([$this->page($payment, '120037', BotText::ReceiptOutcomeSubscription)], $this->said());
        self::assertSame([
            [['text' => self::text(BotText::PayOnlineButton), 'url' => (string) $payment->link]],
            [['text' => self::text(BotText::PayOnlineCheck), 'callback_data' => OnlinePaymentHandler::checkCallback($payment)]],
            [['text' => self::text(BotText::Back), 'callback_data' => $this->checkoutOf($this->plan, $this->server)]],
        ], $this->inlineKeyboard(0));
        self::assertSame(120000, $this->cubePay->callsTo(CubePayGateway::CREATE)[0]['body']['price_amount'], 'CubePay is asked the order\'s amount');
        self::assertSame([PaymentStatus::Pending, '120000.00'], [$payment->status, $payment->amount]);
        self::assertNull(TelegramSession::of(self::CHAT)?->state, 'no step awaits anything: no receipt');
    }

    public function testAFeePassedOnToTheCustomerIsSaidOnThePage(): void
    {
        $this->online->forceFill(['config' => ['fee_percent' => '3', 'fee_fixed' => 500] + $this->online->config])->save();

        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->online)));

        // 3% of 120,000 and 500: CubePay is asked 124,100, and its page asks its few Toman more.
        self::assertSame(124100, $this->cubePay->callsTo(CubePayGateway::CREATE)[0]['body']['price_amount']);
        $said = $this->said()[0];
        self::assertStringContainsString(Money::format('124137'), $said);
        self::assertStringContainsString(self::text(BotText::PayOnlineFee, ['fee' => Money::format('4100')]), $said);
        self::assertSame('120000.00', Payment::query()->sole()->amount, 'the order is paid at its price');
    }

    public function testATopUpsPageSaysWhatItBrings(): void
    {
        $this->send($this->tap(CallbackData::build(TopUpHandler::checkoutCallback(100000), $this->online->id)));

        $payment = Payment::query()->sole();
        self::assertSame([$this->page($payment, '100037', BotText::ReceiptOutcomeTopup)], $this->said());
        self::assertSame([OnlinePaymentHandler::checkCallback($payment), TopUpHandler::checkoutCallback(100000)], $this->callbacks(0));
    }

    public function testTheCardCubePayShowsInTheBotComesProtectedWithItsCopyButtons(): void
    {
        $this->cubePay->showCard = true;

        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->online)));

        $payment = Payment::query()->sole();
        self::assertSame(['deleteMessage', 'sendMessage'], array_slice($this->calls(), 0, 2), 'a protected message is sent, never edited in');
        self::assertSame('true', $this->params(1)['protect_content'] ?? null);
        self::assertSame([BotTexts::paragraphs($this->page($payment, '120037', BotText::ReceiptOutcomeSubscription), self::text(BotText::PayOnlineCard, [
            'card' => FakeCubePay::CARD,
            'card_holder' => self::text(BotText::PayOnlineCardHolder, ['holder' => 'CubePay Holder']),
            'bank' => '',
            'amount_digits' => '120037',
        ]))], $this->said());
        self::assertStringContainsString('تا آخرین رقم', $this->said()[0]);
        self::assertSame([
            [['text' => self::text(BotText::PayOnlineButton), 'url' => (string) $payment->link]],
            [
                ['text' => self::text(BotText::CardCopyAmount), 'copy_text' => ['text' => '120037']],
                ['text' => self::text(BotText::CardCopyNumber), 'copy_text' => ['text' => FakeCubePay::CARD]],
            ],
            [['text' => self::text(BotText::PayOnlineCheck), 'callback_data' => OnlinePaymentHandler::checkCallback($payment)]],
            [['text' => self::text(BotText::Back), 'callback_data' => $this->checkoutOf($this->plan, $this->server)]],
        ], $this->inlineKeyboard(1), 'the exact sum copied, not the order\'s');

        // Unprotected, it is edited in like the page alone.
        $this->botSettings('payments', ['card_protect' => false]);
        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->online)));
        self::assertSame('editMessageText', $this->calls()[0]);
        self::assertArrayNotHasKey('protect_content', $this->params(0));
        self::assertStringContainsString(FakeCubePay::CARD, $this->said()[0]);
        self::assertSame(1, Payment::query()->count(), 'the same page');
    }

    public function testCheckingBeforePayingSaysNothingCameYetAndAsksOnceInAFewSeconds(): void
    {
        $payment = $this->onlinePage();

        $this->send($this->tap(OnlinePaymentHandler::checkCallback($payment)));
        self::assertSame(self::text(BotText::PayOnlineWaiting), $this->popup());
        self::assertSame([], $this->said(), 'the page stays as it is');
        self::assertCount(1, $this->cubePay->callsTo(CubePayGateway::VERIFY));

        // Tapped again at once: CubePay is not asked again.
        $this->send($this->tap(OnlinePaymentHandler::checkCallback($payment)));
        self::assertSame(self::text(BotText::PayOnlineWaiting), $this->popup());
        self::assertCount(1, $this->cubePay->callsTo(CubePayGateway::VERIFY));

        Carbon::setTestNow(now()->addSeconds(6));
        $this->send($this->tap(OnlinePaymentHandler::checkCallback($payment)));
        self::assertCount(2, $this->cubePay->callsTo(CubePayGateway::VERIFY));
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);
    }

    public function testCheckingAPageWhoseOrderClosedSaysSoInsteadOfInvitingAPayment(): void
    {
        $payment = $this->onlinePage();
        $this->service(PaymentService::class)->cancelWithOrder($payment, 'admin', null);
        $this->telegram()->reset();

        $this->send($this->tap(OnlinePaymentHandler::checkCallback($payment)));

        self::assertSame([self::text(BotText::OrderNotPending)], $this->said(), 'nothing came, and nothing is to be paid there');
        self::assertCount(1, $this->cubePay->callsTo(CubePayGateway::VERIFY), 'CubePay is asked all the same: money paid on it would go to the wallet');
    }

    public function testCheckingAfterPayingDeliversOnceAndTellsOnce(): void
    {
        $payment = $this->onlinePage();
        $this->cubePay->pay((string) $payment->reference);

        $this->send($this->tap(OnlinePaymentHandler::checkCallback($payment)));

        self::assertSame(self::text(BotText::PayOnlinePaid), $this->popup());
        $payment->refresh();
        self::assertSame([PaymentStatus::Paid, OrderStatus::Fulfilled], [$payment->status, $payment->order->status]);
        self::assertSame(1, Subscription::query()->count());
        $said = $this->said();
        self::assertCount(2, $said, 'the delivery, as the notice of a settled payment, and the page saying it was paid');
        self::assertSame(self::text(BotText::PayOnlineDone, ['order' => $payment->order_id]), $said[1]);
        $edit = array_search('editMessageText', $this->calls(), true);
        self::assertIsInt($edit);
        self::assertSame([], $this->inlineKeyboard($edit), 'nothing left on the page to pay with');
        self::assertSame(1, Notification::query()->where('type', NoticeType::PaymentSettled->value)->count());

        // CubePay's callback comes after, and the customer taps again: paid already, nothing twice.
        $this->service(OnlinePayments::class)->check($payment);
        Carbon::setTestNow(now()->addSeconds(6));
        $verified = count($this->cubePay->callsTo(CubePayGateway::VERIFY));
        $this->send($this->tap(OnlinePaymentHandler::checkCallback($payment)));
        self::assertSame(self::text(BotText::PayOnlineAlreadyPaid), $this->popup());
        self::assertSame([self::text(BotText::PayOnlineDone, ['order' => $payment->order_id])], $this->said(), 'the page it was tapped on, paid');
        self::assertCount($verified, $this->cubePay->callsTo(CubePayGateway::VERIFY), 'a paid payment is not asked about');
        self::assertSame(1, Notification::query()->where('type', NoticeType::PaymentSettled->value)->count());
        self::assertSame(1, Subscription::query()->count());
    }

    public function testATopUpPaidIsCreditedOnce(): void
    {
        $this->send($this->tap(CallbackData::build(TopUpHandler::checkoutCallback(100000), $this->online->id)));
        $payment = Payment::query()->sole();
        $this->cubePay->pay((string) $payment->reference);

        $this->send($this->tap(OnlinePaymentHandler::checkCallback($payment)));
        Carbon::setTestNow(now()->addSeconds(6));
        $this->send($this->tap(OnlinePaymentHandler::checkCallback($payment)));

        self::assertSame(self::text(BotText::PayOnlineAlreadyPaid), $this->popup());
        self::assertSame('100000.00', $this->ali->balance(), 'the top-up, not the Toman CubePay added');
    }

    public function testAPageWhoseTimeRanOutSaysSoAndLeadsBackToANewPage(): void
    {
        $payment = $this->onlinePage();
        $this->cubePay->close((string) $payment->reference);

        $this->send($this->tap(OnlinePaymentHandler::checkCallback($payment)));

        self::assertSame([self::text(BotText::PayOnlineClosed, ['order' => $payment->order_id])], $this->said());
        self::assertSame('editMessageText', $this->calls()[0]);
        self::assertSame([PurchaseHandler::reopenCallback($payment->order_id)], $this->callbacks(0));
        self::assertSame(PaymentStatus::Failed, $payment->refresh()->status);
        self::assertSame(OrderStatus::Pending, $payment->order->status, 'the order is still theirs to pay');

        // «بازگشت»: the order's checkout, and a new page on it.
        $this->send($this->tap(PurchaseHandler::reopenCallback($payment->order_id)));
        self::assertContains($this->payButton($this->plan, $this->server, $this->online), $this->callbacks(0));
        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->online)));
        $again = Payment::query()->latest('id')->firstOrFail();
        self::assertNotSame($payment->id, $again->id);
        self::assertSame($payment->order_id, $again->order_id, 'the same order');
        self::assertSame((string) $again->link, $this->inlineKeyboard(0)[0][0]['url'] ?? null);
        self::assertCount(2, $this->cubePay->callsTo(CubePayGateway::CREATE));
    }

    public function testAGatewayThatWillNotOpenThePageSaysSoAndNothingIsPaid(): void
    {
        $this->wallet($this->ali, '500000.00');
        $this->cubePay->refuse(402, 'موجودی کیف‌پول کارمزد کافی نیست.');

        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->online)));

        self::assertSame([self::text(BotText::PayFailed, ['reason' => GatewayUnavailableException::UNAVAILABLE])], $this->said(), 'CubePay\'s own reason is the owner\'s, not the customer\'s');
        self::assertSame([$this->checkoutOf($this->plan, $this->server)], $this->callbacks(1));
        $payment = Payment::query()->sole();
        self::assertSame([PaymentStatus::Failed, OrderStatus::Pending], [$payment->status, $payment->order->status]);
        self::assertSame(0, Subscription::query()->count());
        self::assertSame('500000.00', $this->ali->balance());
    }

    public function testAGatewayOutOfReachIsSaidInAPopupAndNothingChanges(): void
    {
        $payment = $this->onlinePage();
        $this->cubePay->down();

        $this->send($this->tap(OnlinePaymentHandler::checkCallback($payment)));

        self::assertSame(self::text(BotText::PayOnlineUnreachable), $this->popup(), 'not the checkout\'s «another way to pay»: they may have paid');
        self::assertNotSame(GatewayUnavailableException::UNAVAILABLE, $this->popup());
        self::assertSame([], $this->said());
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);
    }

    public function testAPaymentHeldForSupportSendsTheCustomerToSupportAndIsNeverAskedAgain(): void
    {
        $payment = $this->onlinePage();
        // CubePay takes less than it was asked: the answer does not fit the payment.
        $order = (string) $payment->invoice['order_id'];
        $this->cubePay->orders[$order]['price'] -= 1000;
        $this->cubePay->pay((string) $payment->reference);

        $this->send($this->tap(OnlinePaymentHandler::checkCallback($payment)));

        self::assertSame(self::text(BotText::PayOnlineHeld), $this->popup(), 'this very ask held it');
        self::assertTrue(OnlinePayments::isHeld($payment->refresh()));
        self::assertSame([PaymentStatus::Pending, OrderStatus::Pending], [$payment->status, $payment->order->status]);
        self::assertSame([], $this->said());

        // Tapped again — at once, and later —: the same words, and CubePay is never asked again.
        $verified = count($this->cubePay->callsTo(CubePayGateway::VERIFY));
        $this->send($this->tap(OnlinePaymentHandler::checkCallback($payment)));
        self::assertSame(self::text(BotText::PayOnlineHeld), $this->popup(), 'not «nothing came yet»');
        Carbon::setTestNow(now()->addMinutes(5));
        $this->send($this->tap(OnlinePaymentHandler::checkCallback($payment)));
        self::assertSame(self::text(BotText::PayOnlineHeld), $this->popup());
        self::assertCount($verified, $this->cubePay->callsTo(CubePayGateway::VERIFY));
        self::assertSame(0, Subscription::query()->count());
    }

    /**
     * Paying an order again — its checkout drawn again, any way to pay — while its online payment is held for support
     * pays nothing: the customer is sent to support. (The refusal is the checkout's: Checkout::pay().)
     */
    public function testAnOrderWhosePaymentIsHeldIsPaidNoOtherWay(): void
    {
        $this->botSettings('general', ['support_contact' => '@amo_support']);
        $payment = $this->onlinePage();
        $payment->forceFill(['invoice' => ['held' => 'CubePay verified another amount'] + (array) $payment->invoice, 'note' => PaymentService::NOTE_HELD])->save();
        $this->wallet($this->ali, '500000.00');

        $this->send($this->tap(PurchaseHandler::reopenCallback($payment->order_id)));
        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->walletMethod())));

        self::assertSame([htmlspecialchars(self::text(BotText::PayOnlineHeld))], $this->said());
        self::assertSame([
            [['text' => self::text(BotText::CardToSupportButton), 'url' => 'https://t.me/amo_support']],
            [['text' => self::text(BotText::Back), 'callback_data' => $this->checkoutOf($this->plan, $this->server)]],
        ], $this->inlineKeyboard(0));
        self::assertSame('500000.00', $this->ali->balance(), 'nothing charged');
        self::assertSame(1, Payment::query()->count(), 'nothing paid another way');
        self::assertSame(OrderStatus::Pending, $payment->order->refresh()->status);

        // Nor a new page of the gateway it is held on.
        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->online)));
        self::assertSame([htmlspecialchars(self::text(BotText::PayOnlineHeld))], $this->said());
        self::assertCount(1, $this->cubePay->callsTo(CubePayGateway::CREATE));
    }

    public function testAClosedPageOfAnOrderPaidAnotherWaySaysTheOrderIsClosed(): void
    {
        $payment = $this->onlinePage();
        $this->wallet($this->ali, '500000.00');
        $this->send($this->tap($this->checkoutOf($this->plan, $this->server)));
        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->walletMethod())));
        self::assertSame(OrderStatus::Fulfilled, $payment->order->refresh()->status, 'paid by the wallet meanwhile');
        $this->cubePay->close((string) $payment->reference);

        $this->send($this->tap(OnlinePaymentHandler::checkCallback($payment)));

        self::assertSame([self::text(BotText::OrderNotPending)], $this->said(), 'not «choose a way to pay again»');
        self::assertSame([MainMenu::HOME], $this->callbacks(0));
    }

    public function testAZarinpalPageSaysTheSumWithItsFeeAndTakesNoCard(): void
    {
        $zarinpal = $this->zarinpal();
        $zarinpal->payerFee = true;
        $method = $this->zarinpalMethod();

        $this->send($this->tap($this->payButton($this->plan, $this->server, $method)));

        $payment = Payment::query()->sole();
        $link = $this->inlineKeyboard(0)[0][0]['url'] ?? '';
        self::assertSame((string) $payment->link, $link);
        self::assertStringContainsString('zarinpal.com', $link);
        // Zarinpal's 1,000 Rial of fee, the customer's to pay: 100 Toman on the plan's 120,000.
        $said = $this->said()[0];
        self::assertStringContainsString(Money::format('120100'), $said);
        self::assertStringContainsString(self::text(BotText::PayOnlineFee, ['fee' => Money::format('100')]), $said);
        self::assertSame('120000.00', $payment->amount, 'the order is paid at its price');
        self::assertSame([
            [['text' => self::text(BotText::PayOnlineButton), 'url' => $link]],
            [['text' => self::text(BotText::PayOnlineCheck), 'callback_data' => OnlinePaymentHandler::checkCallback($payment)]],
            [['text' => self::text(BotText::Back), 'callback_data' => $this->checkoutOf($this->plan, $this->server)]],
        ], $this->inlineKeyboard(0));
    }

    /**
     * AtlasPay's page is its mini app in Telegram; the card it gives (the merchant's direct payment info) comes under it
     * with its holder and bank. Paid short, the customer having accepted it, the payment is held for support.
     */
    public function testAnAtlasPayPageShowsItsCardWithHolderAndBankAndAnUnderpaymentIsHeld(): void
    {
        $atlasPay = $this->atlasPay();
        $atlasPay->directInfo = true;
        $method = $this->atlasPayMethod();

        $this->send($this->tap($this->payButton($this->plan, $this->server, $method)));

        $payment = Payment::query()->sole();
        self::assertStringStartsWith('https://t.me/', $this->inlineKeyboard(1)[0][0]['url'] ?? '', 'its mini app');
        self::assertStringContainsString(self::text(BotText::PayOnlineCard, [
            'card' => FakeAtlasPay::CARD,
            'card_holder' => self::text(BotText::PayOnlineCardHolder, ['holder' => 'Ali Rezaei']),
            'bank' => self::text(BotText::CardBank, ['bank_name' => 'Bank Melli']),
            'amount_digits' => (string) (120000 + FakeAtlasPay::MARKUP),
        ]), $this->said()[0]);

        $atlasPay->payShort($atlasPay->onlyOrder(), 100000);
        $this->send($this->tap(OnlinePaymentHandler::checkCallback($payment)));

        self::assertSame(self::text(BotText::PayOnlineHeld), $this->popup());
        self::assertTrue(OnlinePayments::isHeld($payment->refresh()));
        self::assertSame(0, Subscription::query()->count(), 'support settles it with the customer');
    }

    /** AbanGateway's card comes with its holder and bank; money that came in part is under the gateway's review. */
    public function testAnAbanPageShowsItsCardAndMoneyInPartWaits(): void
    {
        $aban = $this->aban();
        $method = $this->abanMethod();

        $this->send($this->tap($this->payButton($this->plan, $this->server, $method)));

        $payment = Payment::query()->sole();
        $said = $this->said()[0];
        self::assertStringContainsString(FakeAban::CARD, $said);
        self::assertStringContainsString(self::text(BotText::PayOnlineCardHolder, ['holder' => 'سارا رضایی']), $said);
        self::assertStringContainsString(self::text(BotText::CardBank, ['bank_name' => 'ملی']), $said);
        self::assertSame((string) $payment->link, $this->inlineKeyboard(1)[0][0]['url'] ?? null);

        $aban->payPart($aban->onlyInvoice());
        $this->send($this->tap(OnlinePaymentHandler::checkCallback($payment)));

        // Money in at the gateway, not settled yet: under its review, not to be paid again.
        self::assertSame(self::text(BotText::PayOnlineReviewing), $this->popup());
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);
    }

    public function testAnotherCustomersPaymentIsNotAskedAbout(): void
    {
        $this->customer(['telegram_id' => 4242, 'first_name' => 'Sara']);
        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->online), chat: 4242));
        $saras = Payment::query()->sole();
        $this->cubePay->pay((string) $saras->reference);
        $asked = count($this->cubePay->calls);

        $this->send($this->tap(OnlinePaymentHandler::checkCallback($saras)));

        self::assertNull($this->popup());
        self::assertSame(['answerCallbackQuery'], $this->calls(), 'the spinner stopped, nothing more');
        self::assertCount($asked, $this->cubePay->calls);
        self::assertSame(PaymentStatus::Pending, $saras->refresh()->status);

        // Nor a card payment's id under the online prefix.
        $card = $this->cardPayment($this->topUpOrder($this->ali, '50000.00'), $this->cardMethod());
        $this->send($this->tap(OnlinePaymentHandler::checkCallback($card)));
        self::assertSame(['answerCallbackQuery'], $this->calls());
    }

    public function testReopeningAPendingOrderShowsItsPageAgainAndANewOneOnceItIsAboutToClose(): void
    {
        $first = $this->onlinePage();

        $this->send($this->tap(PurchaseHandler::reopenCallback($first->order_id)));
        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->online)));
        self::assertSame((string) $first->link, $this->inlineKeyboard(0)[0][0]['url'] ?? null, 'its page again');
        self::assertSame(OnlinePaymentHandler::checkCallback($first), $this->inlineKeyboard(0)[1][0]['callback_data'] ?? null);
        self::assertCount(1, $this->cubePay->callsTo(CubePayGateway::CREATE));

        // Two minutes left on it: too little to pay on, so a new page.
        Carbon::setTestNow(now()->addMinutes(28));
        $this->send($this->tap(PurchaseHandler::reopenCallback($first->order_id)));
        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->online)));
        $second = Payment::query()->latest('id')->firstOrFail();
        self::assertNotSame($first->id, $second->id);
        self::assertSame((string) $second->link, $this->inlineKeyboard(0)[0][0]['url'] ?? null);
        self::assertSame(OnlinePaymentHandler::checkCallback($second), $this->inlineKeyboard(0)[1][0]['callback_data'] ?? null);
        self::assertCount(2, $this->cubePay->callsTo(CubePayGateway::CREATE));
        self::assertSame(1, Order::query()->count());
    }

    public function testNoReceiptIsTakenAndNoReminderOfferedForAnOnlinePayment(): void
    {
        $payment = $this->onlinePage();

        $this->send($this->tap(ReceiptHandler::stateFor($payment)));
        self::assertSame([self::text(BotText::OrderNotPending)], $this->said());
        $this->send($this->message(null, ['photo' => [['file_id' => 'receipt-1']]]));

        $payment->refresh();
        self::assertSame([PaymentStatus::Pending, null], [$payment->status, $payment->receipt_file_id]);
        self::assertFalse($this->service(PaymentActions::class)->allowed($payment)['remind'], 'a reminder is a card payment\'s');
    }

    /** The plan bought with CubePay in the bot: its payment, its page shown. */
    private function onlinePage(): Payment
    {
        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->online)));

        return Payment::query()->latest('id')->firstOrFail();
    }

    /** What the page of `$payment` says: its order, the sum CubePay asks, until when, and what comes once paid. */
    private function page(Payment $payment, string $asked, BotText $outcome): string
    {
        self::assertNotNull($payment->link_expires_at);

        return self::text(BotText::PayOnline, [
            'order' => $payment->order_id,
            'amount' => Money::format($asked),
            'gateway_fee' => '',
            'deadline' => self::text(BotText::PayOnlineDeadline, ['until' => Persian::date($payment->link_expires_at, withTime: true)]),
            'outcome' => self::text($outcome),
        ]);
    }
}
