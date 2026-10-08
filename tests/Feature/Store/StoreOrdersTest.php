<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Core\Http\ErrorHandler;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\Models\Server;
use App\Modules\Store\Models\Website;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Tests\Fakes\FakeProvider;
use Tests\HttpTestCase;

/**
 * A customer's orders on the shop's website — their own alone, another's a 404 by the query itself —, newest first,
 * each with its payments: how each was paid, whether a receipt went with it, and support's word on its latest verdict —
 * which the customer is meant to read —, never the order's own notes, support's diagnosis.
 */
final class StoreOrdersTest extends HttpTestCase
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

    public function testTheyReadTheirOwnOrdersNewestFirstWithTheirPayments(): void
    {
        $bought = $this->purchaseOrder($this->customer, $this->plan, $this->server);
        $paid = $this->paidByCard($bought, $this->card);
        Carbon::setTestNow(now()->addMinute());
        $topUp = $this->topUpOrder($this->customer, '50000.00');
        $pending = $this->cardPayment($topUp, $this->card);
        $theirs = $this->topUpOrder($this->customer(['telegram_id' => 7272]), '10000.00');

        $answer = $this->decode($this->get($this->storeApi($this->website, '/orders')));
        $delivered = Subscription::query()->findOrFail($bought->refresh()->subscription_id);

        self::assertSame([$topUp->id, $bought->id], array_column($answer['orders'], 'id'));
        self::assertSame(['page' => 1, 'per_page' => 25, 'total' => 2, 'last_page' => 1], $answer['meta']);
        self::assertSame([
            'id' => $bought->id,
            'type' => 'purchase',
            'status' => 'fulfilled',
            'amount' => '120000.00',
            'created_at' => '2026-10-07T12:00:00+00:00',
            'fulfilled_at' => '2026-10-07T12:00:00+00:00',
            'plan' => ['id' => $this->plan->id, 'name' => 'یک‌ماهه'],
            'server' => ['id' => $this->server->id, 'name' => 'Berlin'],
            'subscription' => ['id' => $delivered->id, 'name' => $delivered->remote_name],
            'payments' => [[
                'id' => $paid->id,
                'method' => ['id' => $this->card->id, 'label' => 'کارت به کارت (ملت)', 'kind' => 'manual'],
                'status' => 'paid',
                'amount' => '120000.00',
                'created_at' => '2026-10-07T12:00:00+00:00',
                'paid_at' => '2026-10-07T12:00:00+00:00',
                'receipt' => ['sent_at' => '2026-10-07T12:00:00+00:00'],
                'note' => null,
            ]],
        ], $answer['orders'][1]);
        self::assertNotNull($bought->subscription_id, 'the service it delivered');
        self::assertSame([
            'id' => $topUp->id,
            'type' => 'wallet_topup',
            'status' => 'pending',
            'amount' => '50000.00',
            'created_at' => '2026-10-07T12:01:00+00:00',
            'fulfilled_at' => null,
            'plan' => null,
            'server' => null,
            'subscription' => null,
            'payments' => [[
                'id' => $pending->id,
                'method' => ['id' => $this->card->id, 'label' => 'کارت به کارت (ملت)', 'kind' => 'manual'],
                'status' => 'pending',
                'amount' => '50000.00',
                'created_at' => '2026-10-07T12:01:00+00:00',
                'paid_at' => null,
                'receipt' => null,
                'note' => null,
            ]],
        ], $this->decode($this->get($this->storeApi($this->website, "/orders/{$topUp->id}")))['order']);

        $response = $this->get($this->storeApi($this->website, "/orders/{$theirs->id}"));
        self::assertSame([404, ErrorHandler::NOT_FOUND], [$response->getStatusCode(), $this->decode($response)['message']], "another customer's order is not there");
    }

    public function testSupportsWordOnAVerdictIsTheirsTheOrdersNotesNever(): void
    {
        $unpaid = $this->purchaseOrder($this->customer, $this->plan, $this->server);
        $rejected = $this->receipt($this->cardPayment($unpaid, $this->card));
        $this->service(PaymentService::class)->reject($rejected, 'admin', 'مبلغ رسید با سفارش نمی‌خواند.');
        $retried = $this->cardPayment($unpaid, $this->card);

        $payments = $this->decode($this->get($this->storeApi($this->website, "/orders/{$unpaid->id}")))['order']['payments'];
        self::assertSame([[$retried->id, 'pending', null], [$rejected->id, 'failed', 'مبلغ رسید با سفارش نمی‌خواند.']], array_map(static fn(array $payment): array => [$payment['id'], $payment['status'], $payment['note']], $payments), 'the newest first; the rejection\'s reason is theirs to read');

        // Approved while the panel is out of reach: the delivery fails, with why on the order — the shop's screens' words.
        $failed = $this->purchaseOrder($this->customer, $this->plan, $this->server);
        FakeProvider::$unreachable = true;
        $this->paidByCard($failed, $this->card);
        self::assertSame(OrderStatus::Failed, $failed->refresh()->status);
        self::assertNotNull($failed->notes);
        self::assertNotNull($failed->diagnosis);

        $response = $this->get($this->storeApi($this->website, "/orders/{$failed->id}"));

        self::assertSame(['failed', 'paid'], [$this->decode($response)['order']['status'], $this->decode($response)['order']['payments'][0]['status']]);
        self::assertStringNotContainsString((string) $failed->notes, (string) $response->getBody(), "support's words stay in the shop");
        self::assertStringNotContainsString((string) $failed->diagnosis, (string) $response->getBody(), "and the owner's diagnosis");
    }

    public function testARenewalIsWhereItsServiceIsAndAWalletPaymentIsInstant(): void
    {
        $subscription = $this->buy($this->customer, $this->plan, $this->server)->subscription ?? self::fail('Nothing was delivered.');
        $renewal = $this->renew($subscription);

        $row = $this->decode($this->get($this->storeApi($this->website, "/orders/{$renewal->id}")))['order'];

        self::assertSame(['renewal', ['id' => $this->server->id, 'name' => 'Berlin'], ['id' => $subscription->id, 'name' => $subscription->remote_name]], [$row['type'], $row['server'], $row['subscription']], 'a renewal names no server of its own: its service\'s');
        self::assertSame(['id' => $this->walletMethod()->id, 'label' => $this->walletMethod()->label, 'kind' => 'instant'], $row['payments'][0]['method']);
    }

    public function testTheListIsNarrowedToAStateAndAKind(): void
    {
        $bought = $this->purchaseOrder($this->customer, $this->plan, $this->server);
        $topUp = $this->topUpOrder($this->customer, '50000.00');
        $paidTopUp = $this->topUpOrder($this->customer, '20000.00');
        $this->paidByCard($paidTopUp, $this->card);

        $ids = fn(string $query): array => array_column($this->decode($this->get($this->storeApi($this->website, "/orders?{$query}")))['orders'], 'id');

        self::assertSame([$topUp->id, $bought->id], $ids('status=pending'));
        self::assertSame([$paidTopUp->id, $topUp->id], $ids('type=wallet_topup'));
        self::assertSame([$topUp->id], $ids('status=pending&type=wallet_topup'));
        self::assertSame([$paidTopUp->id, $topUp->id, $bought->id], array_column($this->decode($this->unchecked()->get($this->storeApi($this->website, '/orders?status=lost&type=gift')))['orders'], 'id'), 'no such state or kind: no filter');
    }
}
