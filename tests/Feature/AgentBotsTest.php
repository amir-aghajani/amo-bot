<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Agency\Enums\TrafficTransactionType;
use App\Modules\Agency\Exceptions\TrafficShortException;
use App\Modules\Agency\Models\TrafficTransaction;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\Enums\ConnectionFailure;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Telegram\Handlers\AgencyHandler;
use App\Modules\Telegram\Handlers\PurchaseHandler;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\User;
use App\Support\Traffic;
use Tests\BotTestCase;
use Tests\Fakes\FakeProvider;
use Tests\Support\FakeTelegram;

/**
 * An agent's bot is a shop of its own run by this installation: its customers, plans, payment methods and texts, the
 * shop's servers; it speaks with its own token, and what it sells — a purchase or a renewal — is drawn from the agent's
 * traffic: a plan the traffic cannot cover is not offered, a delivery it cannot cover (or an unlimited plan, which no
 * pool pays for) fails and the agent hears it in the main bot, a delivery that failed gives its traffic back. The agent
 * is its admin; it has no agency of its own. (The scheduler going round the shops is SchedulerShopsTest's.)
 */
final class AgentBotsTest extends BotTestCase
{
    private Server $server;
    private Bot $bot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakePanel();
        $this->withoutQr();
        $this->server = $this->fakeServer();
        $this->inbound($this->server, '1');
        $this->bot = $this->agentBot(traffic: 100);
        CurrentBot::run($this->bot, fn() => $this->withoutQr());
    }

    public function testThePersonWhoTalksToTwoBotsIsACustomerOfEachAndTheAgentIsTheAdminOfTheirs(): void
    {
        $this->send($this->message('/start'));
        self::assertSame(FakeTelegram::TOKEN, $this->telegram()->tokenOf(0));

        $this->sendTo($this->bot, $this->message('/start'));
        self::assertSame(FakeTelegram::AGENT_TOKEN, $this->telegram()->tokenOf(0), 'answered by the bot it was sent to');

        $customers = User::query()->withoutGlobalScope(CurrentBot::SCOPE)->where('telegram_id', self::CHAT)->orderBy('bot_id')->get();
        self::assertSame([Bot::MAIN, $this->bot->id], $customers->pluck('bot_id')->all(), 'two customers, one person');
        self::assertSame([UserRole::Customer, UserRole::Customer], $customers->pluck('role')->all());

        $this->sendTo($this->bot, $this->message('/start', chat: self::AGENT_TELEGRAM_ID));
        $owner = CurrentBot::run($this->bot, static fn(): User => User::query()->where('telegram_id', self::AGENT_TELEGRAM_ID)->sole());
        self::assertSame(UserRole::Admin, $owner->role, 'the agent runs their own bot');
    }

    public function testAnAgentsBotSellsItsOwnPlansAndEachSaleDrawsItsTraffic(): void
    {
        $mainPlan = $this->plan(['name' => 'پلن فروشگاه اصلی'], $this->server);
        $plan = CurrentBot::run($this->bot, fn(): Plan => $this->plan(['name' => 'پلن رضا', 'traffic_gb' => 30, 'price' => '90000'], $this->server));
        CurrentBot::run($this->bot, fn(): User => $this->wallet($this->customer(), '100000'));
        $wallet = CurrentBot::run($this->bot, fn() => $this->walletMethod());

        $this->sendTo($this->bot, $this->tap(MainMenu::PLANS));
        self::assertContains(PurchaseHandler::planCallback($plan->id), $this->callbacks(0));
        self::assertNotContains(PurchaseHandler::planCallback($mainPlan->id), $this->callbacks(0), "the main bot's plans are not its");

        $this->sendTo($this->bot, $this->tap(PurchaseHandler::serverCallback($plan->id, $this->server->id)));
        $this->sendTo($this->bot, $this->tap(CallbackData::build(PurchaseHandler::serverCallback($plan->id, $this->server->id), $wallet->id)));

        $order = CurrentBot::run($this->bot, static fn(): Order => Order::query()->sole());
        self::assertSame(OrderStatus::Fulfilled, $order->status);
        self::assertSame(Traffic::bytesOfGb(70), $this->bot->trafficBalance(), 'its 30 GB out of the 100');
        $line = TrafficTransaction::query()->where('order_id', $order->id)->sole();
        self::assertSame([TrafficTransactionType::Sale, -Traffic::bytesOfGb(30), $order->id], [$line->type, $line->bytes, $line->order_id]);
        self::assertSame([FakeTelegram::AGENT_TOKEN], array_values(array_unique(array_map($this->telegram()->tokenOf(...), array_keys($this->telegram()->history)))), "the checkout and the service, all by the agent's bot");
        self::assertStringContainsString(' | @agent_shop_bot', FakeProvider::$created[0]['spec']->comment, 'the panel says whose customer it is');
    }

    public function testARenewalInAnAgentsBotDrawsItsPlansTraffic(): void
    {
        $subscription = $this->agentsService();

        $order = CurrentBot::run($this->bot, fn(): Order => $this->renew($subscription));

        self::assertSame(['ali_1'], FakeProvider::updatedNames(), 'renewed on the panel');
        self::assertSame(Traffic::bytesOfGb(70), $this->bot->trafficBalance(), "the plan's 30 GB out of the 100");
        $line = TrafficTransaction::query()->where('order_id', $order->id)->sole();
        self::assertSame([TrafficTransactionType::Renewal, -Traffic::bytesOfGb(30)], [$line->type, $line->bytes]);
    }

    public function testARenewalTheTrafficCannotCoverFailsAndTheServiceStaysAsItWas(): void
    {
        $subscription = $this->agentsService();
        $this->traffic($this->bot, 10);
        $this->telegram()->reset();

        $order = CurrentBot::run($this->bot, function () use ($subscription): Order {
            $plan = $subscription->plan ?? self::fail('no plan');
            $order = $this->service(OrderService::class)->createRenewal($this->wallet($subscription->user, $plan->price), $subscription, $plan);
            $this->service(PaymentService::class)->createForOrder($order, $this->walletMethod());

            return $order->refresh();
        });

        self::assertSame(OrderStatus::Failed, $order->status);
        self::assertSame(sprintf(TrafficShortException::SHORT, Messages::bytes(Traffic::bytesOfGb(30)), Messages::bytes(Traffic::bytesOfGb(10))), $order->notes);
        self::assertSame([], FakeProvider::updatedNames(), 'the panel was not asked');
        self::assertSame([Traffic::bytesOfGb(10), 0], [$this->bot->trafficBalance(), TrafficTransaction::query()->where('order_id', $order->id)->count()]);
        self::assertSame([self::text(BotText::AgencyTrafficShort, ['order' => (string) $order->id, 'traffic' => Messages::bytes(Traffic::bytesOfGb(10))])], $this->telegram()->sentTo(self::AGENT_TELEGRAM_ID));
    }

    public function testAnUnlimitedPlanIsNeverDeliveredFromTheAgentsTraffic(): void
    {
        $plan = CurrentBot::run($this->bot, fn(): Plan => $this->plan(['traffic_gb' => 0], $this->server));
        $order = CurrentBot::run($this->bot, fn(): Order => $this->service(OrderService::class)->openPurchase($this->customer(), $plan, $this->server));

        CurrentBot::run($this->bot, function () use ($order): void {
            $this->service(OrderService::class)->markPaid($order);
            $this->service(OrderService::class)->deliver($order);
        });

        self::assertSame([OrderStatus::Failed, TrafficShortException::UNLIMITED], [$order->refresh()->status, $order->notes]);
        self::assertSame([], FakeProvider::$created, 'the panel was not asked');
        self::assertSame(Traffic::bytesOfGb(100), $this->bot->trafficBalance());
    }

    public function testAPlanTheTrafficCannotCoverIsNotOffered(): void
    {
        $this->traffic($this->bot, 10);
        $plan = CurrentBot::run($this->bot, fn(): Plan => $this->plan(['traffic_gb' => 30], $this->server));
        CurrentBot::run($this->bot, fn(): User => $this->customer());

        $this->sendTo($this->bot, $this->tap(MainMenu::PLANS));
        self::assertSame(self::text(BotText::PlansEmpty), $this->params(0)['text']);

        $this->sendTo($this->bot, $this->tap("plan:{$plan->id}"));
        self::assertStringContainsString(self::text(BotText::PlanNoServer), $this->params(0)['text'], 'a button from before: not for sale now');

        $this->sendTo($this->bot, $this->tap("plan:{$plan->id}:srv:{$this->server->id}"));
        self::assertSame(0, CurrentBot::run($this->bot, static fn(): int => Order::query()->count()), 'nothing to pay for');
    }

    public function testADeliveryTheTrafficCannotCoverFailsTheAgentIsToldAndTheRetryDrawsOnce(): void
    {
        $plan = CurrentBot::run($this->bot, fn(): Plan => $this->plan(['traffic_gb' => 30], $this->server));
        $customer = CurrentBot::run($this->bot, fn(): User => $this->customer());
        $order = CurrentBot::run($this->bot, fn(): Order => $this->service(OrderService::class)->openPurchase($customer, $plan, $this->server));
        $this->traffic($this->bot, 10);
        $this->telegram()->reset();

        CurrentBot::run($this->bot, function () use ($order): void {
            $this->service(OrderService::class)->markPaid($order);
            $this->service(OrderService::class)->deliver($order);
        });

        self::assertSame(OrderStatus::Failed, $order->refresh()->status);
        self::assertSame(sprintf(TrafficShortException::SHORT, Messages::bytes(Traffic::bytesOfGb(30)), Messages::bytes(Traffic::bytesOfGb(10))), $order->notes);
        self::assertSame([], FakeProvider::$created, 'the panel was not asked');
        self::assertSame([self::text(BotText::AgencyTrafficShort, ['order' => (string) $order->id, 'traffic' => Messages::bytes(Traffic::bytesOfGb(10))])], $this->telegram()->sentTo(self::AGENT_TELEGRAM_ID));
        self::assertSame(FakeTelegram::TOKEN, $this->telegram()->tokenOf(0), 'the agent hears it in the main bot');
        self::assertSame([AgencyHandler::TRAFFIC], $this->callbacks(0), '«خرید حجم» under it');

        // More traffic, and the retry (from the agent's panel: their shop) delivers — drawing the 30 GB once.
        $this->traffic($this->bot, 50);
        CurrentBot::run($this->bot, fn() => $this->service(OrderService::class)->deliver(Order::query()->findOrFail($order->id)));
        self::assertSame(OrderStatus::Fulfilled, $order->refresh()->status, (string) $order->notes);
        self::assertSame(Traffic::bytesOfGb(20), $this->bot->trafficBalance());
        self::assertSame(1, TrafficTransaction::query()->where('order_id', $order->id)->count());
    }

    public function testADeliveryThePanelFailsGivesItsTrafficBack(): void
    {
        $plan = CurrentBot::run($this->bot, fn(): Plan => $this->plan(['traffic_gb' => 30], $this->server));
        $customer = CurrentBot::run($this->bot, fn(): User => $this->customer());
        $order = CurrentBot::run($this->bot, fn(): Order => $this->service(OrderService::class)->openPurchase($customer, $plan, $this->server));
        FakeProvider::$failing[$this->server->id]['createClient'] = ConnectionFailure::Refused;

        CurrentBot::run($this->bot, function () use ($order): void {
            $this->service(OrderService::class)->markPaid($order);
            $this->service(OrderService::class)->deliver($order);
        });

        self::assertSame(OrderStatus::Failed, $order->refresh()->status);
        self::assertSame(Traffic::bytesOfGb(100), $this->bot->trafficBalance(), 'back where it was');
        self::assertSame([TrafficTransactionType::Sale, TrafficTransactionType::Refund], TrafficTransaction::query()->where('order_id', $order->id)->orderBy('id')->get()->pluck('type')->all());
    }

    public function testAnAgentsBotHasNoAgencyOfItsOwn(): void
    {
        $this->agencyProgram();
        $customer = CurrentBot::run($this->bot, fn(): User => $this->customer());

        $markup = CurrentBot::run($this->bot, fn(): array => $this->service(MainMenu::class)->markup($customer));
        self::assertStringNotContainsString(Messages::MENU_AGENCY, (string) json_encode($markup, JSON_UNESCAPED_UNICODE));

        $this->sendTo($this->bot, $this->tap(MainMenu::AGENCY));
        self::assertSame([self::text(BotText::AgencyOff)], $this->said());
        $this->sendTo($this->bot, $this->tap('agency:apply'));
        self::assertSame([self::text(BotText::AgencyOff)], $this->said());
    }

    /** A customer of the agent's bot with a running service (ali_1, on the panel) of its 30 GB plan. */
    private function agentsService(): Subscription
    {
        return CurrentBot::run($this->bot, function (): Subscription {
            $plan = $this->plan(['traffic_gb' => 30, 'price' => '90000'], $this->server);
            $subscription = $this->subscription($this->customer(), $plan, $this->server, 'ali_1');
            FakeProvider::mirror($subscription);

            return $subscription;
        });
    }
}
