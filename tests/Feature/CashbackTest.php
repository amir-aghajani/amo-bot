<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Notifications\Enums\NoticeType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Services\PaymentActions;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\Models\Server;
use App\Modules\Rewards\DTO\Reward;
use App\Modules\Rewards\Models\Cashback as CashbackRow;
use App\Modules\Rewards\Services\Cashback;
use App\Modules\Rewards\Services\Rewards;
use App\Modules\Subscriptions\Enums\ExtraKind;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Models\User;
use App\Modules\Users\Models\WalletTransaction;
use App\Modules\Users\Services\WalletService;
use App\Support\Money;
use Tests\DatabaseTestCase;
use Tests\Fakes\FakeProvider;

/**
 * Cashback: the best matching rule (kind, group, payment method) plus the tier's percent, credited once per order and told;
 * nothing for a free order or while switched off; a refund gives back the amount less the cashback.
 */
final class CashbackTest extends DatabaseTestCase
{
    private Server $server;
    private Plan $plan;
    private User $ali;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutQr();
        $this->telegram();
        $this->server = $this->sellingServer();
        $this->plan = $this->plan(['price' => '120000.00'], $this->server);
        $this->ali = $this->customer(['username' => 'ali']);
    }

    public function testSwitchedOffNoOrderEarnsAny(): void
    {
        $this->cashbackRule(10);
        $this->loyaltyTier('همه', '0', 0, 5);

        $this->buy($this->ali, $this->plan, $this->server);

        self::assertSame(0, CashbackRow::query()->count());
        self::assertSame('0.00', $this->ali->balance());
    }

    public function testTheHighestRuleThatMatchesAndTheTiersPercentComeBackToTheWallet(): void
    {
        $this->cashbackOn();
        $this->cashbackRule(5);
        $this->cashbackRule(10);
        $this->cashbackRule(40, ['enabled' => false]);
        $this->loyaltyTier('همه', '0', 0, 3);

        $order = $this->buy($this->ali, $this->plan, $this->server);

        $cashback = CashbackRow::query()->sole();
        self::assertSame([$order->id, $this->ali->id, 13, '15600.00', null], [$cashback->order_id, $cashback->user_id, $cashback->percent, $cashback->amount, $cashback->reversed_at], '10% the best rule switched on, 3% the tier: 13% of 120,000');
        self::assertSame('15600.00', $this->ali->balance());
        self::assertSame(sprintf(Cashback::LINE, $order->id), WalletTransaction::query()->where('user_id', $this->ali->id)->latest('id')->value('description'));

        $notice = Notification::query()->where('user_id', $this->ali->id)->where('type', NoticeType::CashbackEarned->value)->sole();
        self::assertSame(self::text(BotText::CashbackEarned, ['order' => $order->id, 'cashback_amount' => Money::format('15600'), 'balance' => Money::format('15600')]), $notice->text);
        self::assertSame(['order', $order->id], [$notice->subject_type?->value, $notice->subject_id], 'about its order');
        self::assertContains($notice->text, $this->telegram()->sentTo(self::TELEGRAM_ID));
    }

    public function testAPercentIsHundredAtMostAndRoundedDownToAToman(): void
    {
        $this->cashbackOn();
        $this->cashbackRule(90);
        $this->loyaltyTier('همه', '0', 0, 30);
        $plan = $this->plan(['name' => 'ارزان', 'price' => '999.00'], $this->server);

        $this->buy($this->ali, $plan, $this->server);

        self::assertSame([100, '999.00'], [CashbackRow::query()->value('percent'), CashbackRow::query()->value('amount')]);
    }

    public function testTheAmountIsRoundedDownToAToman(): void
    {
        $this->cashbackOn();
        $this->cashbackRule(7);
        $plan = $this->plan(['name' => 'ارزان', 'price' => '999.00'], $this->server);

        $this->buy($this->ali, $plan, $this->server);

        self::assertSame('69.00', CashbackRow::query()->value('amount'), 'floor(999 × 7 / 100)');
    }

    public function testARuleMatchesByTheKindOfOrderTheCustomersGroupAndTheWayItWasPaid(): void
    {
        $this->cashbackOn();
        $vip = $this->customerGroup('VIP');
        $card = $this->cardMethod();
        $this->cashbackRule(20, ['order_types' => [OrderType::Renewal->value]]);
        $this->cashbackRule(10, ['customer_group_id' => $vip->id]);
        $this->cashbackRule(30, ['payment_method_id' => $card->id]);
        $this->cashbackRule(15, ['order_types' => [OrderType::ExtraTime->value]]);

        $order = $this->buy($this->ali, $this->plan, $this->server);
        self::assertSame(0, $this->percentOf($order), 'a purchase, from the wallet, outside VIP: no rule is theirs');

        $vip->users()->attach($this->ali->id);
        self::assertSame(10, $this->percentOf($this->buy($this->ali, $this->plan, $this->server)), 'VIP now');

        $byCard = $this->purchaseOrder($this->ali, $this->plan, $this->server);
        $this->paidByCard($byCard, $card);
        self::assertSame(30, $this->percentOf($byCard->refresh()), 'paid by that card');

        $service = $this->subscriptionOf($order);
        self::assertSame(20, $this->percentOf($this->renew($service)), "a renewal: its rule's 20, above VIP's 10");

        $this->extrasOnSale();
        self::assertSame(15, $this->percentOf($this->paidExtra($service, ExtraKind::Time, 7, '7000')), 'extra time, the rule that names it');
        self::assertSame('1050.00', CashbackRow::query()->latest('id')->value('amount'));
    }

    public function testATopUpOrAnAgentsTrafficEarnsNone(): void
    {
        $this->cashbackOn();
        $this->cashbackRule(10, ['order_types' => [OrderType::Purchase->value, OrderType::Renewal->value, OrderType::ExtraTraffic->value, OrderType::ExtraTime->value]]);
        $topUp = $this->topUpOrder($this->ali, '50000');

        $this->paidByCard($topUp);

        self::assertSame(OrderStatus::Fulfilled, $topUp->refresh()->status);
        self::assertSame(0, CashbackRow::query()->count());
    }

    public function testAnOrderEarnsItOnceWhateverAsksAgain(): void
    {
        $this->cashbackOn();
        $this->cashbackRule(10);
        $order = $this->buy($this->ali, $this->plan, $this->server);

        self::assertNull($this->service(Cashback::class)->earn($order), 'asked again — another process, a retry: the row is there');

        self::assertSame(1, CashbackRow::query()->count());
        self::assertSame('12000.00', $this->ali->balance(), 'credited once');
    }

    public function testADeliveryRetriedLaterEarnsItThen(): void
    {
        $this->cashbackOn();
        $this->cashbackRule(10);
        FakeProvider::$unreachable = true;
        $this->service(WalletService::class)->credit($this->ali, '120000', 'test');
        $order = $this->service(OrderService::class)->openPurchase($this->ali, $this->plan, $this->server);
        $this->service(PaymentService::class)->createForOrder($order, $this->walletMethod());
        self::assertSame(OrderStatus::Failed, $order->refresh()->status);
        self::assertSame(0, CashbackRow::query()->count(), 'nothing for a delivery that failed');

        FakeProvider::$unreachable = false;
        $this->service(OrderService::class)->deliver($order);

        self::assertSame(OrderStatus::Fulfilled, $order->refresh()->status);
        self::assertSame('12000.00', CashbackRow::query()->sole()->amount);
    }

    public function testAnOrderAtNoPriceEarnsNothing(): void
    {
        $this->cashbackOn();
        $this->cashbackRule(50);
        $this->loyaltyTier('همه', '0', 0, 50);

        $given = $this->service(Rewards::class)->give($this->ali, Reward::plan($this->plan), 'جایزه');

        self::assertSame(OrderStatus::Fulfilled, $given->order?->status);
        self::assertSame(0, CashbackRow::query()->count());
    }

    public function testARefundGivesBackTheAmountLessTheCashback(): void
    {
        $this->cashbackOn();
        $this->cashbackRule(10);
        $order = $this->buy($this->ali, $this->plan, $this->server);
        self::assertSame('12000.00', $this->ali->balance());
        $payment = $order->payments()->firstOrFail();

        $this->service(PaymentActions::class)->refund($payment, $this->panelActor(), null);

        self::assertSame(OrderStatus::Refunded, $order->refresh()->status);
        self::assertSame('120000.00', $this->ali->balance(), '12,000 kept + 108,000 back: what was paid, the cashback taken out of it');
        self::assertNotNull(CashbackRow::query()->sole()->reversed_at);
        self::assertSame(WalletService::describeRefund($payment, null, lessCashback: true), WalletTransaction::query()->where('user_id', $this->ali->id)->latest('id')->value('description'));
        self::assertSame('108000.00', WalletTransaction::query()->where('user_id', $this->ali->id)->latest('id')->value('amount'));
        $told = Notification::query()->where('user_id', $this->ali->id)->where('type', NoticeType::PaymentRefunded->value)->sole()->text;
        self::assertStringContainsString(self::text(BotText::CashbackTakenBack, ['cashback_amount' => Money::format('12000')]), $told);
    }

    public function testARefundOfAnOrderWithoutCashbackGivesItAllBack(): void
    {
        $order = $this->buy($this->ali, $this->plan, $this->server);
        $payment = $order->payments()->firstOrFail();

        $this->service(PaymentActions::class)->refund($payment, $this->panelActor(), null);

        self::assertSame('120000.00', $this->ali->balance());
        self::assertSame(WalletService::describeRefund($payment, null), WalletTransaction::query()->where('user_id', $this->ali->id)->latest('id')->value('description'));
        self::assertStringNotContainsString('کش‌بک', Notification::query()->where('type', NoticeType::PaymentRefunded->value)->sole()->text);
    }

    public function testACashbackAfterTheOrdersRefundEarnsNothing(): void
    {
        $this->cashbackOn();
        $this->cashbackRule(10);
        $order = $this->purchaseOrder($this->ali, $this->plan, $this->server, ['status' => OrderStatus::Refunded, 'fulfilled_at' => now()]);
        $this->cardPayment($order, $this->cardMethod(), ['status' => PaymentStatus::Refunded, 'paid_at' => now()]);

        self::assertNull($this->service(Cashback::class)->earn($order->refresh()), 'given back before it was credited: nothing to earn');
        self::assertSame(0, CashbackRow::query()->count());
    }

    public function testAnAgentsShopEarnsByItsOwnRules(): void
    {
        $this->cashbackOn();
        $this->cashbackRule(10);
        $bot = $this->agentBot(traffic: 500);

        CurrentBot::run($bot, function (): void {
            $reza = $this->customer(['telegram_id' => 3003]);
            $plan = $this->plan(['price' => '100000.00', 'traffic_gb' => 10], $this->server);
            $this->buy($reza, $plan, $this->server);
            self::assertSame(0, CashbackRow::query()->count(), "the main shop's rules and switch are not the agent's");

            $this->cashbackOn();
            $this->cashbackRule(4);
            $this->buy($reza, $plan, $this->server);
            self::assertSame('4000.00', CashbackRow::query()->sole()->amount);
        });
    }

    private function percentOf(Order $order): int
    {
        return (int) (CashbackRow::query()->where('order_id', $order->id)->value('percent') ?? 0);
    }

    private function subscriptionOf(Order $order): Subscription
    {
        return $order->refresh()->subscription ?? self::fail('the purchase delivered no service');
    }
}
