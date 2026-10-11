<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Users\Models\WalletTransaction;
use Illuminate\Support\Carbon;
use Tests\HttpTestCase;

/** A free plan (or a free first purchase) ordered on the website is settled on the spot by the shop's wallet row. */
final class StoreFreePlansTest extends HttpTestCase
{
    public function testAFreePlanIsTakenWhicheverWayToPayIsSent(): void
    {
        Carbon::setTestNow('2026-10-08 12:00:00');
        $this->telegram();
        $website = $this->website();
        $server = $this->sellingServer('Berlin');
        $test = $this->plan(['name' => 'تست رایگان', 'price' => '0.00', 'duration_hours' => 1], $server);
        $card = $this->cardMethod();
        $this->bearer($this->customerSession($this->customer(['username' => 'ali'])));

        $response = $this->send('POST', "/api/store/v1/{$website->key}/orders", ['plan_id' => $test->id, 'server_id' => $server->id, 'method_id' => $card->id], ['Idempotency-Key' => 'free-1']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $checkout = $this->decode($response)['checkout'];
        self::assertSame(['settled', 'fulfilled', null], [$checkout['outcome'], $checkout['order']['status'], $checkout['transfer']], 'no transfer of nothing');
        self::assertSame([$this->walletMethod()->id, '0.00'], [$checkout['order']['payments'][0]['method']['id'], $checkout['order']['payments'][0]['amount']]);
        self::assertSame([1, 1], [$checkout['subscription']['term']['duration_hours'], $checkout['subscription']['term']['duration_days']], 'a one-hour test');
        self::assertSame(0, WalletTransaction::query()->count(), 'nothing taken, no line');
    }

    public function testAFreeFirstPurchaseIsTakenSoAndTheNextIsPaid(): void
    {
        Carbon::setTestNow('2026-10-08 12:00:00');
        $this->telegram();
        $website = $this->website();
        $server = $this->sellingServer('Berlin');
        $plan = $this->plan(['price' => '150000.00', 'first_purchase_price' => '0.00'], $server);
        $card = $this->cardMethod();
        $this->bearer($this->customerSession($this->customer(['username' => 'ali'])));
        $order = fn(string $key): array => $this->decode($this->send('POST', "/api/store/v1/{$website->key}/orders", ['plan_id' => $plan->id, 'server_id' => $server->id, 'method_id' => $card->id], ['Idempotency-Key' => $key]))['checkout'];

        $first = $order('first-1');
        self::assertSame(['settled', 'fulfilled', '0.00'], [$first['outcome'], $first['order']['status'], $first['order']['amount']], 'their first purchase, free: no transfer of nothing');

        $next = $order('next-1');
        self::assertSame(['transfer', '150000.00'], [$next['outcome'], $next['transfer']['amount'] ?? null], 'the next one by card, at the plan\'s price');
    }
}
