<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentMethods;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\Models\Server;
use App\Modules\Telegram\Handlers\MenuHandler;
use App\Modules\Telegram\Handlers\PurchaseHandler;
use App\Modules\Telegram\Handlers\TopUpHandler;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Users\Exceptions\InsufficientBalanceException;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\WalletService;
use App\Support\Money;
use App\Support\Traffic;
use Illuminate\Database\Events\QueryExecuted;
use Tests\BotTestCase;
use Tests\Fakes\FakeProvider;

/**
 * The customer's purchase in the bot: pick a plan, pick one of its servers by name, pick how to pay — the order is made
 * at that last tap, or the open one of the same plan and server found. The wallet pays a checkout once and the service
 * arrives as a fresh message; a wallet that cannot cover it says so before anything is ordered, and one spent in the
 * same moment refuses the payment with its reason; a payment whose delivery another process took is said paid, with
 * what comes; a card shows where to transfer (its receipt: BotReceiptTest). A checkout's buttons answer what is true
 * now: a plan gone, a way to pay switched off or its driver gone, a checkout the chat moved on from, an order closed or
 * no longer the customer's to pay.
 */
final class BotPurchaseFlowTest extends BotTestCase
{
    private User $ali;
    private Plan $plan;
    private Server $berlin;
    private Server $paris;
    private PaymentMethod $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakePanel();
        $this->withoutQr();

        $this->berlin = $this->fakeServer('آلمان');
        $this->paris = $this->fakeServer('فرانسه');
        $this->inbound($this->berlin, '1');
        $this->inbound($this->berlin, '2', ['protocol' => 'trojan']);
        $this->inbound($this->paris, '9');
        $this->plan = $this->plan(on: [$this->berlin, $this->paris]);
        $this->ali = $this->wallet($this->customer(), '200000.00');
        $this->wallet = $this->walletMethod();
    }

    public function testPlanShowsItsServersByNameThenCheckoutThenWalletPaymentDelivers(): void
    {
        $this->send($this->tap(PurchaseHandler::planCallback($this->plan->id)));
        self::assertSame([BotTexts::paragraphs($this->details(), self::text(BotText::PlanPickServer))], $this->said(), 'no description, no blank line for one');
        self::assertSame([
            [['text' => self::text(BotText::ServerButton, ['server' => 'آلمان']), 'callback_data' => $this->checkout($this->berlin)]],
            [['text' => self::text(BotText::ServerButton, ['server' => 'فرانسه']), 'callback_data' => $this->checkout($this->paris)]],
        ], array_slice($this->inlineKeyboard(0), 0, 2), 'a button per server, by name');

        $this->send($this->tap($this->checkout($this->paris)));
        self::assertSame([self::text(BotText::Checkout, ['plan' => 'یک‌ماهه', 'server' => 'فرانسه', 'amount' => Money::format('120000')])], $this->said());
        self::assertSame($this->wallet->label, $this->inlineKeyboard(0)[0][0]['text']);
        self::assertSame([$this->pay($this->paris, $this->wallet), PurchaseHandler::planCallback($this->plan->id)], $this->callbacks(0), 'a button per way to pay, and back to the plan');
        self::assertSame(0, Order::query()->count(), 'nothing is ordered before a way to pay is picked');

        $this->send($this->tap($this->pay($this->paris, $this->wallet)));
        self::assertSame(['deleteMessage', 'sendMessage', 'answerCallbackQuery'], $this->calls(), 'the checkout message goes; the service arrives as a new message');
        self::assertSame([self::text(BotText::PaySuccess, [
            'client' => 'USER_1',
            'plan' => 'یک‌ماهه',
            'server' => 'فرانسه',
            'duration' => Messages::duration(30),
            'traffic' => Messages::traffic(Traffic::bytesOfGb(30)),
            'subscription' => 'https://fake.test/sub/sub-1',
        ])], $this->said(), "the client's name on the panel and the panel's subscription link, each a code span a tap copies");
        self::assertSame(['9'], FakeProvider::$created[0]['inbounds'], 'the client went to the chosen server only');
        $order = Order::query()->sole();
        self::assertSame([OrderStatus::Fulfilled, $this->paris->id], [$order->status, $order->server_id], 'the order remembers the chosen server');
        self::assertSame('80000.00', $this->ali->balance());
    }

    public function testTheSameWalletButtonTwicePaysOnce(): void
    {
        $this->wallet($this->ali, '500000.00');
        $this->send($this->tap($this->checkout($this->berlin)));

        $this->send($this->tap($this->pay($this->berlin, $this->wallet)));
        $this->send($this->tap($this->pay($this->berlin, $this->wallet)));

        self::assertSame([self::text(BotText::OrderNotPending)], $this->said(), 'the checkout was paid by the first tap');
        self::assertSame([$this->checkout($this->berlin)], $this->callbacks(0), 'with the way back to a fresh checkout');
        self::assertSame(1, Order::query()->count());
        self::assertCount(1, FakeProvider::$created);
        self::assertSame('380000.00', $this->ali->balance());

        // From a checkout shown again, the same plan is bought again — that is the customer's call.
        $this->send($this->tap($this->checkout($this->berlin)));
        $this->send($this->tap($this->pay($this->berlin, $this->wallet)));
        self::assertSame(2, Order::query()->where('status', OrderStatus::Fulfilled->value)->count());
    }

    public function testAWalletButtonOfACheckoutTheChatMovedOnFromChargesNothing(): void
    {
        $this->send($this->tap($this->checkout($this->berlin)));
        $this->send($this->message('/start'));

        $this->send($this->tap($this->pay($this->berlin, $this->wallet)));

        self::assertSame([self::text(BotText::OrderNotPending)], $this->said());
        self::assertSame([$this->checkout($this->berlin)], $this->callbacks(0), 'the way back to a fresh checkout');
        self::assertSame(0, Order::query()->count(), 'nothing ordered');
        self::assertSame('200000.00', $this->ali->balance(), 'nothing charged');
    }

    public function testADescriptionIsItsOwnLineUnderTheName(): void
    {
        $plan = $this->plan(['description' => 'مناسب گوشی و لپ‌تاپ'], $this->berlin);

        $this->send($this->tap(PurchaseHandler::planCallback($plan->id)));

        self::assertSame(BotTexts::paragraphs($this->details("\nمناسب گوشی و لپ‌تاپ"), self::text(BotText::PlanPickServer)), $this->said()[0]);
    }

    public function testAPlanGoneSinceItsButtonsWereShownSaysSoAndOrdersNothing(): void
    {
        $this->plan->forceFill(['is_active' => false])->save();

        foreach (['its details' => PurchaseHandler::planCallback($this->plan->id), 'its checkout' => $this->checkout($this->berlin), 'paying it' => $this->pay($this->berlin, $this->wallet)] as $button => $data) {
            $this->send($this->tap($data));

            self::assertSame([self::text(BotText::PlanGone)], $this->said(), $button);
            self::assertSame([MainMenu::PLANS], $this->callbacks(0), "{$button}: back to the plans");
        }
        self::assertSame(0, Order::query()->count());
    }

    public function testAWayToPaySwitchedOffSinceTheCheckoutShowsTheCheckoutAsItIsNow(): void
    {
        $card = $this->cardMethod();
        $this->send($this->tap($this->checkout($this->berlin)));
        $this->service(PaymentMethods::class)->setEnabled($card, false);

        $this->send($this->tap($this->pay($this->berlin, $card)));

        self::assertSame([self::text(BotText::Checkout, ['plan' => 'یک‌ماهه', 'server' => 'آلمان', 'amount' => Money::format('120000')])], $this->said());
        self::assertSame([$this->pay($this->berlin, $this->wallet), PurchaseHandler::planCallback($this->plan->id)], $this->callbacks(0), 'without the card');
        self::assertSame(0, Order::query()->count());
    }

    public function testAWayToPayWhoseDriverIsNoLongerInstalledIsNeitherOfferedNorTaken(): void
    {
        $gone = $this->cardMethod('درگاه قدیمی', overrides: ['driver' => 'paypal']);

        $this->send($this->tap($this->checkout($this->berlin)));
        self::assertSame([$this->pay($this->berlin, $this->wallet), PurchaseHandler::planCallback($this->plan->id)], $this->callbacks(0), 'switched on, but nothing to pay with: not offered');

        // A button of it from before its driver went.
        $this->send($this->tap($this->pay($this->berlin, $gone)));
        self::assertSame([self::text(BotText::Checkout, ['plan' => 'یک‌ماهه', 'server' => 'آلمان', 'amount' => Money::format('120000')])], $this->said(), 'the checkout as it is now');
        self::assertSame(0, Order::query()->count(), 'nothing ordered');
    }

    public function testAWalletTapThatLosesTheOrderToAnotherProcessChargesNothing(): void
    {
        $this->send($this->tap($this->checkout($this->berlin)));

        // The order closes the moment the payment first reads it (a cancel from the panel in the same moment): the
        // order's claim inside the payment fails and unwinds the debit and the payment with it.
        $this->whileTheOrderClosesOnceRead(fn() => $this->send($this->tap($this->pay($this->berlin, $this->wallet))));

        self::assertSame([self::text(BotText::OrderNotPending)], $this->said());
        self::assertSame('200000.00', $this->ali->balance(), 'the debit was rolled back with the claim');
        self::assertSame(0, Payment::query()->count(), 'and the payment with it');
        self::assertSame([], FakeProvider::$created);
    }

    public function testAWalletPaymentWhoseDeliveryAnotherProcessTookSaysItIsPaidAndWhatComes(): void
    {
        $this->send($this->tap($this->checkout($this->berlin)));

        // The moment the payment marks the order paid, another process (support's retry, the resume task) takes its delivery.
        $taken = false;
        $this->whileListening(QueryExecuted::class, static function (QueryExecuted $query) use (&$taken): void {
            if (!$taken && str_starts_with($query->sql, 'update "orders"') && in_array(OrderStatus::Paid->value, $query->bindings, true)) {
                $taken = true;
                Order::query()->update(['status' => OrderStatus::Processing->value]);
            }
        }, fn() => $this->send($this->tap($this->pay($this->berlin, $this->wallet))));

        $order = Order::query()->sole();
        self::assertSame(OrderStatus::Processing, $order->status);
        self::assertSame([self::text(BotText::PayProcessing, ['order' => $order->id, 'outcome' => self::text(BotText::ReceiptOutcomeSubscription)])], $this->said(), 'paid, as it stands — never «پرداخت انجام نشد»');
        self::assertSame('80000.00', $this->ali->balance(), 'charged once');
        self::assertSame([], FakeProvider::$created, 'the delivery is the other process\'s');
    }

    public function testAWalletSpentBetweenTheCheckAndThePayRefusesThePaymentAndTheOrderStaysPayable(): void
    {
        $this->send($this->tap($this->checkout($this->berlin)));

        // The wallet covers the plan when the checkout checks it; the customer's other purchase spends it the moment
        // this order is made.
        $this->whileListening(
            'eloquent.created: ' . Order::class,
            fn() => $this->service(WalletService::class)->debit($this->ali, '150000', 'خرید دیگر'),
            fn() => $this->send($this->tap($this->pay($this->berlin, $this->wallet))),
        );

        $refusal = (new InsufficientBalanceException('0'))->getMessage();
        self::assertSame([self::text(BotText::PayFailed, ['reason' => $refusal])], $this->said(), "the wallet's refusal, in its words");
        self::assertContains($this->checkout($this->berlin), $this->callbacks(1), 'back to the checkout');
        $payment = Payment::query()->sole();
        self::assertSame([PaymentStatus::Failed, $refusal], [$payment->status, $payment->note], "the refusal is the payment's verdict");
        self::assertSame('50000.00', $this->ali->balance(), 'nothing debited');

        // The order may still be paid: the wallet topped up, back at the checkout, the same order is paid.
        $this->wallet($this->ali, '200000.00');
        $this->send($this->tap($this->checkout($this->berlin)));
        $this->send($this->tap($this->pay($this->berlin, $this->wallet)));
        self::assertSame([1, OrderStatus::Fulfilled], [Order::query()->count(), Order::query()->sole()->status]);
    }

    public function testWithSeveralGroupsTheCustomerPicksACategoryFirst(): void
    {
        $economy = $this->category(['name' => 'اقتصادی']);
        $hidden = $this->category(['name' => 'مخفی', 'is_active' => false]);
        $empty = $this->category(['name' => 'سازمانی']);
        $cheap = $this->plan(['name' => 'اقتصادی ۳۰ روزه', 'category_id' => $economy->id], $this->berlin);
        $off = $this->plan(['name' => 'پنهان', 'price' => '1.00', 'category_id' => $hidden->id], $this->berlin);

        $this->send($this->tap(MainMenu::PLANS));
        self::assertSame([self::text(BotText::CategoriesTitle)], $this->said());
        self::assertSame([
            [MenuHandler::categoryCallback($economy->id), 'اقتصادی'],
            [MenuHandler::categoryCallback($empty->id), 'سازمانی'],
            [MenuHandler::categoryCallback(null), self::text(BotText::CategoryOther)],
        ], array_map(static fn(array $row): array => [$row[0]['callback_data'], $row[0]['text']], $this->inlineKeyboard(0)), 'every active category in order — an empty one too —, then the plans of none or of one switched off');

        $this->send($this->tap(MenuHandler::categoryCallback($empty->id)));
        self::assertSame([self::text(BotText::CategoryEmpty, ['category' => 'سازمانی'])], $this->said());
        self::assertSame([MainMenu::PLANS], $this->callbacks(0), 'with a way back to the categories');

        $this->send($this->tap(MenuHandler::categoryCallback($economy->id)));
        self::assertSame([self::text(BotText::CategoryPlans, ['category' => 'اقتصادی'])], $this->said());
        self::assertSame([PurchaseHandler::planCallback($cheap->id), MainMenu::PLANS], $this->callbacks(0), 'its plans, and back to the categories');

        $this->send($this->tap(MenuHandler::categoryCallback(null)));
        self::assertSame([PurchaseHandler::planCallback($this->plan->id), PurchaseHandler::planCallback($off->id), MainMenu::PLANS], $this->callbacks(0), 'a plan whose category is off is still for sale, under "other"');

        $this->send($this->tap(PurchaseHandler::planCallback($cheap->id)));
        self::assertContains(MenuHandler::categoryCallback($economy->id), $this->callbacks(0), "back from the details lands on the plan's category");
    }

    public function testAPlanWhoseServersCannotDeliverIsNotShownAtAll(): void
    {
        $other = $this->plan(['name' => 'ویژه', 'price' => '300000.00'], $this->paris);
        $this->paris->forceFill(['serves_subscriptions' => false])->save();

        $this->send($this->tap(MainMenu::PLANS));
        self::assertContains(PurchaseHandler::planCallback($this->plan->id), $this->callbacks(0), 'still sold on Berlin');
        self::assertNotContains(PurchaseHandler::planCallback($other->id), $this->callbacks(0), 'only on a server without subscription links: hidden, not "unavailable"');

        $this->send($this->tap(PurchaseHandler::planCallback($this->plan->id)));
        self::assertContains($this->checkout($this->berlin), $this->callbacks(0));
        self::assertNotContains($this->checkout($this->paris), $this->callbacks(0), 'and Paris is not offered for the plan that has both');

        // A stale button of the hidden plan — its details, its payment — still answers gracefully.
        $this->send($this->tap(PurchaseHandler::planCallback($other->id)));
        self::assertSame([BotTexts::paragraphs($this->details(plan: $other), self::text(BotText::PlanNoServer))], $this->said());
        $this->send($this->tap(CallbackData::build(PurchaseHandler::serverCallback($other->id, $this->paris->id), $this->wallet->id)));
        self::assertSame([self::text(BotText::PlanNoServer)], $this->said());
        self::assertSame(0, Order::query()->count());

        $this->berlin->forceFill(['serves_subscriptions' => false])->save();
        $this->send($this->tap(MainMenu::PLANS));
        self::assertSame([self::text(BotText::PlansEmpty)], $this->said(), 'nothing sellable: the shop is empty');
    }

    public function testWithOneGroupThePlansShowDirectly(): void
    {
        $this->send($this->tap(MainMenu::PLANS));

        self::assertSame([self::text(BotText::PlansTitle)], $this->said());
        self::assertSame([PurchaseHandler::planCallback($this->plan->id)], $this->callbacks(0), 'no categories to pick from');
    }

    public function testAShortWalletIsExplainedBeforeAnythingIsOrdered(): void
    {
        $this->wallet($this->ali, '50000.00');
        $this->send($this->tap($this->checkout($this->berlin)));

        $this->send($this->tap($this->pay($this->berlin, $this->wallet)));

        self::assertSame([self::text(BotText::PayInsufficient, [
            'balance' => Messages::balance('50000.00'),
            'amount' => Money::format('120000'),
            'missing' => Money::format('70000'),
        ])], $this->said());
        self::assertSame([TopUpHandler::START, $this->checkout($this->berlin)], $this->callbacks(0), 'top up, or back to the checkout');
        self::assertSame([0, 0], [Order::query()->count(), Payment::query()->count()]);
    }

    public function testACardShowsWhereToTransferWithTheWayBackAndTheSameCardFindsTheSamePayment(): void
    {
        $mellat = $this->cardMethod();
        $melli = $this->cardMethod('کارت به کارت (ملی)', 90, ['config' => ['card_number' => '5892101012345670', 'card_holder' => 'Reza <&>', 'instructions' => 'فقط از حساب خودتان']]);
        $this->cardMethod('کارت قدیمی', 0, ['enabled' => false]);

        $this->send($this->tap($this->checkout($this->berlin)));
        self::assertSame([$this->pay($this->berlin, $this->wallet), $this->pay($this->berlin, $mellat), $this->pay($this->berlin, $melli), PurchaseHandler::planCallback($this->plan->id)], $this->callbacks(0), 'every method switched on is its own button, in checkout order');

        $this->send($this->tap($this->pay($this->berlin, $melli)));
        self::assertSame([BotTexts::paragraphs(trim(self::text(BotText::CardInstructions, [
            'amount' => Money::format('120000'),
            'card' => '5892101012345670',
            'holder' => 'Reza &lt;&amp;&gt;',
            'instructions' => 'فقط از حساب خودتان',
        ])), self::text(BotText::PayInstructionsSuffix))], $this->said(), "the chosen card, and its holder's name as text — never as markup");
        self::assertSame([$this->checkout($this->berlin)], $this->callbacks(0), 'back to the checkout to pick another way — no «انصراف»');
        $order = Order::query()->sole();
        $payment = Payment::query()->sole();
        self::assertSame([OrderStatus::Pending, PaymentStatus::Pending, $melli->id], [$order->status, $payment->status, $payment->payment_method_id], 'the payment remembers which card');

        // The same card again — a second tap, or back and the same card — finds the same order and payment.
        $this->send($this->tap($this->pay($this->berlin, $melli)));
        self::assertSame([1, 1], [Order::query()->count(), Payment::query()->count()]);

        // Its receipt with support, a checkout of the same plan makes a new order: the first is not paid twice.
        $this->receipt($payment);
        $this->send($this->tap($this->checkout($this->berlin)));
        $this->send($this->tap($this->pay($this->berlin, $this->wallet)));
        self::assertSame(2, Order::query()->count());
        self::assertSame(OrderStatus::Pending, $order->refresh()->status, 'the first one waits for support');
    }

    public function testPayingAnotherWayFindsTheOpenOrderAndTheCardLeftBehindGoesWithIt(): void
    {
        $card = $this->cardMethod();
        $this->send($this->tap($this->checkout($this->berlin)));
        $this->send($this->tap($this->pay($this->berlin, $card)));
        $cardPayment = Payment::query()->sole();

        $this->send($this->tap($this->checkout($this->berlin)));
        $this->send($this->tap($this->pay($this->berlin, $this->wallet)));

        $order = Order::query()->sole();
        self::assertSame(OrderStatus::Fulfilled, $order->status, 'the open order of the same plan and server, paid from the wallet');
        self::assertSame([PaymentStatus::Cancelled, PaymentService::NOTE_PAID_OTHERWISE], [$cardPayment->refresh()->status, $cardPayment->note]);
    }

    public function testTheReminderPayAgainOpensTheCheckoutWhileTheOrderMayStillBePaid(): void
    {
        $order = $this->purchaseOrder($this->ali, $this->plan, $this->berlin);

        $this->send($this->tap(PurchaseHandler::reopenCallback($order->id)));
        self::assertSame([self::text(BotText::Checkout, ['plan' => 'یک‌ماهه', 'server' => 'آلمان', 'amount' => Money::format('120000')])], $this->said());
        self::assertContains($this->pay($this->berlin, $this->wallet), $this->callbacks(0));

        $this->send($this->tap($this->pay($this->berlin, $this->wallet)));
        self::assertSame(OrderStatus::Fulfilled, $order->refresh()->status, 'paying it found that very order');
        self::assertSame(1, Order::query()->count());

        $this->send($this->tap(PurchaseHandler::reopenCallback($order->id)));
        self::assertSame([self::text(BotText::OrderNotPending)], $this->said(), 'a paid order is not offered again');
    }

    public function testPayAgainIsRefusedForAnOrderWithItsReceiptWithSupportCancelledOrSomeoneElses(): void
    {
        $withSupport = $this->purchaseOrder($this->ali, $this->plan, $this->berlin);
        $this->receipt($this->cardPayment($withSupport, $this->cardMethod()));
        $cancelled = $this->purchaseOrder($this->ali, $this->plan, $this->berlin, ['status' => OrderStatus::Cancelled]);
        $someoneElses = $this->purchaseOrder($this->customer(['telegram_id' => 9999]), $this->plan, $this->berlin);

        foreach (['its receipt is with support' => $withSupport, 'cancelled' => $cancelled, "someone else's" => $someoneElses] as $why => $order) {
            $this->send($this->tap(PurchaseHandler::reopenCallback($order->id)));

            self::assertSame([self::text(BotText::OrderNotPending)], $this->said(), $why);
        }
        self::assertSame(3, Order::query()->count());
    }

    /** The checkout of the plan on that server. */
    private function checkout(Server $server): string
    {
        return PurchaseHandler::serverCallback($this->plan->id, $server->id);
    }

    /** The checkout's button that pays the plan on that server with that method. */
    private function pay(Server $server, PaymentMethod $method): string
    {
        return CallbackData::build($this->checkout($server), $method->id);
    }

    /** The details of the plan (the fixtures' one-month plan unless told otherwise) as the bot words them. */
    private function details(string $description = '', ?Plan $plan = null): string
    {
        $plan ??= $this->plan;

        return self::text(BotText::PlanDetails, [
            'plan' => $plan->name,
            'description' => $description,
            'traffic' => Messages::traffic($plan->trafficBytes()),
            'duration' => Messages::duration($plan->duration_days),
            'devices' => Messages::devices($plan->ip_limit),
            'price' => Money::format($plan->price),
        ]);
    }
}
