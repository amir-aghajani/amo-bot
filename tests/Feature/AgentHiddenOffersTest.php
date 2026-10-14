<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Catalog\Exceptions\NoServerAvailableException;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Services\PlanService;
use App\Modules\Catalog\Services\ServerSelector;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Providers\Models\Server;
use App\Modules\Rewards\DTO\Reward;
use App\Modules\Rewards\Exceptions\RewardUnavailableException;
use App\Modules\Rewards\Services\Rewards;
use App\Modules\Store\Services\StoreCatalog;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\CustomerRenewal;
use App\Modules\Subscriptions\Services\ProvisioningService;
use App\Modules\Subscriptions\Services\TestAccountDirectory;
use App\Modules\Subscriptions\Services\TestServers;
use App\Modules\Users\Models\User;
use Tests\Fakes\FakeProvider;
use Tests\HttpTestCase;

/**
 * What the owner keeps from an agent's shop («سرورها و پلن‌های پنهان»): a hidden server sells none of its plans, gives no
 * free test, takes no service its staff move or build there and is none of its form's or its discount codes' choices —
 * while the services it already has there run on and renew, and the owner's own moves (a server's, or one service's)
 * still carry its services there; a hidden plan is not sold nor given as a prize, the agent sees it marked and cannot
 * show it again. The main shop and other agents' shops are untouched.
 */
final class AgentHiddenOffersTest extends HttpTestCase
{
    private Server $germany;
    private Server $holland;
    private Bot $bot;
    private Plan $both;
    private Plan $dutch;
    private Plan $main;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->telegram();
        $this->fakePanel();
        $this->germany = $this->fakeServer('آلمان', ['test_enabled' => true]);
        $this->holland = $this->fakeServer('هلند', ['test_enabled' => true]);
        $this->inbound($this->germany, '1', ['is_selectable' => true]);
        $this->inbound($this->holland, '2', ['is_selectable' => true]);
        $this->bot = $this->agentBot(traffic: 100);
        $this->agent = $this->bot->agent ?? self::fail('no agent');
        $this->main = $this->plan(['name' => 'پلن اصلی'], $this->holland);
        [$this->both, $this->dutch] = CurrentBot::run($this->bot, fn(): array => [
            $this->plan(['name' => 'دو سرور', 'traffic_gb' => 20], [$this->germany, $this->holland]),
            $this->plan(['name' => 'فقط هلند', 'traffic_gb' => 20], $this->holland),
        ]);
    }

    public function testTheOwnerKeepsServersAndPlansFromAnAgentsShop(): void
    {
        $this->loginAsAdmin();
        $url = "/api/admin/agency/agents/{$this->agent->id}/hidden";

        $read = $this->decode($this->get($url))['hidden'];
        self::assertSame([['آلمان', false], ['هلند', false]], array_map(static fn(array $server): array => [$server['name'], $server['hidden']], $read['servers']), 'every server of the installation');
        self::assertSame([['دو سرور', false], ['فقط هلند', false]], array_map(static fn(array $plan): array => [$plan['name'], $plan['hidden']], $read['plans']), "the agent's plans, not the main shop's");

        $saved = $this->putJson($url, ['server_ids' => [$this->holland->id], 'plan_ids' => [$this->both->id]]);
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        $body = $this->decode($saved);
        self::assertSame([false, true], array_column($body['hidden']['servers'], 'hidden'));
        self::assertSame([true, false], array_column($body['hidden']['plans'], 'hidden'));
        self::assertSame(['servers' => 1, 'plans' => 1], $body['agent']['hidden'], 'counted on their row');
        self::assertSame(['servers' => 1, 'plans' => 1], $this->decode($this->get('/api/admin/agency/agents'))['agents'][0]['hidden'], 'and on the list');
        self::assertSame([$this->holland->id], (Bot::query()->findOrFail($this->bot->id))->hiddenServerIds());
        self::assertTrue((bool) Plan::query()->withoutGlobalScope(CurrentBot::SCOPE)->findOrFail($this->both->id)->hidden_by_owner);

        $refused = $this->putJson($url, ['server_ids' => [999], 'plan_ids' => [$this->main->id]]);
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(['server_ids', 'plan_ids'], array_keys($this->decode($refused)['errors']), 'a server that is not there; a plan of the main shop');

        $cleared = $this->decode($this->putJson($url, ['server_ids' => [], 'plan_ids' => []]));
        self::assertSame(['servers' => 0, 'plans' => 0], $cleared['agent']['hidden'], 'none kept now');
        self::assertFalse((bool) Plan::query()->withoutGlobalScope(CurrentBot::SCOPE)->findOrFail($this->both->id)->hidden_by_owner);
    }

    /** Both lists are sent every time: one left out is refused, never read as none — and neither takes more than 1,000. */
    public function testEachListIsSentEveryTimeAndBounded(): void
    {
        $this->loginAsAdmin();
        $url = "/api/admin/agency/agents/{$this->agent->id}/hidden";
        $this->putJson($url, ['server_ids' => [$this->holland->id], 'plan_ids' => [$this->dutch->id]]);

        $notAList = 'لیست را از گزینه‌ها انتخاب کنید.';
        $this->assertRefusedUnder('server_ids', $notAList, $this->unchecked()->putJson($url, ['plan_ids' => []]), message: 'the servers left out');
        $this->assertRefusedUnder('plan_ids', $notAList, $this->unchecked()->putJson($url, ['server_ids' => []]), message: 'the plans left out');
        $tooMany = 'لیست شناسه‌ها بلندتر از حد است؛ حداکثر ۱٬۰۰۰ شناسه.';
        $this->assertRefusedUnder('server_ids', $tooMany, $this->unchecked()->putJson($url, ['server_ids' => range(1, 1001), 'plan_ids' => []]));
        $this->assertRefusedUnder('plan_ids', $tooMany, $this->unchecked()->putJson($url, ['server_ids' => [], 'plan_ids' => range(1, 1001)]));

        self::assertSame([$this->holland->id], Bot::query()->findOrFail($this->bot->id)->hiddenServerIds(), 'nothing changed');
        self::assertTrue((bool) Plan::query()->withoutGlobalScope(CurrentBot::SCOPE)->findOrFail($this->dutch->id)->hidden_by_owner);
    }

    public function testAHiddenServerSellsNothingNewInTheAgentsShopWhileItsServicesRunOn(): void
    {
        $customer = CurrentBot::run($this->bot, fn(): User => $this->customer(['telegram_id' => 7070]));
        $old = CurrentBot::run($this->bot, fn(): Subscription => $this->subscription($customer, $this->dutch, $this->holland, 'ali_1'));
        $moving = CurrentBot::run($this->bot, fn(): Subscription => $this->subscription($customer, $this->both, $this->germany, 'ali_2'));
        // Paid for before the server was hidden.
        $paid = CurrentBot::run($this->bot, function () use ($customer): Order {
            $order = $this->service(OrderService::class)->openPurchase($customer, $this->dutch, $this->holland);
            $this->service(OrderService::class)->markPaid($order);

            return $order;
        });
        $this->bot->hideServers([$this->holland->id]);

        CurrentBot::run($this->bot, function () use ($old, $paid): void {
            $selector = $this->service(ServerSelector::class);
            self::assertSame(['آلمان'], array_map(static fn(array $choice): string => $choice['server']->name, $selector->choices($this->both)), 'a plan on both sells on the other');
            self::assertSame([], $selector->choices($this->dutch));
            self::assertSame([$this->both->id], array_keys($selector->offers(Plan::active()->get())), 'a plan on the hidden server alone is not offered');
            $judged = $this->service(PlanService::class)->all();
            self::assertSame([null, 'هیچ‌کدام از سرورهای این پلن الان قابل فروش نیست؛ دلیل هر کدام کنار نام همان سرور آمده است.'], array_column($judged, 'unsellable_reason'));
            self::assertSame(ServerSelector::HIDDEN_SERVER, $judged[1]['servers'][0]['unsellable_reason'], 'the entry says why');
            self::assertSame(['آلمان'], array_column($this->service(PlanService::class)->options()['servers'], 'name'), "the form's (and the discount codes' and the move's) choices");
            self::assertSame(['آلمان'], array_map(static fn(array $choice): string => $choice['server']->name, $this->service(TestServers::class)->choices()), 'no test there');
            self::assertSame(['آلمان'], array_column($this->service(TestAccountDirectory::class)->servers(), 'name'));
            self::assertSame(['آلمان'], array_column($this->service(StoreCatalog::class)->status(), 'name'), "the website's locations");

            try {
                $selector->resolve($this->dutch, $this->holland->id);
                self::fail('ordered on a hidden server');
            } catch (NoServerAvailableException) {
            }
            self::assertNotNull($this->service(CustomerRenewal::class)->preview($old), 'a service already there renews');
            self::assertTrue($this->service(OrderService::class)->deliver($paid));
            self::assertSame([OrderStatus::Fulfilled, $this->holland->id], [$paid->refresh()->status, $paid->subscription?->server_id], 'paid before: delivered there');
        });

        // The main shop sells on it as before.
        self::assertSame([$this->main->id], array_keys($this->service(ServerSelector::class)->offers(Plan::active()->get())));

        $this->loginAsAgent($this->bot);
        $moved = $this->postJson("/api/agent/subscriptions/{$moving->id}/move", ['server_id' => $this->holland->id]);
        self::assertSame(422, $moved->getStatusCode());
        self::assertArrayHasKey('server_id', $this->decode($moved)['errors'], 'no service moved there by hand');
        $code = $this->postJson('/api/agent/discount-codes', ['code' => 'HOLLAND', 'type' => 'percent', 'value' => '10', 'server_ids' => [$this->holland->id], 'first_purchase_only' => false, 'enabled' => true]);
        self::assertSame(422, $code->getStatusCode());
        self::assertArrayHasKey('server_ids', $this->decode($code)['errors'], 'nor a discount code of it');

        // The owner's move of a server's services («انتقال سرویس‌ها», through ProvisioningService::move()) carries the
        // agent's too, onto a server kept from their shop or not.
        CurrentBot::run($this->bot, fn() => $this->service(ProvisioningService::class)->move(Subscription::query()->findOrFail($moving->id), $this->holland, true));
        self::assertSame($this->holland->id, $moving->refresh()->server_id);
    }

    public function testTheOwnersOwnMoveOfOneServiceCarriesItOntoAServerKeptFromTheShop(): void
    {
        $customer = CurrentBot::run($this->bot, fn(): User => $this->customer(['telegram_id' => 7070]));
        $moving = CurrentBot::run($this->bot, fn(): Subscription => $this->subscription($customer, $this->both, $this->germany, 'ali_2'));
        FakeProvider::mirror($moving);
        $this->bot->hideServers([$this->holland->id]);

        // The agent's shop opened in the owner's panel: their call, as their move of a server's services is.
        $this->loginAsAdmin();
        $this->openShop($this->bot);
        $moved = $this->postJson("/api/admin/subscriptions/{$moving->id}/move", ['server_id' => $this->holland->id]);

        self::assertSame(200, $moved->getStatusCode(), (string) $moved->getBody());
        self::assertSame($this->holland->id, $moving->refresh()->server_id);
    }

    public function testAHiddenPlanIsNoPrizeEither(): void
    {
        Plan::query()->withoutGlobalScope(CurrentBot::SCOPE)->whereKey($this->both->id)->update(['hidden_by_owner' => true]);

        CurrentBot::run($this->bot, function (): void {
            $customer = $this->customer(['telegram_id' => 7070]);
            $rewards = $this->service(Rewards::class);
            try {
                $rewards->give($customer, Reward::plan(Plan::query()->findOrFail($this->both->id)), 'جایزه گردونه');
                self::fail('a hidden plan was given');
            } catch (RewardUnavailableException $e) {
                self::assertSame(RewardUnavailableException::NO_SERVER, $e->getMessage());
            }
            self::assertSame(0, Order::query()->count(), 'nothing ordered');

            $given = $rewards->give($customer, Reward::plan($this->dutch), 'جایزه گردونه');
            self::assertSame(OrderStatus::Fulfilled, $given->order?->status, 'a plan of theirs on sale is given');
        });
    }

    public function testTheAgentSeesAHiddenPlanAndCannotShowItAgain(): void
    {
        $this->bot->hideServers([$this->holland->id]);
        Plan::query()->withoutGlobalScope(CurrentBot::SCOPE)->whereKey($this->both->id)->update(['hidden_by_owner' => true]);
        $this->loginAsAgent($this->bot);

        $plans = $this->decode($this->get('/api/agent/plans'))['plans'];
        self::assertSame([[true, ServerSelector::HIDDEN_PLAN], [false, 'هیچ‌کدام از سرورهای این پلن الان قابل فروش نیست؛ دلیل هر کدام کنار نام همان سرور آمده است.']], array_map(static fn(array $plan): array => [$plan['hidden_by_owner'], $plan['unsellable_reason']], $plans));

        $input = ['name' => 'دو سرور', 'price' => '150000', 'duration_days' => 30, 'traffic_gb' => 20, 'ip_limit' => 0, 'is_active' => true];
        $kept = $this->putJson("/api/agent/plans/{$this->both->id}", $input + ['servers' => [['server_id' => $this->germany->id, 'all_inbounds' => true, 'inbound_ids' => []], ['server_id' => $this->holland->id, 'all_inbounds' => true, 'inbound_ids' => []]]]);
        self::assertSame(200, $kept->getStatusCode(), (string) $kept->getBody());
        self::assertTrue($this->decode($kept)['plan']['hidden_by_owner'], 'saved and still hidden; the server it was on stays on it');

        $added = $this->postJson('/api/agent/plans', $input + ['name' => 'تازه', 'servers' => [['server_id' => $this->holland->id, 'all_inbounds' => true, 'inbound_ids' => []]]]);
        self::assertSame(422, $added->getStatusCode());
        self::assertSame(['«هلند»: ' . ServerSelector::HIDDEN_SERVER], $this->decode($added)['errors']['servers'], 'the one refusal of a server kept from the shop');

        self::assertTrue($this->decode($this->patchJson("/api/agent/plans/{$this->both->id}", ['is_active' => true]))['plan']['hidden_by_owner'], 'its own switch shows nothing');
        $copy = $this->decode($this->postJson("/api/agent/plans/{$this->both->id}/duplicate"))['plan'];
        self::assertTrue($copy['hidden_by_owner'], 'nor does a copy');
        self::assertSame([], CurrentBot::run($this->bot, static fn(): array => Plan::active()->where('name', 'like', 'دو سرور%')->pluck('id')->all()), 'never on sale');
        self::assertSame(['آلمان'], array_column($this->decode($this->get('/api/agent/plans/options'))['servers'], 'name'));
    }
}
