<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\DTO\Buyer;
use App\Modules\Orders\DTO\OrderKey;
use App\Modules\Orders\Enums\OrderPricing;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Exceptions\FirstPurchaseUsedException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\Pricing;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Services\AutoRenewal;
use App\Modules\Subscriptions\Services\CustomerRenewal;
use App\Modules\Subscriptions\Tasks\AutoRenewTask;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\Topic;
use App\Modules\Users\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Tests\DatabaseTestCase;
use Tests\Fakes\FakeProvider;

/**
 * First-purchase prices: paid until the customer has bought (Order::boughtBy()) and marked on the order; a keyed request
 * made again keeps its order's price; of two ordered at that price before either was paid, the one paid second fails at
 * delivery for support to refund (a retried first purchase stays first); renewals pay the plan's price; shops count their
 * own purchases.
 */
final class FirstPurchasePricesTest extends DatabaseTestCase
{
    private Server $server;
    private Plan $plan;
    private Plan $plain;
    private User $ali;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-08 12:00:00');
        $this->withoutQr();
        $this->server = $this->sellingServer();
        $this->plan = $this->plan(['price' => '150000.00', 'first_purchase_price' => '50000.00'], $this->server);
        $this->plain = $this->plan(['name' => 'سه‌ماهه', 'price' => '300000.00'], $this->server);
        $this->ali = $this->customer(['username' => 'ali']);
    }

    public function testHavingBoughtIsAPurchasePaidAtSomePointWhateverCameOfItSince(): void
    {
        $bought = fn(): bool => Order::boughtBy($this->ali->id)->exists();

        $this->purchaseOrder($this->ali, $this->plain, $this->server);
        $this->purchaseOrder($this->ali, $this->plain, $this->server, ['status' => OrderStatus::Cancelled]);
        $this->topUpOrder($this->ali, '50000.00', ['status' => OrderStatus::Fulfilled]);
        $this->purchaseOrder($this->customer(['telegram_id' => 7001]), $this->plain, $this->server, ['status' => OrderStatus::Fulfilled]);
        self::assertFalse($bought(), "a purchase still to be paid, one cancelled unpaid, a wallet top-up, someone else's purchase");

        $order = $this->purchaseOrder($this->ali, $this->plain, $this->server, ['status' => OrderStatus::Paid]);
        foreach ([OrderStatus::Paid, OrderStatus::Processing, OrderStatus::Fulfilled, OrderStatus::Failed, OrderStatus::Refunded] as $status) {
            $order->forceFill(['status' => $status])->save();

            self::assertTrue($bought(), "a purchase {$status->value}: the money came in");
        }
    }

    public function testANewcomerPaysTheFirstPurchasePriceThenThePrice(): void
    {
        $orders = $this->service(OrderService::class);
        self::assertTrue($this->pricing()->buyer($this->ali, $this->plan)->firstPurchase, 'a newcomer');
        self::assertSame(['50000.00', '150000.00'], [(new Buyer(true, false))->purchase($this->plan)->amount, (new Buyer(false, false))->purchase($this->plan)->amount]);

        $first = $this->buy($this->ali, $this->plan, $this->server);

        self::assertSame(['50000.00', OrderPricing::FirstPurchase], [$first->amount, $first->pricing], 'their first purchase, at its price');
        self::assertSame('100000.00', $this->ali->balance(), 'the wallet charged that price');
        self::assertNotNull($first->subscription, 'delivered');
        self::assertFalse($this->pricing()->buyer($this->ali, $this->plan)->firstPurchase, 'bought now');

        $next = $orders->openPurchase($this->ali, $this->plan, $this->server);
        self::assertSame(['150000.00', OrderPricing::Regular], [$next->amount, $next->pricing], 'the next one at the plan\'s price');
    }

    public function testAPlanWithoutOneCostsItsPriceAndAsksNothing(): void
    {
        $orders = $this->service(OrderService::class);
        $pricing = $this->pricing();

        $queries = 0;
        $this->whileListening(QueryExecuted::class, static function () use (&$queries): void {
            ++$queries;
        }, fn() => self::assertFalse($pricing->buyer($this->ali, $this->plain)->firstPurchase));
        self::assertSame(0, $queries, 'no price of the kind: nobody\'s purchases are asked about');
        self::assertTrue($this->pricing()->buyer($this->ali, $this->plain, $this->plan)->firstPurchase, 'a list: one of them has one');
        self::assertTrue($this->pricing()->buyer(null, $this->plan)->firstPurchase, "a website's visitor who has not signed in has bought nothing");

        $order = $orders->openPurchase($this->ali, $this->plain, $this->server);
        self::assertSame(['300000.00', OrderPricing::Regular], [$order->amount, $order->pricing]);
    }

    public function testAFirstPurchasePriceNotBelowThePriceIsNone(): void
    {
        // Kept below the price when it was set; the price lowered since to it, or under it.
        foreach (['50000.00', '40000.00'] as $price) {
            $this->plan->forceFill(['price' => $price])->save();

            self::assertNull($this->plan->firstPurchasePrice(), "the price {$price}");
            self::assertSame($price, (new Buyer(true, false))->purchase($this->plan)->amount);
            self::assertFalse($this->pricing()->buyer($this->ali, $this->plan)->firstPurchase);
        }

        $this->plan->forceFill(['price' => '150000.00', 'first_purchase_price' => '0.00'])->save();
        self::assertSame('0.00', $this->plan->firstPurchasePrice(), 'a free first purchase');
    }

    public function testWhatIsNotBoughtLeavesTheFirstPurchasePriceTheirs(): void
    {
        $this->purchaseOrder($this->ali, $this->plain, $this->server, ['status' => OrderStatus::Cancelled]);
        $this->purchaseOrder($this->ali, $this->plain, $this->server);
        $this->paidByCard($this->topUpOrder($this->ali, '50000.00'));

        $order = $this->buy($this->ali, $this->plan, $this->server);

        self::assertSame(['50000.00', OrderPricing::FirstPurchase], [$order->amount, $order->pricing], 'a purchase cancelled, one never paid and a top-up: nothing bought yet');
    }

    public function testARequestMadeAgainWithItsKeyIsAnsweredByItsOrderAtItsOwnPrice(): void
    {
        $orders = $this->service(OrderService::class);
        $card = $this->cardMethod();
        $first = $orders->openPurchase($this->ali, $this->plan, $this->server, new OrderKey('first-1', $card->id));
        self::assertSame(['50000.00', OrderPricing::FirstPurchase], [$first->amount, $first->pricing]);
        // Meanwhile, another plan bought from the wallet.
        $this->buy($this->ali, $this->plain, $this->server);

        self::assertSame($first->id, $orders->openPurchase($this->ali, $this->plan, $this->server, new OrderKey('first-1', $card->id))->id, 'the order its key came to, at its own price');

        $again = $orders->openPurchase($this->ali, $this->plan, $this->server, new OrderKey('first-2', $card->id));
        self::assertNotSame($first->id, $again->id, 'another key: a new purchase, not the first one found again');
        self::assertSame(['150000.00', OrderPricing::Regular], [$again->amount, $again->pricing], 'at the plan\'s price: they have bought');
    }

    public function testOfTwoPaidInTheSameMomentTheEarlierIsDeliveredAndTheLaterFailsReportedForSupport(): void
    {
        $this->telegram();
        $this->reportGroup();
        $paris = $this->sellingServer('Paris');
        $this->planEntry($this->plan, $paris);
        // Both ordered at the first-purchase price before either was paid — one on each of its locations —, then paid in
        // the same moment.
        $earlier = $this->paid($this->firstPurchase($this->server));
        $later = $this->paid($this->firstPurchase($paris));
        $orders = $this->service(OrderService::class);

        self::assertTrue($orders->deliver($later), 'the later one, delivered first');
        self::assertTrue($orders->deliver($earlier));

        self::assertSame(OrderStatus::Fulfilled, $earlier->refresh()->status, 'the earlier is their first purchase');
        self::assertSame([OrderStatus::Failed, FirstPurchaseUsedException::MESSAGE], [$later->refresh()->status, $later->notes], 'the later fails, in the refusal\'s words');
        self::assertSame([$this->server->id], array_column(FakeProvider::$created, 'server'), 'one service, on the earlier one\'s location');
        self::assertStringContainsString(FirstPurchaseUsedException::MESSAGE, ReportMessage::query()->where('ref', "delivery:{$later->id}")->sole()->text, 'reported as any failed delivery, with its retry');

        // Support's retry finds it so again: their money goes back.
        self::assertTrue($orders->deliver($later->refresh()));
        self::assertSame([OrderStatus::Failed, FirstPurchaseUsedException::MESSAGE], [$later->refresh()->status, $later->notes]);
        self::assertCount(1, FakeProvider::$created);
    }

    public function testOneWaitingForItsReceiptWhileTheWalletBuysAnotherIsNoLongerTheFirst(): void
    {
        // Ordered first at the first-purchase price, by card — then, its receipt still with support, another plan bought
        // from the wallet.
        $first = $this->service(OrderService::class)->openPurchase($this->ali, $this->plan, $this->server);
        Carbon::setTestNow('2026-10-08 12:05:00');
        $this->buy($this->ali, $this->plain, $this->server);
        Carbon::setTestNow('2026-10-08 12:30:00');

        $this->paidByCard($first);

        self::assertSame([OrderStatus::Failed, FirstPurchaseUsedException::MESSAGE], [$first->refresh()->status, $first->notes], 'the wallet\'s was paid first: theirs is the first purchase, and support gives this one\'s money back');
        self::assertCount(1, FakeProvider::$created);
    }

    public function testAPurchaseAtThePriceIsHeldToNothing(): void
    {
        // Ordered at the plan's price after a first purchase was ordered — and paid before it: theirs to have.
        $first = $this->service(OrderService::class)->openPurchase($this->ali, $this->plan, $this->server);
        $regular = $this->purchaseOrder($this->ali, $this->plain, $this->server);
        $this->paidByCard($first);
        $this->paidByCard($regular);

        self::assertSame([OrderStatus::Fulfilled, OrderStatus::Fulfilled], [$first->refresh()->status, $regular->refresh()->status]);
        self::assertCount(2, FakeProvider::$created);
    }

    public function testAFirstPurchaseWhoseDeliveryFailedIsStillTheFirstWhenSupportRetriesIt(): void
    {
        FakeProvider::$unreachable = true;
        $first = $this->firstPurchase($this->server);
        $this->paidByCard($first);
        self::assertSame(OrderStatus::Failed, $first->refresh()->status, 'the panel out of reach');

        FakeProvider::$unreachable = false;
        Carbon::setTestNow('2026-10-08 13:00:00');
        $this->buy($this->ali, $this->plain, $this->server);
        $this->service(OrderService::class)->deliver($first->refresh());

        self::assertSame(OrderStatus::Fulfilled, $first->refresh()->status, 'paid before the other: their first purchase, whatever they bought since');
        self::assertCount(2, FakeProvider::$created);
    }

    public function testTheGroupHearsAPurchaseWasAtTheFirstPurchasePrice(): void
    {
        $this->telegram();
        $this->reportGroup();

        $this->buy($this->ali, $this->plan, $this->server);
        $this->buy($this->ali, $this->plan, $this->server);

        $sales = ReportMessage::query()->where('topic', Topic::Purchases)->oldest('id')->pluck('text')->all();
        self::assertCount(2, $sales);
        self::assertStringContainsString('💳 مبلغ: ۵۰٬۰۰۰ تومان · کیف پول · 🎁 قیمت خرید اول', $sales[0], 'beside what was paid');
        self::assertStringNotContainsString('قیمت خرید اول', $sales[1], 'the next one at the price');
    }

    public function testItsServiceRenewsAtThePlansPriceByItsCustomerOrByItself(): void
    {
        $this->telegram();
        // The shop turns «تمدید خودکار» on for every new service.
        $this->botSettings('auto_renew', ['auto_renew_days' => 2, 'auto_renew_default' => true]);
        $service = $this->buy($this->ali, $this->plan, $this->server)->subscription ?? self::fail('nothing delivered');

        self::assertTrue($service->auto_renew, 'a new service of it starts with «تمدید خودکار» as the shop set it');
        self::assertTrue($this->service(AutoRenewal::class)->offeredFor($service));
        self::assertSame('150000.00', $this->service(CustomerRenewal::class)->preview($service)?->price, 'its customer renews it at the plan\'s price');

        // Its end within the days: renewed by itself, at the price.
        $this->wallet($this->ali, '500000.00');
        $service->forceFill(['starts_at' => now()->subDays(29), 'expires_at' => now()->addDay()])->save();
        FakeProvider::mirror($service);
        $this->service(AutoRenewTask::class)->run();

        $renewal = Order::query()->where('type', OrderType::Renewal->value)->sole();
        self::assertSame([OrderStatus::Fulfilled, '150000.00', OrderPricing::Regular], [$renewal->status, $renewal->amount, $renewal->pricing]);
        self::assertSame('350000.00', $this->ali->balance());
    }

    public function testEachShopCountsItsOwnCustomersPurchases(): void
    {
        $this->buy($this->ali, $this->plan, $this->server);
        self::assertFalse($this->pricing()->buyer($this->ali, $this->plan)->firstPurchase, 'Ali has bought in the main shop');

        CurrentBot::run($this->agentBot(), function (): void {
            // The same person in the agent's bot: a customer of its shop of their own, who has bought nothing there.
            $ali = $this->customer(['username' => 'ali']);
            $theirs = $this->plan(['name' => 'یک‌ماهه نماینده', 'price' => '90000.00', 'first_purchase_price' => '30000.00'], $this->server);
            self::assertTrue($this->pricing()->buyer($ali, $theirs)->firstPurchase, "the agent's shop: a newcomer there");

            $order = $this->buy($ali, $theirs, $this->server);

            self::assertSame(['30000.00', OrderPricing::FirstPurchase], [$order->amount, $order->pricing]);
            self::assertFalse($this->pricing()->buyer($ali, $theirs)->firstPurchase, 'bought there now');
        });

        self::assertSame(1, Order::boughtBy($this->ali->id)->count(), "the main shop's Ali, one purchase: theirs");
    }

    private function pricing(): Pricing
    {
        return $this->service(Pricing::class);
    }

    /** Ali's purchase of the plan on the server, ordered at its first-purchase price before anything of theirs was paid. */
    private function firstPurchase(Server $server): Order
    {
        return $this->purchaseOrder($this->ali, $this->plan, $server, ['amount' => '50000.00', 'pricing' => OrderPricing::FirstPurchase]);
    }

    /** The order paid by card, its delivery not started: the money in, as a settlement leaves it just before delivering. */
    private function paid(Order $order): Order
    {
        $this->cardPayment($order, $this->cardMethod(), ['status' => PaymentStatus::Paid, 'paid_at' => now()]);
        $order->forceFill(['status' => OrderStatus::Paid])->save();

        return $order;
    }
}
