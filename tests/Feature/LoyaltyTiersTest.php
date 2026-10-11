<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Discounts\Models\LoyaltyTier;
use App\Modules\Discounts\Services\Loyalty;
use App\Modules\Notifications\Enums\NoticeType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Orders\Enums\OrderPricing;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Providers\Models\Server;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Models\User;
use App\Support\Money;
use Tests\BotTestCase;
use Tests\Fakes\FakeProvider;

/**
 * Loyalty tiers: what counts as spent (paid and not given back, bought for themselves, never a free service), the tier it
 * puts a customer on, a promotion told exactly once however deliveries interleave, and the wallet screen's tier line.
 */
final class LoyaltyTiersTest extends BotTestCase
{
    private Server $server;
    private Plan $plan;
    private User $ali;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutQr();
        $this->server = $this->sellingServer();
        $this->plan = $this->plan(['price' => '120000.00'], $this->server);
        $this->ali = $this->customer(['username' => 'ali']);
    }

    public function testWhatACustomerSpentCountsOnlyWhatTheyPaidAndKept(): void
    {
        $this->buy($this->ali, $this->plan, $this->server);
        $this->purchaseOrder($this->ali, $this->plan, $this->server, ['status' => OrderStatus::Refunded, 'amount' => '90000']);
        $this->purchaseOrder($this->ali, $this->plan, $this->server, ['status' => OrderStatus::Cancelled, 'amount' => '90000']);
        $this->purchaseOrder($this->ali, $this->plan, $this->server, ['amount' => '90000']);
        $this->purchaseOrder($this->ali, $this->plan, $this->server, ['status' => OrderStatus::Fulfilled, 'amount' => '0', 'pricing' => OrderPricing::Gift]);
        $this->topUpOrder($this->ali, '500000', ['status' => OrderStatus::Fulfilled]);
        $this->purchaseOrder($this->ali, $this->plan, $this->server, ['status' => OrderStatus::Failed, 'amount' => '30000']);

        self::assertSame('150000.00', $this->loyalty()->spent($this->ali), 'the purchase delivered and the one waiting for its retry: a refund, a cancelled or unpaid order, a gift and a top-up are nothing spent');
    }

    public function testTheHighestTierReachedIsTheirsAndANextOneIsLeft(): void
    {
        $bronze = $this->loyaltyTier('برنزی', '0', 0, 0);
        $silver = $this->loyaltyTier('نقره‌ای', '100000', 3, 1);
        $this->loyaltyTier('طلایی', '300000', 5, 2);
        $sara = $this->customer(['telegram_id' => 2002]);

        self::assertSame($bronze->id, $this->tierOf($sara)?->id, 'a tier from nothing spent holds every customer');
        $this->buy($this->ali, $this->plan, $this->server);

        self::assertSame($silver->id, $this->tierOf($this->ali)?->id);
        self::assertSame([
            'tier' => ['id' => $silver->id, 'name' => 'نقره‌ای', 'discount_percent' => 3, 'cashback_percent' => 1],
            'spent' => '120000.00',
            'next' => ['name' => 'طلایی', 'min_spent' => '300000.00', 'missing' => '180000.00'],
        ], $this->loyalty()->standing($this->ali));
        self::assertNull($this->tierOf(null));
    }

    public function testAShopWithNoTierHasNone(): void
    {
        $this->buy($this->ali, $this->plan, $this->server);

        self::assertNull($this->tierOf($this->ali));
        self::assertSame(['tier' => null, 'spent' => '120000.00', 'next' => null], $this->loyalty()->standing($this->ali));
    }

    public function testADeliveryThatMovesTheCustomerUpTellsThemOnce(): void
    {
        $this->loyaltyTier('نقره‌ای', '200000', 0, 3);
        $this->loyaltyTier('طلایی', '300000', 10, 0);

        $this->buy($this->ali, $this->plan, $this->server);
        self::assertSame([], $this->tierNotices(), 'below the first tier: nothing to tell');

        $this->buy($this->ali, $this->plan, $this->server);
        $told = $this->tierNotices();
        self::assertCount(1, $told);
        self::assertSame(self::text(BotText::LoyaltyTierUp, [
            'loyalty_tier' => 'نقره‌ای',
            'tier_perks' => self::text(BotText::LoyaltyPerkCashback, ['tier_cashback' => '۳٪']),
        ]), $told[0], 'its perks, each a line — a discount of none is not one');
        self::assertContains($told[0], $this->telegram()->sentTo(self::CHAT), 'in Telegram too');

        $this->buy($this->ali, $this->plan, $this->server);
        self::assertCount(2, $told = $this->tierNotices(), 'gold: told again, for the next tier');
        self::assertStringContainsString('طلایی', $told[1]);

        $this->buy($this->ali, $this->plan, $this->server);
        self::assertCount(2, $this->tierNotices(), 'past the top tier: nothing new');
    }

    public function testTwoDeliveriesThatReachATierTogetherTellItOnce(): void
    {
        $silver = $this->loyaltyTier('نقره‌ای', '200000', 0, 0);
        $first = $this->paidOrder();
        $second = $this->paidOrder();
        self::assertSame('240000.00', $this->loyalty()->spent($this->ali), 'both paid, neither delivered yet');

        $this->service(OrderService::class)->deliver($first);
        $this->service(OrderService::class)->deliver($second);

        self::assertSame(OrderStatus::Fulfilled, $second->refresh()->status);
        self::assertCount(1, $this->tierNotices(), 'the delivery that completed the crossing told it, the other did not');
        // However late either asks, the crossing is one order's: the other, counted with it, moved nobody.
        self::assertNull($this->loyalty()->promotedBy($first->refresh()));
        self::assertSame($silver->id, $this->loyalty()->promotedBy($second)?->id);
    }

    public function testADeliveryRetriedLaterTellsTheTierItCompleted(): void
    {
        $this->loyaltyTier('نقره‌ای', '200000', 0, 0);
        $this->buy($this->ali, $this->plan, $this->server);
        FakeProvider::$unreachable = true;
        $failed = $this->paidOrder();
        $this->service(OrderService::class)->deliver($failed);
        self::assertSame(OrderStatus::Failed, $failed->refresh()->status);
        self::assertSame([], $this->tierNotices(), 'a failed delivery tells nothing');

        FakeProvider::$unreachable = false;
        $this->service(OrderService::class)->deliver($failed);

        self::assertSame(OrderStatus::Fulfilled, $failed->refresh()->status);
        self::assertCount(1, $this->tierNotices());
    }

    public function testAServiceGivenFreeMovesNobody(): void
    {
        $this->loyaltyTier('برنزی', '1', 0, 0);
        $gift = $this->paidOrder(['amount' => '0', 'pricing' => OrderPricing::Gift]);

        $this->service(OrderService::class)->deliver($gift);

        self::assertSame(OrderStatus::Fulfilled, $gift->refresh()->status);
        self::assertSame([], $this->tierNotices());
    }

    public function testTheWalletScreenShowsTheTierAndWhatIsLeftToTheNext(): void
    {
        $this->send($this->tap(MainMenu::WALLET));
        self::assertStringNotContainsString('🏅', $this->said()[0], 'no tier, no line');

        $this->loyaltyTier('نقره‌ای', '100000', 3, 1);
        $this->loyaltyTier('طلایی', '300000', 5, 2);
        $this->send($this->tap(MainMenu::WALLET));
        self::assertStringContainsString(self::text(BotText::LoyaltyNoTier, ['to_next_tier' => Money::format('100000'), 'next_tier' => 'نقره‌ای']), $this->said()[0], 'what is left to the first');

        $this->buy($this->ali, $this->plan, $this->server);
        $this->send($this->tap(MainMenu::WALLET));
        self::assertStringContainsString(self::text(BotText::LoyaltyTierLine, [
            'loyalty_tier' => 'نقره‌ای',
            'tier_climb' => self::text(BotText::LoyaltyTierClimb, ['to_next_tier' => Money::format('180000'), 'next_tier' => 'طلایی']),
        ]), $this->said()[0]);

        $this->buy($this->ali, $this->plan, $this->server);
        $this->buy($this->ali, $this->plan, $this->server);
        $this->send($this->tap(MainMenu::WALLET));
        self::assertStringContainsString(self::text(BotText::LoyaltyTierLine, ['loyalty_tier' => 'طلایی', 'tier_climb' => '']), $this->said()[0], 'the top tier: nothing left to climb');
    }

    public function testAnAgentsShopHasTiersOfItsOwn(): void
    {
        $this->loyaltyTier('طلایی', '0', 0, 0);
        $bot = $this->agentBot();

        CurrentBot::run($bot, function (): void {
            $reza = $this->customer(['telegram_id' => 3003]);
            self::assertNull($this->tierOf($reza), "the main shop's tiers are not the agent's");
            $this->loyaltyTier('مشتری ویژه', '0', 0, 0);
            self::assertSame('مشتری ویژه', $this->tierOf($reza)?->name);
        });
    }

    /**
     * A purchase of the plan paid and not delivered yet: its payment taken by card, the order paid.
     *
     * @param array<string, mixed> $overrides
     */
    private function paidOrder(array $overrides = []): Order
    {
        $order = $this->purchaseOrder($this->ali, $this->plan, $this->server, $overrides + ['status' => OrderStatus::Paid]);
        $this->cardPayment($order, $this->cardMethod(), ['status' => PaymentStatus::Paid, 'paid_at' => now()]);

        return $order->refresh();
    }

    /**
     * The customer's tier, read afresh.
     *
     * @phpstan-impure
     */
    private function tierOf(?User $user): ?LoyaltyTier
    {
        return $this->loyalty()->tierOf($user);
    }

    /**
     * @return list<string> The tier-up notices kept for Ali, oldest first.
     * @phpstan-impure
     */
    private function tierNotices(): array
    {
        return Notification::query()->where('user_id', $this->ali->id)->where('type', NoticeType::LoyaltyTierUp->value)->orderBy('id')->pluck('text')->all();
    }

    private function loyalty(): Loyalty
    {
        return $this->service(Loyalty::class);
    }
}
