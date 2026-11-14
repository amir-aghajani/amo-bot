<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Providers\Models\Server;
use App\Modules\Telegram\Handlers\DiscountCodeHandler;
use App\Modules\Users\Models\User;
use Tests\BotTestCase;

/**
 * «همکارها فقط با قیمت همکاری» in the bot's checkout: while it is on, one who pays agent prices is not offered «🏷 کد
 * تخفیف دارم» — every code would be refused, each try spending one of their wrong-code tries. Off, they are offered it
 * as anyone.
 */
final class BotAgentPerksTest extends BotTestCase
{
    private Server $server;
    private Plan $plan;
    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutQr();
        $this->server = $this->sellingServer();
        $this->plan = $this->plan(['price' => '150000.00', 'agent_price' => '100000.00'], $this->server);
        $this->colleague = $this->wallet($this->customer(), '500000.00');
        $this->customerGroup('همکاران', [$this->colleague], ['agent_pricing' => true]);
        $this->walletMethod();
        $this->cardMethod();
        $this->discountCode('NOWRUZ', 10);
    }

    public function testOffTheyAreOfferedACodeAsAnyone(): void
    {
        $this->send($this->tap($this->checkoutOf($this->plan, $this->server)));

        self::assertContains(DiscountCodeHandler::askCallback($this->checkoutOf($this->plan, $this->server)), $this->callbacks(0));
    }

    public function testOnOneWhoPaysAgentPricesIsNotOfferedACode(): void
    {
        $this->botSettings('agent_perks', ['agents_without_perks' => true]);

        $this->send($this->tap($this->checkoutOf($this->plan, $this->server)));

        self::assertNotEmpty($this->callbacks(0), 'the checkout, its ways to pay');
        self::assertNotContains(DiscountCodeHandler::askCallback($this->checkoutOf($this->plan, $this->server)), $this->callbacks(0));
    }
}
