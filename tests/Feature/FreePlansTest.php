<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\Models\Server;
use App\Modules\Telegram\Handlers\PurchaseHandler;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Users\Models\WalletTransaction;
use Tests\BotTestCase;
use Tests\Fakes\FakeProvider;

/**
 * Free plans (price 0): the checkout offers one «🎁 دریافت رایگان» button and the wallet row settles the order on the spot
 * — no ledger line, whichever button was pressed and whether the wallet is switched on —, once however often it is pressed;
 * a refund gives nothing back.
 */
final class FreePlansTest extends BotTestCase
{
    private Server $server;
    private Plan $test;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutQr();
        $this->server = $this->sellingServer();
        $this->test = $this->plan(['name' => 'تست رایگان', 'price' => '0.00', 'duration_hours' => 1, 'traffic_gb' => 1], $this->server);
        $this->customer();
    }

    public function testAFreePlanIsTakenWithOneButtonAndNothingCharged(): void
    {
        $this->cardMethod();

        $take = $this->checkout($this->test);
        self::assertSame([[['text' => self::text(BotText::CheckoutFree), 'callback_data' => $take]]], array_slice($this->inlineKeyboard(0), 0, 1), 'one button instead of the ways to pay');
        self::assertCount(2, $this->inlineKeyboard(0), 'and the way back');

        $this->send($this->tap($take));

        self::assertSame(OrderStatus::Fulfilled, Order::query()->sole()->status);
        $payment = Payment::query()->sole();
        self::assertSame([PaymentStatus::Paid, '0.00', $this->walletMethod()->id], [$payment->status, $payment->amount, $payment->payment_method_id]);
        self::assertSame(0, WalletTransaction::query()->count(), 'nothing taken, no line');
        self::assertCount(1, FakeProvider::$created);
    }

    public function testAFreeOrderIsTakenOnceHoweverOftenItsButtonIsPressed(): void
    {
        $take = $this->checkout($this->plan(['name' => 'رایگان', 'price' => '0.00'], $this->server));
        $this->send($this->tap($take));
        $this->send($this->tap($take));

        self::assertSame([self::text(BotText::OrderNotPending)], $this->said(), 'the checkout was taken');
        self::assertSame(1, Order::query()->count());
        self::assertCount(1, FakeProvider::$created);
    }

    public function testAFreeOrderNeedsNoSwitchedOnWallet(): void
    {
        $this->walletMethod()->forceFill(['enabled' => false])->save();
        $card = $this->cardMethod();

        $take = $this->checkout($this->test);
        self::assertSame(CallbackData::build(PurchaseHandler::serverCallback($this->test->id, $this->server->id), $card->id), $take, 'through the one way to pay there is');
        $this->send($this->tap($take));

        self::assertSame(OrderStatus::Fulfilled, Order::query()->sole()->status, 'no card transfer of nothing');
        self::assertSame($this->walletMethod()->id, Payment::query()->sole()->payment_method_id, 'settled by the shop\'s own wallet');
    }

    public function testAFreeOrderGivenBackGivesNothingBack(): void
    {
        $this->send($this->tap($this->checkout($this->test)));
        $payment = Payment::query()->sole();

        self::assertTrue($this->service(PaymentService::class)->refund($payment, 'root', null));

        self::assertSame([PaymentStatus::Refunded, OrderStatus::Refunded], [$payment->refresh()->status, $payment->order->refresh()->status]);
        self::assertSame(0, WalletTransaction::query()->count());
    }

    /** The plan's checkout on the server, opened: the free button's callback data, its only way on. */
    private function checkout(Plan $plan): string
    {
        $this->send($this->tap(PurchaseHandler::serverCallback($plan->id, $this->server->id)));

        return $this->inlineKeyboard(0)[0][0]['callback_data'];
    }
}
