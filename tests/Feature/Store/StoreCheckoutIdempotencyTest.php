<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Accounts\Services\AccountMerger;
use App\Modules\Auth\Actor;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\DTO\OrderKey;
use App\Modules\Orders\Exceptions\RequestKeyReusedException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\RequestKey;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Tasks\ExpireOrdersTask;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Providers\Models\Server;
use App\Modules\Store\Exceptions\CheckoutRefusedException;
use App\Modules\Store\Http\IdempotencyKey;
use App\Modules\Store\Models\Website;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ServiceTransfers;
use App\Modules\Users\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\Fakes\FakeProvider;
use Tests\HttpTestCase;

/**
 * Idempotency keys: a repeated ordering request is answered by its key's order as it stands, whatever changed since —
 * never a second order or charge, even for two at once; a key reused for something else, missing or malformed is
 * refused; keys are kept a week.
 */
final class StoreCheckoutIdempotencyTest extends HttpTestCase
{
    /** The statement that looks for the order a request's key came to (OrderService::keyed()). */
    private const KEYED = 'select * from "orders" where "id" in (select "order_id" from "request_keys" where "user_id" = ? and "key" = ?';

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
        $this->customer = $this->wallet($this->customer(['username' => 'ali']), '500000');
        $this->server = $this->sellingServer('Berlin');
        $this->plan = $this->plan([], $this->server);
        $this->card = $this->cardMethod();
        $this->bearer($this->customerSession($this->customer));
    }

    public function testTheSameKeyIsAnsweredByItsOrderAndChargesOnce(): void
    {
        $first = $this->decode($this->placeOrder($this->website, $this->plan, $this->server, $this->walletMethod()));

        $again = $this->placeOrder($this->website, $this->plan, $this->server, $this->walletMethod());

        self::assertSame(200, $again->getStatusCode(), (string) $again->getBody());
        self::assertSame($first, $this->decode($again), 'the same answer');
        self::assertSame(1, Order::query()->count(), 'one order');
        self::assertSame('380000.00', $this->customer->balance(), 'one charge');
        self::assertCount(1, FakeProvider::$created, 'one client on its panel');
    }

    public function testAKeyMadeAgainIsAnsweredByItsOrderWhateverChangedSince(): void
    {
        $first = $this->decode($this->placeOrder($this->website, $this->plan, $this->server, $this->walletMethod()))['checkout'];
        // Since: the plan taken off sale, the wallet spent, the wallet switched off.
        $this->plan->forceFill(['is_active' => false])->save();
        $this->wallet($this->customer, '0');
        $this->walletMethod()->forceFill(['enabled' => false])->save();

        $again = $this->placeOrder($this->website, $this->plan, $this->server, $this->walletMethod());

        self::assertSame(200, $again->getStatusCode(), (string) $again->getBody());
        self::assertSame(['settled', $first['order']['id']], [$this->decode($again)['checkout']['outcome'], $this->decode($again)['checkout']['order']['id']]);
    }

    public function testAKeyMadeAgainOnceItsServiceWasHandedOnIsAnsweredByItsOrderWithoutTheService(): void
    {
        $first = $this->decode($this->placeOrder($this->website, $this->plan, $this->server, $this->walletMethod()))['checkout'];
        $this->transfersOn();
        $sara = $this->customer(['telegram_id' => 7070, 'username' => 'sara']);
        $this->service(ServiceTransfers::class)->transfer(Subscription::query()->findOrFail($first['subscription']['id']), $sara, Actor::customer($this->customer));

        $again = $this->placeOrder($this->website, $this->plan, $this->server, $this->walletMethod());

        self::assertSame(200, $again->getStatusCode(), (string) $again->getBody());
        $checkout = $this->decode($again)['checkout'];
        self::assertSame(['settled', $first['order']['id'], null], [$checkout['outcome'], $checkout['order']['id'], $checkout['subscription']], 'their order, not Sara\'s service');
    }

    public function testACardTransferMadeAgainIsTheSamePaymentToTheSameCard(): void
    {
        $first = $this->decode($this->placeOrder($this->website, $this->plan, $this->server, $this->card))['checkout'];

        $again = $this->decode($this->placeOrder($this->website, $this->plan, $this->server, $this->card))['checkout'];

        self::assertSame(['transfer', $first['transfer']], [$again['outcome'], $again['transfer']]);
        self::assertSame(1, Payment::query()->count(), 'one payment awaiting its receipt');
    }

    public function testAKeyThatPaidAnotherKeysOpenOrderIsAnsweredByItWhenMadeAgain(): void
    {
        // A card's checkout with one key, not paid; then the wallet with another: the open order of the same thing is the
        // one the wallet pays — and the second key is kept for it, as the first is.
        $card = $this->decode($this->placeOrder($this->website, $this->plan, $this->server, $this->card, key: 'k1'))['checkout'];
        $paid = $this->decode($this->placeOrder($this->website, $this->plan, $this->server, $this->walletMethod(), key: 'k2'))['checkout'];
        self::assertSame(['transfer', 'settled', $card['order']['id']], [$card['outcome'], $paid['outcome'], $paid['order']['id']]);

        // The wallet's request sent again — a network's retry —: that order, as it stands.
        $again = $this->placeOrder($this->website, $this->plan, $this->server, $this->walletMethod(), key: 'k2');

        self::assertSame(200, $again->getStatusCode(), (string) $again->getBody());
        self::assertSame(['settled', $card['order']['id']], [$this->decode($again)['checkout']['outcome'], $this->decode($again)['checkout']['order']['id']]);
        self::assertSame(1, Order::query()->count(), 'one order');
        self::assertSame('380000.00', $this->customer->balance(), 'one charge');
        self::assertCount(1, FakeProvider::$created, 'one client on its panel');
        $cardAgain = $this->decode($this->placeOrder($this->website, $this->plan, $this->server, $this->card, key: 'k1'))['checkout'];
        self::assertSame(['settled', $card['order']['id']], [$cardAgain['outcome'], $cardAgain['order']['id']], "the card's request sent again finds it paid, too");
        self::assertSame([$card['order']['id'], $card['order']['id']], RequestKey::query()->whereIn('key', ['k1', 'k2'])->orderBy('key')->pluck('order_id')->all(), 'both keys name it');
    }

    public function testAnOpenOrderOfTheSameThingFoundWithoutAKeyIsTheKeysOrder(): void
    {
        // The customer picked the card in the bot a moment ago: the open order of the same thing is the one the site pays.
        $open = $this->service(OrderService::class)->openPurchase($this->customer, $this->plan, $this->server);

        $checkout = $this->decode($this->placeOrder($this->website, $this->plan, $this->server, $this->walletMethod(), key: 'from-the-site'))['checkout'];

        self::assertSame(['settled', $open->id], [$checkout['outcome'], $checkout['order']['id']]);
        self::assertSame($open->id, $this->service(OrderService::class)->keyed($this->customer, 'from-the-site')?->id, 'the key kept for the order it came to');
        $again = $this->decode($this->placeOrder($this->website, $this->plan, $this->server, $this->walletMethod(), key: 'from-the-site'))['checkout'];
        self::assertSame(['settled', $open->id], [$again['outcome'], $again['order']['id']], 'made again: the order it paid');
        self::assertSame('380000.00', $this->customer->balance(), 'one charge');
    }

    public function testAKeyThatCameToAnOrderOfSomethingElseIsRefused(): void
    {
        $this->placeOrder($this->website, $this->plan, $this->server, $this->card, key: 'one-attempt');

        $responses = [
            'another plan' => $this->placeOrder($this->website, $this->plan(['name' => 'دوماهه', 'price' => '200000.00'], $this->server), $this->server, $this->card, key: 'one-attempt'),
            'a top-up' => $this->placeTopUp($this->website, 120000, $this->card, 'one-attempt'),
        ];
        $this->placeTopUp($this->website, 50000, $this->card, 'a-top-up');
        $responses['a top-up of another amount'] = $this->placeTopUp($this->website, 60000, $this->card, 'a-top-up');

        foreach ($responses as $why => $response) {
            self::assertSame([422, [RequestKeyReusedException::MESSAGE]], [$response->getStatusCode(), $this->decode($response)['errors']['idempotency_key'] ?? null], $why);
        }
        self::assertSame(2, Order::query()->count());
    }

    public function testAPriceChangedSinceIsNoOtherThingItsOwnOrderAnswersAtItsOwnPrice(): void
    {
        $first = $this->decode($this->placeOrder($this->website, $this->plan, $this->server, $this->card, key: 'before-the-change'))['checkout'];
        // The wallet's request for the same thing too, its process gone between its order and its payment.
        $wallet = $this->walletMethod();
        $this->service(OrderService::class)->openPurchase($this->customer, $this->plan, $this->server, new OrderKey('the-wallets', $wallet->id));
        $this->plan->forceFill(['price' => '150000.00'])->save();

        $again = $this->placeOrder($this->website, $this->plan, $this->server, $this->card, key: 'before-the-change');

        self::assertSame(200, $again->getStatusCode(), (string) $again->getBody());
        $checkout = $this->decode($again)['checkout'];
        self::assertSame([$first['order']['id'], '120000.00', '120000.00'], [$checkout['order']['id'], $checkout['order']['amount'], $checkout['transfer']['amount'] ?? null], 'its own order, at the price it was made at');

        // The wallet's made again: what it is short of is judged by the order's own amount.
        $this->wallet($this->customer, '130000');
        $paid = $this->decode($this->placeOrder($this->website, $this->plan, $this->server, $wallet, key: 'the-wallets'))['checkout'];
        self::assertSame(['settled', $first['order']['id']], [$paid['outcome'], $paid['order']['id']]);
        self::assertSame('10000.00', $this->customer->balance());
        self::assertSame(1, Order::query()->count());
    }

    public function testAKeyMadeAgainWithAnotherWayToPayIsRefusedNeverPaidSo(): void
    {
        $first = $this->decode($this->placeOrder($this->website, $this->plan, $this->server, $this->card, key: 'one-attempt'))['checkout'];

        // The card's request, its key sent again with the wallet: the order would be paid another way than it was asked.
        $wallet = $this->placeOrder($this->website, $this->plan, $this->server, $this->walletMethod(), key: 'one-attempt');

        self::assertSame([422, [RequestKeyReusedException::OTHER_METHOD]], [$wallet->getStatusCode(), $this->decode($wallet)['errors']['idempotency_key'] ?? null]);
        self::assertSame('500000.00', $this->customer->balance(), 'nothing charged');
        self::assertSame([$this->card->id], RequestKey::query()->pluck('payment_method_id')->all(), 'the key keeps the way it was sent with');
        $again = $this->decode($this->placeOrder($this->website, $this->plan, $this->server, $this->card, key: 'one-attempt'))['checkout'];
        self::assertSame(['transfer', $first['order']['id']], [$again['outcome'], $again['order']['id']], 'made again as it was: its own order');

        // Another way to pay is another checkout attempt — a key of its own: the open order of the same thing, paid.
        $paid = $this->decode($this->placeOrder($this->website, $this->plan, $this->server, $this->walletMethod(), key: 'another-attempt'))['checkout'];
        self::assertSame(['settled', $first['order']['id']], [$paid['outcome'], $paid['order']['id']]);
    }

    public function testAKeyKeptBeforeKeysKeptTheirWayToPayIsAnsweredWhateverWayIsSent(): void
    {
        $first = $this->decode($this->placeOrder($this->website, $this->plan, $this->server, $this->card, key: 'from-before'))['checkout'];
        // What db:rebuild carries over of a key kept before keys kept their way to pay: none.
        RequestKey::query()->update(['payment_method_id' => null]);

        $again = $this->placeOrder($this->website, $this->plan, $this->server, $this->walletMethod(), key: 'from-before');

        self::assertSame(200, $again->getStatusCode(), (string) $again->getBody());
        self::assertSame(['settled', $first['order']['id']], [$this->decode($again)['checkout']['outcome'], $this->decode($again)['checkout']['order']['id']], 'its order, as it was answered then');
        self::assertSame(1, Order::query()->count());
    }

    public function testAKeyWhoseOrderWasCancelledSinceIsSaidSoInWordsOfItsOwn(): void
    {
        $this->placeOrder($this->website, $this->plan, $this->server, $this->card, key: 'left-unpaid');
        Carbon::setTestNow(now()->addHours(49));
        $this->service(ExpireOrdersTask::class)->run();

        $again = $this->placeOrder($this->website, $this->plan, $this->server, $this->card, key: 'left-unpaid');

        self::assertSame([409, CheckoutRefusedException::CANCELLED], [$again->getStatusCode(), $this->decode($again)['message']], 'not «a moment ago»: it went unpaid two days back');
        self::assertSame(1, Order::query()->count(), 'nothing ordered again');
    }

    public function testAMissingOrMalformedKeyIsRefused(): void
    {
        $body = ['plan_id' => $this->plan->id, 'server_id' => $this->server->id, 'method_id' => $this->card->id];

        $missing = $this->unchecked()->send('POST', $this->storeApi($this->website, '/orders'), $body);
        $malformed = $this->unchecked()->send('POST', $this->storeApi($this->website, '/orders'), $body, ['Idempotency-Key' => 'no spaces, please']);
        $tooLong = $this->unchecked()->send('POST', $this->storeApi($this->website, '/wallet/top-up'), ['amount' => 50000, 'method_id' => $this->card->id], ['Idempotency-Key' => str_repeat('k', 65)]);

        self::assertSame([422, [IdempotencyKey::MISSING]], [$missing->getStatusCode(), $this->decode($missing)['errors']['idempotency_key'] ?? null]);
        self::assertSame([422, [IdempotencyKey::MALFORMED]], [$malformed->getStatusCode(), $this->decode($malformed)['errors']['idempotency_key'] ?? null]);
        self::assertSame([422, [IdempotencyKey::MALFORMED]], [$tooLong->getStatusCode(), $this->decode($tooLong)['errors']['idempotency_key'] ?? null]);
        self::assertSame(0, Order::query()->count());
    }

    public function testTwoWithOneKeyInTheSameMomentMakeOneOrderOneChargeAndOneAnswer(): void
    {
        // The same request, sent twice at once: the other one is served this very moment — after this one looked for its
        // key's order the last time (the third: its replay, its payment, its order), before it makes its own —, so this
        // one's key meets the other's row, and its order goes with it.
        $looked = 0;
        $twin = $this->withATwin(
            QueryExecuted::class,
            static function (object $query) use (&$looked): bool {
                return $query instanceof QueryExecuted && str_starts_with($query->sql, self::KEYED) && ++$looked === 3;
            },
            fn(): ResponseInterface => $this->placeOrder($this->website, $this->plan, $this->server, $this->walletMethod()),
        );

        self::assertSame(200, $twin['this']->getStatusCode(), (string) $twin['this']->getBody());
        self::assertSame($this->decode($twin['twin']), $this->decode($twin['this']), 'one answer');
        self::assertSame('settled', $this->decode($twin['this'])['checkout']['outcome']);
        self::assertSame(1, Order::query()->count(), 'one order');
        self::assertSame('380000.00', $this->customer->balance(), 'one charge');
        self::assertCount(1, FakeProvider::$created);
    }

    public function testTwoWithOneKeyThatBothFindItsOrderPayItOnce(): void
    {
        // The other one is served the moment this one's order is made: both pay it, and its compare-and-swap lets one.
        $twin = $this->withATwin(
            TransactionCommitted::class,
            static fn(): bool => Order::query()->exists(),
            fn(): ResponseInterface => $this->placeOrder($this->website, $this->plan, $this->server, $this->walletMethod()),
        );

        self::assertSame(200, $twin['this']->getStatusCode(), (string) $twin['this']->getBody());
        self::assertSame($this->decode($twin['twin']), $this->decode($twin['this']), 'one answer');
        self::assertSame(1, Order::query()->count(), 'one order');
        self::assertSame('380000.00', $this->customer->balance(), 'one charge');
        self::assertSame(1, Payment::query()->count(), 'the payment that lost left nothing behind');
    }

    public function testTwoAccountsMergedKeepTheSurvivorsKey(): void
    {
        $older = $this->customer;
        $theirs = $this->decode($this->placeOrder($this->website, $this->plan, $this->server, $this->card, key: 'shared-key'))['checkout']['order']['id'];
        Carbon::setTestNow(now()->addMinute());
        $newer = $this->webCustomer();
        $this->bearer($this->customerSession($newer));
        $this->placeOrder($this->website, $this->plan, $this->server, $this->card, key: 'shared-key');
        $own = $this->decode($this->placeTopUp($this->website, 50000, $this->card, 'newer-key'))['checkout']['order']['id'];

        $survivor = $this->service(AccountMerger::class)->merge($older, $newer, AccountMerger::BY_CUSTOMER);

        self::assertSame($older->id, $survivor->id);
        $orders = $this->service(OrderService::class);
        self::assertSame($theirs, $orders->keyed($survivor, 'shared-key')?->id, 'a key both used: the one the account that stays used');
        self::assertSame($own, $orders->keyed($survivor, 'newer-key')?->id, 'the other account\'s own keys come with its orders');
        self::assertSame(2, RequestKey::query()->where('user_id', $survivor->id)->count());
    }

    public function testAKeyIsForgottenAWeekOn(): void
    {
        $first = $this->decode($this->placeOrder($this->website, $this->plan, $this->server, $this->walletMethod(), key: 'kept-a-week'))['checkout']['order']['id'];

        Carbon::setTestNow(now()->addDays(RequestKey::KEEP_DAYS)->subMinute());
        $this->service(ExpireOrdersTask::class)->run();
        self::assertSame(1, RequestKey::query()->count(), 'a week not over');

        Carbon::setTestNow(now()->addMinutes(2));
        $this->service(ExpireOrdersTask::class)->run();
        self::assertSame(0, RequestKey::query()->count());
        $again = $this->decode($this->placeOrder($this->website, $this->plan, $this->server, $this->walletMethod(), key: 'kept-a-week'))['checkout']['order']['id'];
        self::assertNotSame($first, $again, 'made again after a week: a new request');
    }

    /**
     * `$request` sent — and sent again, the twin, served by another process this very moment: the first time `$now`
     * says so of an `$event` the first one raises.
     *
     * @param \Closure(object): bool $now
     * @param \Closure(): ResponseInterface $request
     * @return array{this: ResponseInterface, twin: ResponseInterface}
     */
    private function withATwin(string $event, \Closure $now, \Closure $request): array
    {
        $twin = null;
        $first = $this->whileListening($event, static function (object $fired) use (&$twin, $now, $request): void {
            if ($twin === null && $now($fired)) {
                $twin = false; // what the twin raises is not this moment
                $twin = $request();
            }
        }, $request);

        return ['this' => $first, 'twin' => $twin instanceof ResponseInterface ? $twin : self::fail('The twin never ran.')];
    }
}
