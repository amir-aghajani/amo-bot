<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Discounts\Services\DiscountCodes;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Providers\Models\Server;
use App\Modules\Store\Models\Website;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\Fakes\FakeProvider;
use Tests\HttpTestCase;

/**
 * Discounts on the website: offer prices and automatic discounts, discount codes on purchases, renewals and extras,
 * checking a code before paying (rate-limited), and repeated requests answered by their order whatever the code says.
 */
final class StoreDiscountsTest extends HttpTestCase
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
        $this->plan = $this->plan(['price' => '100000.00'], $this->server);
        $this->card = $this->cardMethod();
        $this->bearer($this->customerSession($this->customer));
    }

    public function testTheCatalogueSaysWhatTheReaderPaysToday(): void
    {
        $this->sale('فروش ویژه نوروز', 20, ['ends_at' => '2026-10-10 00:00:00']);

        $plan = $this->decode($this->get($this->storeApi($this->website, "/plans/{$this->plan->id}")))['plan'];
        self::assertSame(['100000.00', '80000.00'], [$plan['price'], $plan['offer_price']]);
        self::assertSame(['source' => 'sale', 'label' => 'فروش ویژه نوروز ۲۰٪', 'percent' => 20, 'ends_at' => '2026-10-10T00:00:00+00:00'], $plan['discount']);

        $this->customer->forceFill(['discount_percent' => 25])->save();
        $groups = $this->decode($this->get($this->storeApi($this->website, '/plans')))['groups'];
        self::assertSame(['75000.00', 'personal'], [$groups[0]['plans'][0]['offer_price'], $groups[0]['plans'][0]['discount']['source']], "the reader's own, the larger");

        $this->bearer(null);
        $guest = $this->decode($this->get($this->storeApi($this->website, "/plans/{$this->plan->id}")))['plan'];
        self::assertSame('80000.00', $guest['offer_price'], 'a guest: the sale alone');
    }

    public function testAPurchaseWithACodeIsMadeAtItAndSaysWhatWasTakenOff(): void
    {
        $this->sale('حراج', 10);
        $this->discountCode('NOWRUZ', 20);
        $this->wallet($this->customer, '500000');

        $response = $this->purchase($this->walletMethod(), ['discount_code' => 'nowruz']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $order = $this->decode($response)['checkout']['order'];
        self::assertSame(['72000.00', '28000.00'], [$order['amount'], $order['discount_amount']]);
        self::assertSame([
            ['source' => 'sale', 'label' => 'حراج ۱۰٪', 'amount' => '10000.00', 'percent' => 10],
            ['source' => 'code', 'label' => 'کد NOWRUZ', 'amount' => '18000.00', 'percent' => 20],
        ], $order['discounts']);
        self::assertSame('NOWRUZ', $order['discount_code']['code']);
        self::assertSame('428000.00', $this->customer->balance());

        $read = $this->decode($this->get($this->storeApi($this->website, "/orders/{$order['id']}")))['order'];
        self::assertSame($order['discounts'], $read['discounts'], 'the order says it as it is read again');
    }

    public function testACodeThatDoesNotHoldIsRefusedOnItsFieldAndNothingIsOrdered(): void
    {
        $this->discountCode('RENEW', 20, ['order_types' => ['renewal']]);

        $response = $this->purchase($this->card, ['discount_code' => 'RENEW']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['discount_code' => ['این کد تخفیف برای خرید نیست.']], $this->decode($response)['errors']);
        self::assertSame(0, Order::query()->count());
    }

    public function testARequestMadeAgainIsAnsweredByItsOrderWhateverItsCodeSaysNow(): void
    {
        $code = $this->discountCode('ONCE', 50, ['max_uses' => 1]);
        $first = $this->decode($this->purchase($this->card, ['discount_code' => 'ONCE'], 'k-1'))['checkout']['order'];

        $code->forceFill(['enabled' => false])->save();
        $again = $this->purchase($this->card, ['discount_code' => 'ONCE'], 'k-1');

        self::assertSame(200, $again->getStatusCode(), (string) $again->getBody());
        self::assertSame([$first['id'], '50000.00'], [$this->decode($again)['checkout']['order']['id'], $this->decode($again)['checkout']['order']['amount']]);
        self::assertSame(1, Order::query()->count());
    }

    public function testARenewalAndAnExtraTakeACodeAndThePreviewIsAtTheAutomaticPrice(): void
    {
        FakeProvider::$subscriptionBase = 'https://sub.example';
        $this->extrasOnSale();
        $this->customer->forceFill(['discount_percent' => 10])->save();
        $service = $this->subscription($this->customer, $this->plan, $this->server, 'ali_1');
        FakeProvider::mirror($service);
        $this->discountCode('KEEP', 0, ['type' => \App\Modules\Discounts\Enums\DiscountType::Fixed, 'value' => '5000']);

        $preview = $this->decode($this->get($this->storeApi($this->website, "/subscriptions/{$service->id}/renewal")))['renewal'];
        self::assertSame('90000.00', $preview['price']);
        self::assertSame([['source' => 'personal', 'label' => 'تخفیف اختصاصی شما ۱۰٪', 'amount' => '10000.00', 'percent' => 10]], $preview['discounts']);

        $renewed = $this->send('POST', $this->storeApi($this->website, "/subscriptions/{$service->id}/renewal"), ['method_id' => $this->card->id, 'discount_code' => 'KEEP'], ['Idempotency-Key' => 'renew-1']);
        self::assertSame('85000.00', $this->decode($renewed)['checkout']['order']['amount'], (string) $renewed->getBody());

        $extra = $this->send('POST', $this->storeApi($this->website, "/subscriptions/{$service->id}/extra-traffic"), ['gb' => 10, 'method_id' => $this->card->id, 'discount_code' => 'KEEP'], ['Idempotency-Key' => 'extra-1']);
        // 18,000 less the customer's 10% (1,800), then 5,000.
        self::assertSame('11200.00', $this->decode($extra)['checkout']['order']['amount'], (string) $extra->getBody());
    }

    public function testTheCheckSaysTheQuoteBeforePaying(): void
    {
        $this->discountCode('NOWRUZ', 20, ['server_ids' => [$this->server->id]]);

        $quote = $this->decode($this->check(['code' => 'nowruz', 'for' => 'purchase', 'plan_id' => $this->plan->id, 'server_id' => $this->server->id]))['quote'];
        self::assertSame(['base' => '100000.00', 'discounts' => [['source' => 'code', 'label' => 'کد NOWRUZ', 'amount' => '20000.00', 'percent' => 20]], 'amount' => '80000.00'], $quote);

        $refused = $this->check(['code' => 'nowruz', 'for' => 'purchase', 'plan_id' => $this->plan->id]);
        self::assertSame([422, ['code' => [DiscountCodes::WRONG_SERVER]]], [$refused->getStatusCode(), $this->decode($refused)['errors']], 'no server picked yet');

        $service = $this->subscription($this->customer, $this->plan, $this->server, 'ali_1');
        $renewal = $this->decode($this->check(['code' => 'NOWRUZ', 'for' => 'renewal', 'subscription_id' => $service->id]))['quote'];
        self::assertSame('80000.00', $renewal['amount']);

        $other = Subscription::query()->whereKey($this->subscription($this->customer(['telegram_id' => 7001]), $this->plan, $this->server, 'sara_1')->id)->sole();
        self::assertSame(404, $this->check(['code' => 'NOWRUZ', 'for' => 'renewal', 'subscription_id' => $other->id])->getStatusCode(), "someone else's service");
        self::assertSame(0, Order::query()->count(), 'nothing ordered');
    }

    public function testWrongCodesAreHeldToTwentyAnHour(): void
    {
        for ($i = 0; $i < DiscountCodes::WRONG_CODES; ++$i) {
            self::assertSame(422, $this->check(['code' => "GUESS{$i}", 'for' => 'purchase', 'plan_id' => $this->plan->id])->getStatusCode());
        }

        $held = $this->check(['code' => 'GUESS', 'for' => 'purchase', 'plan_id' => $this->plan->id]);
        self::assertSame(429, $held->getStatusCode());
        self::assertSame('3600', $held->getHeaderLine('Retry-After'));

        $purchase = $this->purchase($this->card, ['discount_code' => 'ANOTHER']);
        self::assertSame(429, $purchase->getStatusCode(), "the checkout's code too");
    }

    /** @param array<string, mixed> $extra */
    private function purchase(PaymentMethod $method, array $extra = [], string $key = 'key-1'): ResponseInterface
    {
        return $this->send('POST', $this->storeApi($this->website, '/orders'), ['plan_id' => $this->plan->id, 'server_id' => $this->server->id, 'method_id' => $method->id] + $extra, ['Idempotency-Key' => $key]);
    }

    /** @param array<string, mixed> $body */
    private function check(array $body): ResponseInterface
    {
        return $this->postJson($this->storeApi($this->website, '/discount-codes/check'), $body);
    }
}
