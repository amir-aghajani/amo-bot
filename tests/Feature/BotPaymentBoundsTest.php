<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Exceptions\MethodUnavailableException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\Models\Server;
use App\Modules\Telegram\Handlers\PurchaseHandler;
use App\Modules\Telegram\Handlers\TopUpHandler;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Users\Models\User;
use App\Support\Money;
use Tests\BotTestCase;

/**
 * The amounts a way to pay is offered for (`min_amount`, `max_amount`) at the bot's checkouts: a method whose bounds do
 * not hold the price is no button — and none left, the customer is told —; one tapped after its bounds moved draws the
 * checkout again with nothing ordered; and the payment itself refuses an amount outside them, whoever asks. A card whose
 * daily cap the customer's own open payment fills is still theirs to tap again — and one whose payment finds no card
 * after all leaves nothing ordered.
 */
final class BotPaymentBoundsTest extends BotTestCase
{
    private User $ali;
    private Plan $plan;
    private Server $server;
    private PaymentMethod $wallet;
    private PaymentMethod $card;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakePanel();
        $this->withoutQr();
        $this->server = $this->fakeServer('آلمان');
        $this->inbound($this->server, '1');
        $this->plan = $this->plan(on: [$this->server]);
        $this->ali = $this->wallet($this->customer(), '500000.00');
        $this->wallet = $this->walletMethod();
        $this->card = $this->cardMethod();
    }

    public function testOnlyTheMethodsWhoseBoundsHoldThePriceAreButtons(): void
    {
        $this->card->forceFill(['min_amount' => '150000'])->save();
        $this->send($this->tap($this->checkoutOf($this->plan, $this->server)));
        self::assertSame([$this->payButton($this->plan, $this->server, $this->wallet), PurchaseHandler::planCallback($this->plan->id)], $this->callbacks(0), 'the card is for 150,000 Toman and up: the plan costs 120,000');

        $this->card->forceFill(['min_amount' => '120000', 'max_amount' => '120000'])->save();
        $this->wallet->forceFill(['max_amount' => '119999'])->save();
        $this->send($this->tap($this->checkoutOf($this->plan, $this->server)));
        self::assertSame([$this->payButton($this->plan, $this->server, $this->card), PurchaseHandler::planCallback($this->plan->id)], $this->callbacks(0), 'both bounds hold the price exactly; the wallet stops a Toman short');
    }

    public function testWhenNoMethodTakesThePriceTheCustomerIsToldSo(): void
    {
        $this->card->forceFill(['max_amount' => '100000'])->save();
        $this->wallet->forceFill(['min_amount' => '200000'])->save();

        $this->send($this->tap($this->checkoutOf($this->plan, $this->server)));

        $checkout = self::text(BotText::Checkout, ['plan' => 'یک‌ماهه', 'server' => 'آلمان', 'amount' => Money::format('120000')]);
        self::assertSame([BotTexts::paragraphs($checkout, self::text(BotText::CheckoutNoMethodForAmount))], $this->said(), 'not «no method is switched on»: none takes this amount');
        self::assertSame([PurchaseHandler::planCallback($this->plan->id)], $this->callbacks(0));
    }

    public function testAMethodTappedAfterItsBoundsMovedDrawsTheCheckoutAgainWithNothingOrdered(): void
    {
        $this->send($this->tap($this->checkoutOf($this->plan, $this->server)));
        $this->card->forceFill(['min_amount' => '500000'])->save();

        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->card)));

        self::assertSame([$this->payButton($this->plan, $this->server, $this->wallet), PurchaseHandler::planCallback($this->plan->id)], $this->callbacks(0), 'the checkout as it stands now');
        self::assertSame([0, 0], [Order::query()->count(), Payment::query()->count()]);
    }

    public function testACardWhoseRoomTheCustomersOwnPaymentHoldsIsStillTheirsToTapAgain(): void
    {
        // Room for one purchase today: the customer's own.
        $this->card->cards()->update(['daily_cap' => '150000']);
        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->card)));
        $payment = Payment::query()->sole();

        $this->send($this->tap($this->checkoutOf($this->plan, $this->server)));
        self::assertSame([$this->payButton($this->plan, $this->server, $this->wallet), $this->payButton($this->plan, $this->server, $this->card), PurchaseHandler::planCallback($this->plan->id)], $this->callbacks(0), '«بازگشت» to the checkout: the card is still a button');

        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->card)));
        self::assertSame([$payment->id], Payment::query()->pluck('id')->all(), 'tapped again: the same payment');
        self::assertStringContainsString('6037997700001119', implode("\n", $this->said()), 'the same card shown');

        // The card — the method's only one — switched off since: the payment keeps it, and so does its customer's checkout.
        $this->card->cards()->update(['enabled' => false]);
        $this->send($this->tap($this->checkoutOf($this->plan, $this->server)));
        self::assertContains($this->payButton($this->plan, $this->server, $this->card), $this->callbacks(0));
        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->card)));
        self::assertSame([$payment->id], Payment::query()->pluck('id')->all());
        self::assertStringContainsString('6037997700001119', implode("\n", $this->said()));
    }

    public function testACardThatFindsNoRoomAsItsPaymentIsMadeLeavesNothingOrdered(): void
    {
        // Room for one purchase today, which the customer's payment of another plan at the same price took: that open
        // payment lets the card in for this plan too, and the new order's payment then finds no card.
        $this->card->cards()->update(['daily_cap' => '120000']);
        $other = $this->plan(['name' => 'دوماهه'], [$this->server]);
        $this->send($this->tap($this->payButton($other, $this->server, $this->card)));
        $kept = Payment::query()->sole();

        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->card)));

        $made = Order::query()->where('plan_id', $this->plan->id)->sole();
        self::assertSame([OrderStatus::Cancelled, '«کارت به کارت (ملت)» امروز برای این مبلغ در دسترس نیست؛ روش دیگری انتخاب کنید.'], [$made->status, $made->notes], 'cancelled at once, the refusal its note: nothing left to pay');
        self::assertSame([$kept->id], Payment::query()->pluck('id')->all(), 'no payment made for it; the other order\'s kept');
        self::assertSame(OrderStatus::Pending, $kept->order->status);
        self::assertContains($this->payButton($this->plan, $this->server, $this->wallet), $this->callbacks(0), 'the checkout drawn again');
    }

    public function testATopUpsCheckoutOffersTheCardsThatTakeItsAmount(): void
    {
        $other = $this->cardMethod('کارت به کارت (ملی)', overrides: ['config' => ['card_number' => '5892101012345670'], 'min_amount' => '1000000']);

        $this->send($this->tap(TopUpHandler::checkoutCallback(200_000)));
        self::assertSame([CallbackData::build(TopUpHandler::checkoutCallback(200_000), $this->card->id), TopUpHandler::START], $this->callbacks(0), 'the other card is for a million and up');

        $this->send($this->tap(CallbackData::build(TopUpHandler::checkoutCallback(200_000), $other->id)));
        self::assertSame(0, Order::query()->count(), 'tapped all the same (an old checkout): nothing ordered');
        self::assertSame([CallbackData::build(TopUpHandler::checkoutCallback(200_000), $this->card->id), TopUpHandler::START], $this->callbacks(0), 'the checkout drawn again');
    }

    public function testThePaymentItselfRefusesAnAmountOutsideItsMethodsBoundsButNotAFreeOrder(): void
    {
        $this->card->forceFill(['min_amount' => '150000', 'max_amount' => '900000'])->save();
        $order = $this->purchaseOrder($this->ali, $this->plan, $this->server);

        try {
            $this->service(PaymentService::class)->createForOrder($order, $this->card);
            self::fail('120,000 Toman is below the card\'s least');
        } catch (MethodUnavailableException $e) {
            self::assertSame('«کارت به کارت (ملت)» برای مبلغ‌های بین ۱۵۰٬۰۰۰ تومان و ۹۰۰٬۰۰۰ تومان است؛ روش دیگری انتخاب کنید.', $e->getMessage());
            self::assertSame(422, $e->status());
        }
        self::assertSame(0, Payment::query()->count());

        $this->wallet->forceFill(['min_amount' => '150000'])->save();
        $free = $this->purchaseOrder($this->ali, $this->plan, $this->server, ['amount' => '0']);
        self::assertTrue($this->service(PaymentService::class)->createForOrder($free, $this->wallet)['payment']->isPaid(), 'nothing to pay is within any bounds');
    }
}
