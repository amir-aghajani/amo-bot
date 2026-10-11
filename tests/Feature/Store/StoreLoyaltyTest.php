<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Store\Models\Website;
use App\Modules\Users\Models\User;
use Tests\HttpTestCase;

/** A customer's loyalty standing in GET and PATCH /me: their tier, what they spent and the next tier. */
final class StoreLoyaltyTest extends HttpTestCase
{
    private Website $website;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->telegram();
        $this->website = $this->website();
        $this->customer = $this->webCustomer();
        $this->bearer($this->customerSession($this->customer));
    }

    public function testAShopWithNoTierSaysWhatTheyHaveSpentAlone(): void
    {
        $me = $this->decode($this->get($this->storeApi($this->website, '/me')));

        self::assertSame(['tier' => null, 'spent' => '0.00', 'next' => null], $me['loyalty']);
    }

    public function testTheirTierAndTheNextOne(): void
    {
        $server = $this->fakeServer();
        $order = $this->purchaseOrder($this->customer, $this->plan([], $server), $server, ['status' => OrderStatus::Fulfilled, 'amount' => '150000']);
        $this->cardPayment($order, $this->cardMethod(), ['status' => PaymentStatus::Paid, 'paid_at' => now()]);
        $silver = $this->loyaltyTier('نقره‌ای', '100000', 3, 1);
        $this->loyaltyTier('طلایی', '400000', 6, 2);

        $me = $this->decode($this->get($this->storeApi($this->website, '/me')));

        self::assertSame([
            'tier' => ['id' => $silver->id, 'name' => 'نقره‌ای', 'discount_percent' => 3, 'cashback_percent' => 1],
            'spent' => '150000.00',
            'next' => ['name' => 'طلایی', 'min_spent' => '400000.00', 'missing' => '250000.00'],
        ], $me['loyalty']);
        self::assertSame($me['loyalty'], $this->decode($this->patchJson($this->storeApi($this->website, '/me'), []))['loyalty'], 'PATCH /me answers as GET /me does');
    }
}
