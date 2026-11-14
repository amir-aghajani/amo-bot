<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Discounts\Exceptions\DiscountCodeRefusedException;
use App\Modules\Discounts\Services\Loyalty;
use App\Modules\Discounts\Services\Perks;
use App\Modules\Orders\Enums\OrderPricing;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Services\Pricing;
use App\Modules\Providers\Models\Server;
use App\Modules\Rewards\Enums\WheelRefusal;
use App\Modules\Rewards\Exceptions\WheelRefusedException;
use App\Modules\Rewards\Services\Cashback;
use App\Modules\Rewards\Services\LuckyWheel;
use App\Modules\Subscriptions\Enums\ExtraKind;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Messages;
use App\Modules\Users\Models\User;
use Tests\BotTestCase;

/**
 * «همکارها فقط با قیمت همکاری» (Perks): while it is on, whoever pays agent prices — an agent, a «همکار» group's customer —
 * goes without the customers' perks, whatever they buy: no sale, loyalty tier, code, first-purchase price, cashback or
 * wheel — in the bot's screens too; where no agent price applies, the regular price. Their own discount stays. Off (the
 * default), they have them all, as before. Each shop its own.
 */
final class AgentPerksTest extends BotTestCase
{
    private const COLLEAGUE = 7002;

    private Server $server;
    private Plan $plan;
    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = $this->sellingServer();
        $this->plan = $this->plan(['price' => '150000.00', 'agent_price' => '100000.00', 'first_purchase_price' => '90000.00'], $this->server);
        $this->colleague = $this->customer(['telegram_id' => self::COLLEAGUE, 'username' => 'sara']);
        $this->customerGroup('همکاران', [$this->colleague], ['agent_pricing' => true]);
        $this->sale('فروش ویژه', 20);
        $this->loyaltyTier('همه', '0', 10, 5);
        $this->discountCode('NOWRUZ', 10);
    }

    public function testOffTheyHaveEveryPerkAsBefore(): void
    {
        $quote = $this->pricing()->purchase($this->colleague, $this->plan, $this->server, 'NOWRUZ');

        self::assertSame([OrderPricing::FirstPurchase, '90000.00'], [$quote->pricing, $quote->base], 'the lowest of their prices: the first purchase\'s');
        self::assertCount(2, $quote->lines(), 'the largest automatic discount, then the code');
        self::assertFalse($this->service(Perks::class)->withheld($this->colleague));
    }

    public function testOnAnAgentAndAColleaguePayTheAgentPriceAndNothingComesOffIt(): void
    {
        $this->perksWithheld();
        $agent = $this->agent(overrides: ['telegram_id' => 7003]);
        $plain = $this->customer(['telegram_id' => 7004, 'username' => 'ali']);

        foreach ([[$this->colleague, 'a colleague'], [$agent, 'an agent']] as [$who, $label]) {
            $quote = $this->pricing()->purchase($who, $this->plan, $this->server);
            self::assertSame([OrderPricing::Agent, '100000.00', [], '100000.00'], [$quote->pricing, $quote->base, $quote->lines(), $quote->amount()], "{$label}: no first-purchase price, no sale, no tier");
            self::assertNull($this->service(Loyalty::class)->tierOf($who), $label);
            self::assertSame([], $this->service(Loyalty::class)->tiersFor($who), $label);
            self::assertSame([null, null], [$this->service(Loyalty::class)->standing($who)['tier'], $this->service(Loyalty::class)->standing($who)['next']], "{$label}: no tier to stand on");

            try {
                $this->pricing()->purchase($who, $this->plan, $this->server, 'NOWRUZ');
                self::fail("{$label}: a code taken");
            } catch (DiscountCodeRefusedException $e) {
                self::assertSame(Perks::NO_CODES, $e->getMessage(), $label);
            }
        }

        $quote = $this->pricing()->purchase($plain, $this->plan, $this->server, 'NOWRUZ');
        self::assertSame([OrderPricing::FirstPurchase, 2], [$quote->pricing, count($quote->lines())], 'everyone else as before');
    }

    public function testOnWhereNoAgentPriceAppliesTheyPayTheRegularPriceWithNothingOff(): void
    {
        $this->perksWithheld();
        $plain = $this->customer(['telegram_id' => 7004, 'username' => 'ali']);
        $without = $this->plan(['price' => '100000.00', 'first_purchase_price' => '70000.00'], $this->server);

        $colleague = $this->pricing()->purchase($this->colleague, $without, $this->server);
        self::assertSame([OrderPricing::Regular, '100000.00'], [$colleague->pricing, $colleague->amount()], 'a plan without an agent price: no first-purchase price, no sale, no tier');
        self::assertSame('56000.00', $this->pricing()->purchase($plain, $without, $this->server)->amount(), 'everyone else: the first purchase, less the sale');

        $theirs = $this->subscription($this->colleague, $this->plan, $this->server, 'sara_1');
        $hers = $this->subscription($plain, $this->plan, $this->server, 'ali_1');
        self::assertSame('50000.00', $this->pricing()->extra($this->colleague, $theirs, ExtraKind::Traffic, '50000')->amount(), 'an extra at its own price');
        self::assertSame('40000.00', $this->pricing()->extra($plain, $hers, ExtraKind::Traffic, '50000')->amount());
    }

    public function testOnTheirOwnDiscountStays(): void
    {
        $this->perksWithheld();
        $this->colleague->forceFill(['discount_percent' => 5])->save();

        $quote = $this->pricing()->purchase($this->colleague->refresh(), $this->plan, $this->server);

        self::assertSame('95000.00', $quote->amount(), 'the owner\'s choice for them, off the agent price');
    }

    public function testOnTheyEarnNoCashbackAndTheWheelIsOffForThem(): void
    {
        $this->cashbackOn();
        $this->cashbackRule(10);
        $this->wheelOn();
        $this->wheelPrize();
        $order = $this->purchaseOrder($this->colleague, $this->plan, $this->server, ['status' => OrderStatus::Fulfilled, 'amount' => '100000.00']);
        self::assertSame(15, $this->service(Cashback::class)->percentFor($order), 'off: the rule and the tier');

        $this->perksWithheld();

        self::assertSame(0, $this->service(Cashback::class)->percentFor($order->refresh()));
        try {
            $this->service(LuckyWheel::class)->spin($this->colleague);
            self::fail('spun');
        } catch (WheelRefusedException $e) {
            self::assertSame(WheelRefusal::Off, $e->reason);
        }
    }

    public function testOnTheBotsWalletScreenShowsThemNoTier(): void
    {
        $this->withoutQr();
        $this->send($this->tap(MainMenu::WALLET, self::COLLEAGUE));
        self::assertStringContainsString('همه', $this->said()[0], 'off: their tier');

        $this->perksWithheld();
        $this->send($this->tap(MainMenu::WALLET, self::COLLEAGUE));

        self::assertStringNotContainsString('همه', $this->said()[0], 'on: they stand on none');
    }

    public function testOnTheBotsMenuHasNoWheelForThem(): void
    {
        $this->wheelOn();
        $this->wheelPrize();
        $this->customer(['telegram_id' => 7004, 'username' => 'ali']);
        $this->perksWithheld();

        $this->send($this->message('/start', [], self::COLLEAGUE));
        self::assertNotContains(Messages::MENU_WHEEL, array_merge(...$this->replyKeyboard(0)), 'off for them: no button that says so');

        $this->send($this->message('/start', [], 7004));
        self::assertContains(Messages::MENU_WHEEL, array_merge(...$this->replyKeyboard(0)), 'everyone else keeps it');
    }

    public function testEachShopHasItsOwnSwitch(): void
    {
        $this->perksWithheld();

        CurrentBot::run($this->agentBot(), function (): void {
            $plan = $this->plan(['price' => '100000.00', 'agent_price' => '90000.00'], $this->server);
            $colleague = $this->customer(['telegram_id' => 8101]);
            $this->customerGroup('همکاران', [$colleague], ['agent_pricing' => true]);
            $this->sale('فروش', 20);

            self::assertSame('72000.00', $this->pricing()->purchase($colleague, $plan, $this->server)->amount(), 'off in the agent\'s shop: the sale off the agent price');
        });
    }

    private function perksWithheld(): void
    {
        $this->botSettings('agent_perks', ['agents_without_perks' => true]);
    }

    private function pricing(): Pricing
    {
        return $this->service(Pricing::class);
    }
}
