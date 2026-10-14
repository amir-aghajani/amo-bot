<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentCard;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Providers\Models\Server;
use App\Modules\Store\Models\Website;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Tests\HttpTestCase;

/**
 * The website's side of the payment methods' bounds and cards: the ways to pay for an amount, an order paid by a way that
 * does not take its price refused under `method_id` in the method's own words — one whose payment finds no card after
 * all too, its order cancelled at once and its key left free —, and a card transfer's card — the one its payment was
 * shown, the same on a request made again — and a top-up's few Toman more.
 */
final class StorePaymentBoundsTest extends HttpTestCase
{
    private Website $website;
    private User $customer;
    private Server $server;
    private Plan $plan;
    private PaymentMethod $card;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->telegram();
        $this->withoutQr();
        $this->website = $this->website();
        $this->customer = $this->customer(['username' => 'ali']);
        $this->server = $this->sellingServer('Berlin');
        $this->plan = $this->plan([], $this->server);
        $this->card = $this->cardMethod();
        $this->bearer($this->customerSession($this->customer));
    }

    public function testTheWaysToPayForAnAmountAreThoseThatTakeIt(): void
    {
        $wallet = $this->walletMethod();
        $wallet->forceFill(['max_amount' => '100000'])->save();
        $big = $this->cardMethod('کارت بزرگ', overrides: ['config' => ['card_number' => '5892101012345670'], 'min_amount' => '500000']);
        $methods = fn(string $query): array => array_column($this->decode($this->get($this->storeApi($this->website, "/payment-methods?for=purchase{$query}")))['methods'] ?? [], 'id');

        self::assertSame([$wallet->id, $this->card->id, $big->id], $methods(''), 'no amount: every way of the kind, whatever its bounds');
        self::assertSame([$this->card->id], $methods('&amount=120000'), 'the wallet takes 100,000 at most, the big card 500,000 at least');
        self::assertSame([$this->card->id, $big->id], $methods('&amount=' . rawurlencode('۵۰۰٬۰۰۰')), 'read as the bot reads one');
        $this->card->cards()->update(['daily_cap' => '99999']);
        self::assertSame([$wallet->id], $methods('&amount=100000'), 'a card method whose cards have no room left is not offered');

        $listed = $this->decode($this->get($this->storeApi($this->website, '/payment-methods?for=purchase')))['methods'];
        self::assertSame(['min_amount' => null, 'max_amount' => '100000.00'], array_intersect_key($listed[0], ['min_amount' => 1, 'max_amount' => 1]), 'each says its bounds');

        $response = $this->get($this->storeApi($this->website, '/payment-methods?for=purchase&amount=lots'));
        self::assertSame(['مبلغ (amount) را به تومان و بدون اعشار بنویسید.'], $this->decode($response)['errors']['amount'] ?? null);
    }

    public function testAnOrderPaidByAWayThatDoesNotTakeItsPriceIsRefusedInTheMethodsWords(): void
    {
        $this->card->forceFill(['max_amount' => '100000'])->save();

        $response = $this->placeOrder($this->website, $this->plan, $this->server, $this->card);

        $this->assertRefusedUnder('method_id', '«کارت به کارت (ملت)» برای مبلغ‌های تا ۱۰۰٬۰۰۰ تومان است؛ روش دیگری انتخاب کنید.', $response);
        self::assertSame([0, 0], [Order::query()->count(), Payment::query()->count()], 'nothing ordered');

        $this->card->forceFill(['max_amount' => null])->save();
        $this->card->cards()->update(['daily_cap' => '50000']);
        $this->assertRefusedUnder('method_id', '«کارت به کارت (ملت)» امروز برای این مبلغ در دسترس نیست؛ روش دیگری انتخاب کنید.', $this->placeOrder($this->website, $this->plan, $this->server, $this->card), message: 'no card with room for it today');
        self::assertSame(0, Order::query()->count());
    }

    public function testACardTransferIsToTheCardItsPaymentWasShownTheSameOnARequestMadeAgain(): void
    {
        $this->card->cards()->update(['bank' => 'ملت']);
        $first = $this->decode($this->placeOrder($this->website, $this->plan, $this->server, $this->card))['checkout']['transfer'] ?? [];
        self::assertSame(['6037997700001119', 'AmoBot', 'ملت'], [$first['card'] ?? null, $first['holder'] ?? null, $first['bank'] ?? null]);

        // Another card switched on, the first switched off: the payment keeps its card.
        $this->paymentCard($this->card, ['number' => '5892101012345670']);
        PaymentCard::query()->where('number', '6037997700001119')->update(['enabled' => false]);
        $again = $this->decode($this->placeOrder($this->website, $this->plan, $this->server, $this->card))['checkout']['transfer'] ?? [];

        self::assertSame([$first['payment_id'], '6037997700001119'], [$again['payment_id'] ?? null, $again['card'] ?? null]);
    }

    public function testARequestMadeAgainIsAnsweredByItsPaymentThoughItFilledItsCard(): void
    {
        // Room for this one purchase today.
        $this->card->cards()->update(['daily_cap' => '120000']);
        $first = $this->decode($this->placeOrder($this->website, $this->plan, $this->server, $this->card))['checkout']['transfer'] ?? [];

        $again = $this->placeOrder($this->website, $this->plan, $this->server, $this->card);
        self::assertSame([$first['payment_id'] ?? null, '6037997700001119'], [$this->decode($again)['checkout']['transfer']['payment_id'] ?? null, $this->decode($again)['checkout']['transfer']['card'] ?? null], 'its own payment, not a refusal for want of room');
        $methods = $this->decode($this->get($this->storeApi($this->website, '/payment-methods?for=purchase&amount=120000')))['methods'];
        self::assertContains($this->card->id, array_column($methods, 'id'), 'and the card method is still among its ways to pay that amount');

        // Its card — the method's only one — switched off since: the request made again still answers with it.
        $this->card->cards()->update(['enabled' => false]);
        $again = $this->decode($this->placeOrder($this->website, $this->plan, $this->server, $this->card))['checkout']['transfer'] ?? [];
        self::assertSame([$first['payment_id'] ?? null, '6037997700001119'], [$again['payment_id'] ?? null, $again['card'] ?? null]);
        $this->card->cards()->update(['enabled' => true]);

        $topUp = $this->placeTopUp($this->website, 50000, $this->card);
        $this->assertRefusedUnder('method_id', '«کارت به کارت (ملت)» امروز برای این مبلغ در دسترس نیست؛ روش دیگری انتخاب کنید.', $topUp, message: 'another order is another payment: no room for it');
    }

    public function testAnOrderWhosePaymentFindsNoCardAfterAllIsCancelledAndItsKeyLeftFree(): void
    {
        // Room for one purchase today, which the customer's payment of another plan at the same price took: that open
        // payment lets the card in for this plan too, and the new order's payment then finds no card.
        $this->card->cards()->update(['daily_cap' => '120000']);
        $other = $this->plan(['name' => 'دوماهه'], $this->server);
        $this->placeOrder($this->website, $other, $this->server, $this->card, key: 'other-1');
        $noRoom = '«کارت به کارت (ملت)» امروز برای این مبلغ در دسترس نیست؛ روش دیگری انتخاب کنید.';

        $this->assertRefusedUnder('method_id', $noRoom, $this->placeOrder($this->website, $this->plan, $this->server, $this->card));

        $made = Order::query()->where('plan_id', $this->plan->id)->sole();
        self::assertSame([OrderStatus::Cancelled, $noRoom], [$made->status, $made->notes], 'cancelled at once, the refusal its note: no unpaid order left');
        self::assertSame(0, $made->payments()->count());
        $this->assertRefusedUnder('method_id', $noRoom, $this->placeOrder($this->website, $this->plan, $this->server, $this->card), message: 'the same key again: the same refusal, not a cancelled order\'s');
        self::assertSame(0, Order::payable()->where('plan_id', $this->plan->id)->count());

        $melli = $this->cardMethod('کارت به کارت (ملی)', overrides: ['config' => ['card_number' => '5892101012345670']]);
        $transfer = $this->decode($this->placeOrder($this->website, $this->plan, $this->server, $melli))['checkout']['transfer'] ?? [];
        self::assertSame('5892101012345670', $transfer['card'] ?? null, 'the same key with another way to pay: a new request, not a key used with another method');
        self::assertSame(1, Order::payable()->where('plan_id', $this->plan->id)->count());
    }

    public function testACardToCardTopUpAsksAFewTomanMoreAndSaysSo(): void
    {
        $this->botSettings('wallet', ['topup_unique_max' => 99]);

        $response = $this->placeTopUp($this->website, 100000, $this->card);

        $checkout = $this->decode($response)['checkout'];
        $paid = (float) $checkout['transfer']['amount'];
        self::assertTrue($paid >= 100001 && $paid <= 100099, "1 to 99 Toman more: {$paid}");
        self::assertSame('100000.00', $checkout['order']['amount'], 'the order is the top-up asked for');
        self::assertSame($checkout['transfer']['amount'], $checkout['order']['payments'][0]['amount']);
    }
}
