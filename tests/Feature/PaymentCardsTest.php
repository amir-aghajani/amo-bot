<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\MethodUnavailableException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentCard;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\Checkout;
use App\Modules\Payments\Services\PaymentDirectory;
use App\Modules\Payments\Services\PaymentMethods;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Users\Models\User;
use App\Support\LocalTime;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Tests\DatabaseTestCase;

/**
 * A card method's cards (Payments\Services\PaymentCards): each payment is shown one at random among those switched on
 * whose daily cap — what came to it today on the shop's clock — leaves room for the amount, and always that one after;
 * none left, and the method is not offered. A card-to-card top-up asks a few Toman more, a sum no other open payment to
 * its card asks and never past its method's most, and the whole of it is credited — and taken back by its refund. While the shop takes card payments in
 * support's private chat, no card is picked: none's room is taken, the method is offered whatever its cards, and the
 * payment says it went there.
 */
final class PaymentCardsTest extends DatabaseTestCase
{
    private const CARD_2 = '5892101012345670';
    private const CARD_3 = '6104337812345674';

    private User $customer;
    private PaymentMethod $method;
    private PaymentCard $mellat;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->customer = $this->customer();
        $this->method = $this->cardMethod();
        $this->mellat = $this->method->cards()->firstOrFail();
    }

    public function testAPaymentIsShownACardAtRandomAmongThoseSwitchedOn(): void
    {
        $melli = $this->paymentCard($this->method, ['number' => self::CARD_2]);
        $this->paymentCard($this->method, ['number' => self::CARD_3, 'enabled' => false]);

        $shown = [];
        for ($i = 0; $i < 40; ++$i) {
            $shown[$this->pay($this->topUpOrder($this->customer, '100000'))->card_id] = true;
        }

        self::assertEqualsCanonicalizing([$this->mellat->id, $melli->id], array_keys($shown), 'both cards switched on, never the one switched off');
    }

    public function testACardWhoseDailyCapIsTakenIsLeftOutTillTheShopsMidnight(): void
    {
        LocalTime::use('Asia/Tehran');
        try {
            $melli = $this->paymentCard($this->method, ['number' => self::CARD_2]);
            $this->mellat->forceFill(['daily_cap' => '300000'])->save();
            // Taken today: a paid payment and an open one; a rejected one and yesterday's do not count.
            $this->cardPayment($this->topUpOrder($this->customer, '150000'), $this->method, ['card_id' => $this->mellat->id, 'status' => PaymentStatus::Paid, 'paid_at' => now()]);
            $this->cardPayment($this->topUpOrder($this->customer, '100000'), $this->method, ['card_id' => $this->mellat->id]);
            $this->cardPayment($this->topUpOrder($this->customer, '900000'), $this->method, ['card_id' => $this->mellat->id, 'status' => PaymentStatus::Failed]);
            // 2026-10-07 00:00 in Tehran is 2026-10-06 20:30 UTC: a payment of 20:00 UTC was yesterday's.
            $this->cardPayment($this->topUpOrder($this->customer, '900000'), $this->method, ['card_id' => $this->mellat->id])->forceFill(['created_at' => '2026-10-06 20:00:00'])->save();

            for ($i = 0; $i < 10; ++$i) {
                self::assertSame($melli->id, $this->pay($this->topUpOrder($this->customer, '60000'))->card_id, 'no room for 60,000 more on the capped card');
            }

            $melli->forceFill(['enabled' => false])->save();
            $methods = $this->service(PaymentMethods::class);
            self::assertSame([], array_map(static fn(PaymentMethod $method): int => $method->id, $methods->forOrder(OrderType::WalletTopUp, '60000.00')), 'no card with room: the method is not offered');
            self::assertSame([$this->method->id], array_map(static fn(PaymentMethod $method): int => $method->id, $methods->forOrder(OrderType::WalletTopUp, '10000.00')), 'a smaller amount still fits');

            $order = $this->topUpOrder($this->customer, '60000');
            try {
                $this->service(PaymentService::class)->createForOrder($order, $this->method);
                self::fail('no card has room for it');
            } catch (MethodUnavailableException $e) {
                self::assertSame('«کارت به کارت (ملت)» امروز برای این مبلغ در دسترس نیست؛ روش دیگری انتخاب کنید.', $e->getMessage());
                self::assertSame(['method_id' => [$e->getMessage()]], $e->errors());
            }
            self::assertSame(0, $order->payments()->count(), 'nothing made');

            // The shop's next day: the cap is whole again.
            Carbon::setTestNow('2026-10-07 20:31:00');
            self::assertSame($this->mellat->id, $this->pay($this->topUpOrder($this->customer, '250000'))->card_id);
        } finally {
            LocalTime::use('UTC');
        }
    }

    public function testAPaymentIsShownTheSameCardEveryTime(): void
    {
        $this->paymentCard($this->method, ['number' => self::CARD_2]);
        $order = $this->topUpOrder($this->customer, '100000');
        $payment = $this->pay($order);

        // Its card switched off — or its cap taken — since: the open payment keeps it all the same.
        PaymentCard::query()->whereKey($payment->card_id)->update(['enabled' => false, 'daily_cap' => '1000']);
        for ($i = 0; $i < 5; ++$i) {
            $again = $this->pay($order);
            self::assertSame([$payment->id, $payment->card_id], [$again->id, $again->card_id], 'the same payment, the same card');
        }

        $result = $this->service(Checkout::class)->pay($this->customer, $this->method, OrderType::WalletTopUp, '100000.00', static fn(): Order => $order);
        self::assertSame(PaymentCard::query()->findOrFail($payment->card_id)->number, $result->card()->card, 'the checkout shows the card it was shown');
    }

    public function testAPaymentFromBeforeCardsIsGivenOneOnceAndKeepsIt(): void
    {
        $order = $this->topUpOrder($this->customer, '100000');
        $old = $this->cardPayment($order, $this->method, ['card_id' => null]);

        $payment = $this->pay($order);

        self::assertSame([$old->id, $this->mellat->id], [$payment->id, $payment->card_id]);
    }

    public function testATopUpByCardAsksAFewTomanMoreNoOtherOpenPaymentToItsCardAsksAndTheWholeIsCredited(): void
    {
        $this->botSettings('wallet', ['topup_unique_max' => 2]);
        $other = $this->customer(['telegram_id' => 7002]);

        $first = $this->pay($this->topUpOrder($this->customer, '100000'));
        $second = $this->pay($this->topUpOrder($other, '100000'));

        self::assertContains($first->amount, ['100001.00', '100002.00'], 'one or two Toman more');
        self::assertEqualsCanonicalizing(['100001.00', '100002.00'], [$first->amount, $second->amount], 'never the sum another open payment to the card asks');
        self::assertSame('100000.00', $first->order->amount, 'the order is the amount asked: the same top-up again finds it');
        self::assertSame($first->id, $this->pay($first->order)->id);
        self::assertSame($first->amount, $this->pay($first->order)->amount, 'and its sum');

        $this->receipt($first);
        self::assertTrue($this->service(PaymentService::class)->approve($first->refresh(), 'admin'));
        self::assertSame([OrderStatus::Fulfilled, $first->amount], [$first->order->refresh()->status, $this->customer->balance()], 'the whole sum paid is credited');

        self::assertEquals((float) $first->amount, (float) Payment::moneyIn()->sum('amount'), "the shop's takings: the whole sum");

        self::assertTrue($this->service(PaymentService::class)->refund($first->refresh(), 'admin', null));
        self::assertSame('0.00', $this->customer->balance(), 'and the whole sum taken back');
    }

    public function testATopUpIsShownOnlyACardWithRoomForTheMostItMayAsk(): void
    {
        $this->botSettings('wallet', ['topup_unique_max' => 50]);
        $melli = $this->paymentCard($this->method, ['number' => self::CARD_2]);
        // Room for 100,000 and 49 Toman: not for a top-up of 100,000 that may ask 50 more.
        $this->mellat->forceFill(['daily_cap' => '100049'])->save();

        for ($i = 0; $i < 10; ++$i) {
            $payment = $this->pay($this->topUpOrder($this->customer, '100000'));
            self::assertSame($melli->id, $payment->card_id, 'never past the capped card\'s cap, whatever the few Toman drawn');
        }

        $melli->forceFill(['enabled' => false])->save();
        $methods = $this->service(PaymentMethods::class);
        self::assertSame([], $methods->forOrder(OrderType::WalletTopUp, '100000.00'), 'not offered for the top-up');
        self::assertContains($this->method->id, array_map(static fn(PaymentMethod $method): int => $method->id, $methods->forOrder(OrderType::Purchase, '100000.00')), 'a purchase of as much asks no more: it fits');

        $this->mellat->forceFill(['daily_cap' => '100050'])->save();
        self::assertSame($this->mellat->id, $this->pay($this->topUpOrder($this->customer, '100000'))->card_id, 'room for the most it may ask');
    }

    public function testATopUpsFewTomanMoreNeverTakeItPastItsMethodsMost(): void
    {
        $this->botSettings('wallet', ['topup_unique_max' => 50]);
        $this->method->forceFill(['max_amount' => '100020'])->save();
        $this->mellat->forceFill(['daily_cap' => '100020'])->save();

        self::assertSame([$this->method->id], array_map(static fn(PaymentMethod $method): int => $method->id, $this->service(PaymentMethods::class)->forOrder(OrderType::WalletTopUp, '100000.00')), 'room for the most it may ask: 20 Toman more, not 50');
        $this->mellat->forceFill(['daily_cap' => null])->save();
        for ($i = 0; $i < 10; ++$i) {
            $amount = $this->pay($this->topUpOrder($this->customer, '100000'))->amount;
            self::assertTrue(Money::compare($amount, '100001') >= 0 && Money::compare($amount, '100020') <= 0, "1 to 20 Toman more: {$amount}");
        }

        self::assertSame('100020.00', $this->pay($this->topUpOrder($this->customer, '100020'))->amount, 'the method\'s most already: nothing more');
    }

    public function testACustomersOwnOpenPaymentTakesNoMoreRoomWhenTheyAskAgain(): void
    {
        $this->mellat->forceFill(['daily_cap' => '150000'])->save();
        $order = $this->topUpOrder($this->customer, '100000');
        $payment = $this->pay($order);
        $methods = $this->service(PaymentMethods::class);
        $offered = static fn(?User $customer, string $amount = '100000.00'): bool => $methods->forOrder(OrderType::WalletTopUp, $amount, $customer) !== [];

        self::assertTrue($offered($this->customer), 'the method again for its customer: their payment is shown again, it takes no more room');
        self::assertTrue($methods->payable($this->method->id, OrderType::WalletTopUp, '100000.00', $this->customer) !== null);
        self::assertSame($payment->id, $this->pay($order)->id, 'and the same payment it is');
        self::assertFalse($offered(null), 'for anyone else the room is taken');
        self::assertFalse($offered($this->customer(['telegram_id' => 7002])));
        self::assertFalse($offered($this->customer, '60000.00'), 'nor for another order of theirs: a new payment would take more');

        // Its card switched off since — the method's only one: the payment keeps it, so the method is still theirs to ask.
        $this->mellat->forceFill(['enabled' => false])->save();
        self::assertTrue($offered($this->customer));
        self::assertSame([$payment->id, $this->mellat->id], [$this->pay($order)->id, $this->pay($order)->card_id]);
        self::assertFalse($offered(null, '1000.00'), 'anyone else: no card switched on');

        $this->receipt($payment);
        self::assertFalse($offered($this->customer), 'its receipt sent, a new payment would be another: none to show it');
    }

    public function testOnlyACardToCardTopUpAsksMoreAndNoneWhileTheSettingIsOff(): void
    {
        $server = $this->sellingServer('Berlin');
        $plan = $this->plan([], $server);

        self::assertSame('100000.00', $this->pay($this->topUpOrder($this->customer, '100000'))->amount, 'off (0): the amount itself');

        $this->botSettings('wallet', ['topup_unique_max' => 50]);
        $purchase = $this->service(OrderService::class)->openPurchase($this->customer, $plan, $server);
        self::assertSame($purchase->amount, $this->pay($purchase)->amount, 'a purchase is paid at its price');
    }

    public function testTheReceiptReportSaysWhichCardItWasShown(): void
    {
        $this->reportGroup();
        $melli = $this->paymentCard($this->method, ['number' => self::CARD_2, 'holder' => 'Reza <&>', 'bank' => 'ملی']);
        $this->mellat->forceFill(['enabled' => false])->save();

        $payment = $this->receipt($this->pay($this->topUpOrder($this->customer, '100000')));

        self::assertSame($melli->id, $payment->card_id);
        $text = (string) ReportMessage::query()->where('ref', "receipt:{$payment->id}")->value('text');
        self::assertStringContainsString('🏦 کارت: 5892 •••• •••• 5670 · Reza &lt;&amp;&gt; · ملی', $text, 'its card, the owner\'s words as text');
    }

    public function testInSupportsPrivateChatNoCardIsPickedNorItsRoomTakenAndThePaymentSaysWhereItWent(): void
    {
        $this->reportGroup();
        $this->botSettings('general', ['support_contact' => '@amo_support']);
        $this->botSettings('payments', ['card_to_support' => true]);
        $this->botSettings('wallet', ['topup_unique_max' => 2]);
        $this->mellat->forceFill(['enabled' => false, 'daily_cap' => '50000'])->save();
        $methods = $this->service(PaymentMethods::class);

        self::assertSame([$this->method->id], array_map(static fn(PaymentMethod $method): int => $method->id, $methods->forOrder(OrderType::WalletTopUp, '100000.00')), 'offered whatever its cards\' switches and caps');
        $first = $this->pay($this->topUpOrder($this->customer, '100000'));
        $second = $this->pay($this->topUpOrder($this->customer(['telegram_id' => 7002]), '100000'));

        self::assertSame([null, null], [$first->card_id, $second->card_id], 'no card picked');
        self::assertEqualsCanonicalizing(['100001.00', '100002.00'], [$first->amount, $second->amount], 'a top-up still asks a sum no other open payment shown no card asks: support tells receipts apart by it');
        self::assertSame([$first->id, $first->amount], [$this->pay($first->order)->id, $this->pay($first->order)->amount], 'asked again, the same payment and sum');
        $row = $methods->presentOne($this->method);
        self::assertSame(['0.00', true], [$row['cards'][0]['today'], $row['to_support']], 'no card\'s room taken; the methods screen says no card is shown');
        $transfer = $this->service(Checkout::class)->pay($this->customer, $this->method, OrderType::WalletTopUp, '100000.00', static fn(): Order => $first->order)->card();
        self::assertSame([null, null, null], [$transfer->card, $transfer->holder, $transfer->bank], 'the checkout shows no card either');

        // Its receipt sent from the website all the same: the report and the payments screen say where it went.
        $payment = $this->receipt($first);
        self::assertStringContainsString('🏦 ' . Payment::TO_SUPPORT, (string) ReportMessage::query()->where('ref', "receipt:{$payment->id}")->value('text'));
        self::assertSame(Payment::TO_SUPPORT, $this->service(PaymentDirectory::class)->present($payment->refresh())['summary']);

        // The shop shows its cards again: a payment shown none is given one as it is asked again.
        $this->botSettings('payments', ['card_to_support' => false]);
        $this->mellat->forceFill(['enabled' => true, 'daily_cap' => null])->save();
        self::assertSame([$second->id, $this->mellat->id], [$this->pay($second->order)->id, $this->pay($second->order)->card_id]);
        self::assertFalse($methods->presentOne($this->method)['to_support']);
    }

    /** The order's card payment, as the checkout makes it (PaymentService::createForOrder()). */
    private function pay(Order $order): Payment
    {
        return $this->service(PaymentService::class)->createForOrder($order, $this->method)['payment'];
    }
}
