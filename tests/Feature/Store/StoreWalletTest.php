<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Store\Models\Website;
use App\Modules\Users\Models\WalletTransaction;
use App\Modules\Users\Services\WalletService;
use Illuminate\Support\Carbon;
use Tests\HttpTestCase;

/**
 * A customer's wallet on the shop's website, as the bot's «کیف پول» has it: its balance — an agent's debt below zero —,
 * an agent's credit and what the two can pay, what a top-up may be (the bot's «افزایش موجودی» bounds), and its ledger,
 * newest line first, a page at a time — theirs alone.
 */
final class StoreWalletTest extends HttpTestCase
{
    private Website $website;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->website = $this->website();
    }

    public function testTheWalletIsTheBotsWallet(): void
    {
        $customer = $this->wallet($this->customer(), '75000');
        $this->bearer($this->customerSession($customer));

        self::assertSame([
            'balance' => '75000.00',
            'credit' => '0.00',
            'spendable' => '75000.00',
            'top_up' => ['min' => '10000.00', 'max' => '500000000.00', 'presets' => ['50000.00', '100000.00', '200000.00', '500000.00']],
        ], $this->decode($this->get($this->storeApi($this->website, '/wallet'))));

        $this->botSettings('wallet', ['topup_min' => 20000, 'topup_presets' => [20000, 80000]]);
        self::assertSame(['min' => '20000.00', 'max' => '500000000.00', 'presets' => ['20000.00', '80000.00']], $this->decode($this->get($this->storeApi($this->website, '/wallet')))['top_up'], "the shop's own bounds, as the bot offers them");
    }

    public function testATopUpTakesTheAmountsAsTheWalletWritesThem(): void
    {
        $this->bearer($this->customerSession($this->customer()));
        $card = $this->cardMethod();
        $topUp = $this->decode($this->get($this->storeApi($this->website, '/wallet')))['top_up'];

        foreach ([$topUp['min'], $topUp['presets'][0], $topUp['max']] as $i => $amount) {
            $response = $this->send('POST', $this->storeApi($this->website, '/wallet/top-up'), ['amount' => $amount, 'method_id' => $card->id], ['Idempotency-Key' => "as-written-{$i}"]);

            self::assertSame(200, $response->getStatusCode(), "{$amount}: " . $response->getBody());
            self::assertSame($amount, $this->decode($response)['checkout']['transfer']['amount'] ?? null, $amount);
        }
    }

    public function testAnAgentsCreditCountsAndTheirDebtIsBelowZero(): void
    {
        $agent = $this->wallet($this->agent(credit: '50000'), '-20000');
        $this->bearer($this->customerSession($agent));

        $wallet = $this->decode($this->get($this->storeApi($this->website, '/wallet')));

        self::assertSame(['-20000.00', '50000.00', '30000.00'], [$wallet['balance'], $wallet['credit'], $wallet['spendable']]);
    }

    public function testTheLedgerNewestLineFirstAPageAtATime(): void
    {
        $customer = $this->customer();
        $wallet = $this->service(WalletService::class);
        foreach (range(1, 27) as $n) {
            $wallet->credit($customer, 1000, "شارژ {$n}");
        }
        $wallet->debit($customer, 5000, 'پرداخت سفارش #9 (یک‌ماهه)');
        $this->wallet($this->customer(['telegram_id' => 7272]), '9000');
        $this->bearer($this->customerSession($customer));

        $first = $this->decode($this->get($this->storeApi($this->website, '/wallet/transactions')));

        self::assertSame(['page' => 1, 'per_page' => 25, 'total' => 28, 'last_page' => 2], $first['meta'], 'theirs alone');
        $newest = WalletTransaction::query()->where('user_id', $customer->id)->latest('id')->firstOrFail();
        self::assertSame([
            'id' => $newest->id,
            'type' => 'debit',
            'amount' => '5000.00',
            'balance_after' => '22000.00',
            'description' => 'پرداخت سفارش #9 (یک‌ماهه)',
            'created_at' => '2026-10-07T12:00:00+00:00',
        ], $first['transactions'][0]);

        $second = $this->decode($this->get($this->storeApi($this->website, '/wallet/transactions?page=2')))['transactions'];
        self::assertSame(['شارژ 3', 'شارژ 2', 'شارژ 1'], array_column($second, 'description'), 'the oldest last');
        self::assertSame(['3000.00', '2000.00', '1000.00'], array_column($second, 'balance_after'));
    }

    public function testAWebsiteCustomersWalletWithoutALineIsEmpty(): void
    {
        $this->bearer($this->customerSession($this->webCustomer()));

        self::assertSame(['0.00', '0.00', '0.00'], array_values(array_slice($this->decode($this->get($this->storeApi($this->website, '/wallet'))), 0, 3)));
        self::assertSame(['transactions' => [], 'meta' => ['page' => 1, 'per_page' => 25, 'total' => 0, 'last_page' => 1]], $this->decode($this->get($this->storeApi($this->website, '/wallet/transactions'))));
    }
}
