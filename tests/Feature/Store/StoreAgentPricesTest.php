<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderPricing;
use App\Modules\Orders\Models\Order;
use App\Modules\Providers\Models\Server;
use App\Modules\Store\Models\Website;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Tests\HttpTestCase;

/**
 * Agent prices on the website (an agent or a colleague reads and pays them, anyone else sees null), and a plan's
 * checkout note.
 */
final class StoreAgentPricesTest extends HttpTestCase
{
    private const NOTE = 'روی Happ و v2rayNG تست شده است.';

    private Website $website;
    private Server $server;
    private Plan $plan;
    private User $sara;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-08 12:00:00');
        $this->telegram();
        $this->withoutQr();
        $this->website = $this->website();
        $this->server = $this->sellingServer('Berlin');
        $this->plan = $this->plan(['price' => '150000.00', 'agent_price' => '100000.00', 'checkout_note' => self::NOTE], $this->server);
        $this->sara = $this->wallet($this->customer(['username' => 'sara']), '500000.00');
        $this->customerGroup('همکاران', [$this->sara], ['agent_pricing' => true]);
    }

    public function testAColleagueReadsItAsTheirsAnyoneElseNull(): void
    {
        self::assertSame([null, self::NOTE], $this->offered(), 'a guest: no agent price, the note');

        $this->bearer($this->customerSession($this->sara));
        self::assertSame(['100000.00', self::NOTE], $this->offered());
        $one = $this->decode($this->get($this->storeApi($this->website, "/plans/{$this->plan->id}")))['plan'];
        self::assertSame(['150000.00', '100000.00'], [$one['price'], $one['agent_price']], 'beside the plan\'s price');

        $this->bearer($this->customerSession($this->customer(['telegram_id' => 7004, 'username' => 'ali'])));
        self::assertSame([null, self::NOTE], $this->offered(), 'a customer of no such group');
    }

    public function testTheyAreChargedItAndTheirOrderSaysSo(): void
    {
        $this->bearer($this->customerSession($this->sara));

        $response = $this->placeOrder($this->website, $this->plan, $this->server, $this->walletMethod(), key: 'agent-1');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $order = $this->decode($response)['checkout']['order'];
        self::assertSame(['fulfilled', '100000.00', 'agent'], [$order['status'], $order['amount'], $order['pricing']]);
        self::assertSame('400000.00', $this->sara->balance());

        $service = Order::query()->sole()->subscription ?? self::fail('nothing delivered');
        $renewal = $this->decode($this->get($this->storeApi($this->website, "/subscriptions/{$service->id}/renewal")))['renewal'];
        self::assertSame(['100000.00', '150000.00', self::NOTE], [$renewal['price'], $renewal['plan']['price'], $renewal['plan']['checkout_note']], 'renewed at it, the plan\'s own price and note beside it');
        self::assertSame(OrderPricing::Agent, Order::query()->sole()->pricing);
    }

    /** @return array{string|null, string|null} The plan's agent price and note, as GET /plans offers it to the reader. */
    private function offered(): array
    {
        $response = $this->get($this->storeApi($this->website, '/plans'));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $plan = $this->decode($response)['groups'][0]['plans'][0];

        return [$plan['agent_price'], $plan['checkout_note']];
    }
}
