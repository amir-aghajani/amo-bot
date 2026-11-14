<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Store\Models\Website;
use App\Modules\Users\Models\User;
use Tests\HttpTestCase;

/**
 * «همکارها فقط با قیمت همکاری» through the Store API: `GET /me` says whether the customers' perks reach the customer
 * (`perks`), and `GET /sales` lists no sale for one they do not — a visitor and everyone else see the shop's sales.
 */
final class StorePerksTest extends HttpTestCase
{
    private Website $website;
    private User $colleague;
    private User $plain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->website = $this->website();
        $this->colleague = $this->customer(['telegram_id' => 7002, 'username' => 'sara']);
        $this->plain = $this->customer(['telegram_id' => 7004, 'username' => 'ali']);
        $this->customerGroup('همکاران', [$this->colleague], ['agent_pricing' => true]);
        $this->sale('فروش ویژه', 20);
    }

    public function testOffThePerksReachEveryone(): void
    {
        $this->bearer($this->customerSession($this->colleague));

        self::assertTrue($this->decode($this->get($this->storeApi($this->website, '/me')))['perks']);
        self::assertCount(1, $this->decode($this->get($this->storeApi($this->website, '/sales')))['sales']);
    }

    public function testOnAColleagueIsToldSoAndSeesNoSale(): void
    {
        $this->botSettings('agent_perks', ['agents_without_perks' => true]);

        $this->bearer($this->customerSession($this->colleague));
        self::assertFalse($this->decode($this->get($this->storeApi($this->website, '/me')))['perks']);
        self::assertSame(['sales' => []], $this->decode($this->get($this->storeApi($this->website, '/sales'))));

        $this->bearer($this->customerSession($this->plain));
        self::assertTrue($this->decode($this->get($this->storeApi($this->website, '/me')))['perks']);
        self::assertCount(1, $this->decode($this->get($this->storeApi($this->website, '/sales')))['sales'], 'everyone else');

        $this->bearer(null);
        self::assertCount(1, $this->decode($this->get($this->storeApi($this->website, '/sales')))['sales'], 'a visitor');
    }

    public function testATokenThatDoesNotHoldIsRefusedOnTheSales(): void
    {
        $this->bearer(str_repeat('a', 64));

        self::assertSame(401, $this->get($this->storeApi($this->website, '/sales'))->getStatusCode());
    }
}
