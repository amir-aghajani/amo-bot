<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Agency\Services\AgencyActions;
use App\Modules\Agency\Services\AgentBots;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Auth\NamedShop;
use App\Modules\Auth\Services\SignInThrottle;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Payments\Models\Payment;
use App\Modules\Providers\Models\Server;
use App\Modules\Users\Models\User;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\HttpTestCase;

/**
 * An agent's panel (/api/agent): they sign in with the one-time link their account in the main bot gives them — once,
 * for AgentBots::LOGIN_MINUTES, the newest link only; where another agent is signed in, only when they say so — and work
 * in their bot's shop and nothing else — its customers, plans (with traffic, on the shop's servers), orders, settings;
 * a request naming another shop is refused —; their account shows their bot and its traffic, and they sign every other
 * browser of theirs out. The owner's panel (/api/admin) is another panel with another session: neither opens the other,
 * both stay signed in side by side in one browser, and the owner's requests that name an agent's shop work in it, while
 * the shop's own sections keep working where they belong. An agent whose agency ended is signed out, and their links
 * with it.
 */
final class AgentPanelTest extends HttpTestCase
{
    private Bot $bot;
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->telegram();
        $this->fakePanel();
        $this->server = $this->fakeServer('آلمان');
        $this->inbound($this->server, '1', ['is_selectable' => true]);
        $this->bot = $this->agentBot(traffic: 100);

        // A customer and a plan in each shop.
        $this->customer(['telegram_id' => 501, 'username' => 'main_customer']);
        $this->plan(['name' => 'پلن اصلی'], $this->server);
        CurrentBot::run($this->bot, function (): void {
            $this->customer(['telegram_id' => 502, 'username' => 'agents_customer']);
            $this->plan(['name' => 'پلن نماینده', 'traffic_gb' => 20], $this->server);
        });
    }

    public function testAnAgentSignsInWithTheirLinkAndWorksInTheirShopOnly(): void
    {
        $this->loginAsAgent($this->bot);

        $session = $this->decode($this->get('/api/agent/auth/me'))['session'];
        self::assertSame(['name' => '@agent_shop_bot', 'shop' => ['id' => $this->bot->id, 'name' => 'فروشگاه نماینده', 'username' => 'agent_shop_bot', 'status' => 'active']], $session);

        self::assertSame(['agents_customer'], array_column($this->decode($this->get('/api/agent/users'))['users'], 'username'));
        self::assertSame(['پلن نماینده'], array_column($this->decode($this->get('/api/agent/plans'))['plans'], 'name'));

        $mainCustomer = User::query()->where('telegram_id', 501)->sole();
        self::assertSame(404, $this->patchJson("/api/agent/users/{$mainCustomer->id}", ['status' => 'banned'])->getStatusCode(), "the main shop's customer is not there for them");
    }

    public function testALoginLinkWorksOnceAndForItsMinutesOnly(): void
    {
        $code = $this->loginCode($this->bot);
        self::assertSame(200, $this->postJson('/api/agent/auth/link', ['code' => $code])->getStatusCode());
        self::assertSame(204, $this->postJson('/api/agent/auth/logout')->getStatusCode());
        $used = $this->postJson('/api/agent/auth/link', ['code' => $code]);
        self::assertSame([401, SignInRefusedException::LINK_REFUSED], [$used->getStatusCode(), $this->decode($used)['message']], 'used once');

        $inTime = $this->loginCode($this->bot);
        Carbon::setTestNow(now()->addMinutes(AgentBots::LOGIN_MINUTES)->subSecond());
        self::assertSame(200, $this->postJson('/api/agent/auth/link', ['code' => $inTime])->getStatusCode(), 'within its minutes');
        $this->postJson('/api/agent/auth/logout');

        $late = $this->loginCode($this->bot);
        Carbon::setTestNow(now()->addMinutes(AgentBots::LOGIN_MINUTES)->addSecond());
        self::assertSame(401, $this->postJson('/api/agent/auth/link', ['code' => $late])->getStatusCode(), 'too late');
        self::assertSame(401, $this->postJson('/api/agent/auth/link', ['code' => 'made-up'])->getStatusCode());
        self::assertSame(401, $this->get('/api/agent/auth/me')->getStatusCode());
    }

    public function testANewLinkReplacesTheLast(): void
    {
        $first = $this->loginCode($this->bot);
        $second = $this->loginCode($this->bot);

        self::assertSame(401, $this->postJson('/api/agent/auth/link', ['code' => $first])->getStatusCode());
        self::assertSame(200, $this->postJson('/api/agent/auth/link', ['code' => $second])->getStatusCode());
    }

    public function testOfTwoTabsOpeningOneLinkAtOnceOneSignsIn(): void
    {
        $code = $this->loginCode($this->bot);
        // The other tab spends the code between this one finding the bot by it and taking it.
        $response = $this->whileListening(
            'eloquent.retrieved: ' . Bot::class,
            static function (Bot $bot) use ($code): void {
                Bot::query()->whereKey($bot->id)->where('login_code', hash('sha256', $code))->update(['login_code' => null, 'login_code_expires_at' => null]);
            },
            fn(): ResponseInterface => $this->postJson('/api/agent/auth/link', ['code' => $code]),
        );

        self::assertSame([401, SignInRefusedException::LINK_REFUSED], [$response->getStatusCode(), $this->decode($response)['message']]);
        self::assertSame(401, $this->get('/api/agent/auth/me')->getStatusCode(), 'signed in by the other tab, not this one');
    }

    public function testAnAddressWhoseLinksKeepFailingWaits(): void
    {
        for ($i = 0; $i < SignInThrottle::MAX_ATTEMPTS; $i++) {
            self::assertSame(401, $this->postJson('/api/agent/auth/link', ['code' => 'not-a-code-' . $i])->getStatusCode());
        }

        // Even a good link waits now, the window is not over; the owner's password is counted apart.
        $waiting = $this->postJson('/api/agent/auth/link', ['code' => $this->loginCode($this->bot)]);
        self::assertSame(429, $waiting->getStatusCode());
        self::assertGreaterThan(0, (int) $waiting->getHeaderLine('Retry-After'));
        self::assertSame(401, $this->get('/api/agent/auth/me')->getStatusCode());
        self::assertSame(200, $this->postJson('/api/admin/auth/login', ['username' => self::ADMIN_USERNAME, 'password' => self::ADMIN_PASSWORD])->getStatusCode());
    }

    public function testAnAgentsSessionIsNoWayIntoTheOwnersPanel(): void
    {
        $this->loginAsAgent($this->bot);

        // (Their session opens nothing of the owner's panel: RouteGuardsTest.) The owner's own sections are not in theirs at all.
        foreach (['/api/agent/servers', '/api/agent/servers/1/grants', '/api/agent/mass-grants', '/api/agent/agency', '/api/agent/agency/settings', '/api/agent/settings/config', '/api/agent/shops'] as $path) {
            self::assertSame(404, $this->get($path)->getStatusCode(), $path);
        }
        self::assertSame(404, $this->postJson('/api/agent/auth/login', ['username' => self::ADMIN_USERNAME, 'password' => self::ADMIN_PASSWORD])->getStatusCode());
    }

    public function testAnAgentsRequestNamingAnotherShopIsRefusedNeverServedInIt(): void
    {
        $this->loginAsAgent($this->bot);
        $main = User::query()->where('telegram_id', 501)->sole();
        $refused = fn(ResponseInterface $response): array => [$response->getStatusCode(), array_keys($this->decode($response)['errors'] ?? [])];

        self::assertSame([403, ['shop']], $refused($this->get('/api/agent/users', [NamedShop::HEADER => (string) Bot::MAIN])), 'in the header');
        self::assertSame([403, ['shop']], $refused($this->get('/api/agent/payments/1/receipt?shop=' . Bot::MAIN)), 'in the query of a picture');
        self::assertSame([403, ['shop']], $refused($this->send('PATCH', "/api/agent/users/{$main->id}", ['status' => 'banned'], ['X-Requested-With' => 'XMLHttpRequest', NamedShop::HEADER => (string) Bot::MAIN])), 'a change');
        self::assertSame('active', $main->refresh()->status->value, "the main shop's customer untouched");

        // Their own shop named is theirs: worked in it as without.
        self::assertSame(['agents_customer'], array_column($this->decode($this->get('/api/agent/users', [NamedShop::HEADER => (string) $this->bot->id]))['users'], 'username'));
        self::assertSame(401, $this->get('/api/admin/users', [NamedShop::HEADER => (string) $this->bot->id])->getStatusCode(), "naming their shop is no way into the owner's panel");
    }

    public function testTheOwnersSessionIsNoWayIntoAnAgentsPanel(): void
    {
        $this->loginAsAdmin();

        // (The owner's session opens nothing of an agent's panel: RouteGuardsTest.)
        self::assertSame(404, $this->get('/api/admin/account')->getStatusCode(), "the account is the agent's panel's");
        self::assertSame(404, $this->postJson('/api/admin/auth/link', ['code' => $this->loginCode($this->bot)])->getStatusCode());
    }

    public function testTheOwnerAndAnAgentStaySignedInSideBySide(): void
    {
        $this->loginAsAdmin();
        $this->loginAsAgent($this->bot);

        self::assertSame(['root', Bot::MAIN], $this->session('admin'));
        self::assertSame(['@agent_shop_bot', $this->bot->id], $this->session('agent'));
        self::assertSame(['main_customer', 'agent'], array_column($this->decode($this->get('/api/admin/users'))['users'], 'username'));
        self::assertSame(['agents_customer'], array_column($this->decode($this->get('/api/agent/users'))['users'], 'username'));

        // The owner's panel working in the agent's shop changes nothing of the agent's.
        $this->openShop($this->bot);
        self::assertSame(['root', $this->bot->id], $this->session('admin'));
        self::assertSame(['@agent_shop_bot', $this->bot->id], $this->session('agent'));

        // Signing out of one panel leaves the other.
        $this->postJson('/api/agent/auth/logout');
        self::assertSame(401, $this->get('/api/agent/auth/me')->getStatusCode());
        self::assertSame(['root', $this->bot->id], $this->session('admin'));

        $this->loginAsAgent($this->bot);
        $this->postJson('/api/admin/auth/logout');
        self::assertSame(401, $this->get('/api/admin/auth/me')->getStatusCode());
        self::assertSame(['@agent_shop_bot', $this->bot->id], $this->session('agent'));
    }

    public function testADecisionIsRecordedUnderTheAgentsBot(): void
    {
        $payment = CurrentBot::run($this->bot, function (): Payment {
            $customer = User::query()->where('telegram_id', 502)->sole();
            $order = $this->purchaseOrder($customer, Plan::query()->sole(), $this->server);

            return $this->receipt($this->cardPayment($order, $this->cardMethod()));
        });
        $this->loginAsAgent($this->bot);

        $rejected = $this->postJson("/api/agent/payments/{$payment->id}/reject", ['note' => 'رسید خوانا نیست']);

        self::assertSame(200, $rejected->getStatusCode(), (string) $rejected->getBody());
        self::assertSame(['failed', '@agent_shop_bot'], [$this->decode($rejected)['payment']['status'], $this->decode($rejected)['payment']['reviewer']]);
    }

    public function testAnAgentsPlansHaveTrafficAndSellOnTheShopsServers(): void
    {
        $this->loginAsAgent($this->bot);
        $input = ['name' => 'یک‌ماهه', 'price' => '150000', 'duration_days' => 30, 'ip_limit' => 2, 'servers' => [['server_id' => $this->server->id, 'all_inbounds' => true, 'inbound_ids' => []]]];

        $unlimited = $this->postJson('/api/agent/plans', $input + ['traffic_gb' => 0]);
        self::assertSame(422, $unlimited->getStatusCode());
        self::assertSame(['traffic_gb'], array_keys($this->decode($unlimited)['errors']), 'the traffic is what the agent pays for');
        self::assertSame(
            ['حجم پلن را به گیگابایت وارد کنید؛ بیشتر از 0 و حداکثر 100000.'],
            $this->decode($this->postJson('/api/agent/plans', $input + ['traffic_gb' => '']))['errors']['traffic_gb'],
            'none typed: no word of "0 is unlimited", which an agent\'s plan cannot be',
        );

        $created = $this->postJson('/api/agent/plans', $input + ['traffic_gb' => 50]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $plan = Plan::query()->withoutGlobalScope(CurrentBot::SCOPE)->findOrFail($this->decode($created)['plan']['id']);
        self::assertSame($this->bot->id, $plan->bot_id, "the agent's shop's");
        self::assertSame(['پلن اصلی'], Plan::query()->pluck('name')->all(), "the main shop's list untouched");
    }

    public function testTheAccountShowsTheBotItsTrafficAndItsLines(): void
    {
        $this->loginAsAgent($this->bot);
        $agent = $this->bot->agent ?? throw new \LogicException('no agent');

        $read = $this->decode($this->get('/api/agent/account'));
        $account = $read['account'];
        self::assertSame(['agent_shop_bot', true, Traffic::bytesOfGb(100)], [$account['bot']['username'], $account['bot']['connected'], $account['bot']['traffic_balance']]);
        self::assertSame([$agent->id, 'طلایی', '3000.00'], [$account['agent']['id'], $account['level']['name'], $account['level']['price_per_gb']]);
        self::assertNull($read['traffic_shortage'], 'their plan sells');
        $lines = $this->decode($this->get('/api/agent/account/traffic'))['lines'];
        self::assertSame([['purchase', Traffic::bytesOfGb(100), Traffic::bytesOfGb(100)]], array_map(static fn(array $line): array => [$line['type'], $line['bytes'], $line['balance_after']], $lines), 'the traffic their bot was handed over with');

        $this->traffic($this->bot, 10);
        self::assertSame(['balance' => Traffic::bytesOfGb(10), 'smallest_plan' => Traffic::bytesOfGb(20)], $this->decode($this->get('/api/agent/account'))['traffic_shortage'], 'too little for their smallest plan');
    }

    public function testTheOwnerOpensAnAgentsShopAndComesBack(): void
    {
        $this->loginAsAdmin();

        $shops = $this->decode($this->get('/api/admin/shops'))['shops'];
        self::assertSame([[Bot::MAIN, 'فروشگاه اصلی'], [$this->bot->id, 'فروشگاه نماینده']], array_map(static fn(array $shop): array => [$shop['id'], $shop['name']], $shops));

        $this->openShop($this->bot);
        self::assertSame(['id' => $this->bot->id, 'name' => 'فروشگاه نماینده', 'username' => 'agent_shop_bot', 'status' => 'active'], $this->decode($this->get('/api/admin/auth/me'))['session']['shop']);
        self::assertSame(['agents_customer'], array_column($this->decode($this->get('/api/admin/users'))['users'], 'username'));
        self::assertSame(1, $this->decode($this->get('/api/admin/agency'))['summary']['agents'], "the agency stays the main bot's whatever shop is open");

        $this->openShop(CurrentBot::main());
        self::assertSame(['main_customer', 'agent'], array_column($this->decode($this->get('/api/admin/users'))['users'], 'username'), 'the agent is a customer of the main bot');
    }

    public function testTheShopsOwnSectionsWorkAcrossEveryShopWhicheverIsOpen(): void
    {
        $this->subscription(User::query()->where('telegram_id', 501)->sole(), Plan::query()->sole(), $this->server, 'main_customer_1');
        CurrentBot::run($this->bot, function (): void {
            $this->subscription(User::query()->where('telegram_id', 502)->sole(), Plan::query()->sole(), $this->server, 'agents_customer_1');
        });
        $this->loginAsAdmin();
        $this->openShop($this->bot);

        $servers = $this->decode($this->get('/api/admin/servers'))['servers'];
        self::assertSame([2], array_column(array_column($servers, 'counts'), 'active_subscriptions'), "the server's services of every bot");
        self::assertSame(['running' => 2, 'unstarted' => 0], $this->decode($this->get("/api/admin/servers/{$this->server->id}/grants"))['audience']);
        $gift = $this->decode($this->get('/api/admin/mass-grants'))['audience'];
        self::assertSame([2, 1], [$gift['all']['running'], $gift['agents']['running']], "a gift reaches every bot's services; the agents' audience, their bots' alone");
    }

    public function testAnAgentWhoseBotIsNotHandedOverYetIsNamedByTheirHandleElseTheirShop(): void
    {
        $withHandle = $this->agent(overrides: ['telegram_id' => 601, 'username' => 'sara'])->ownBot ?? self::fail('no shop');
        $withoutHandle = $this->agent(overrides: ['telegram_id' => 602])->ownBot ?? self::fail('no shop');

        $this->loginAsAgent($withHandle);
        self::assertSame('@sara', $this->decode($this->get('/api/agent/auth/me'))['session']['name']);

        $this->postJson('/api/agent/auth/logout');
        $this->loginAsAgent($withoutHandle);
        self::assertSame("agent#{$withoutHandle->id}", $this->decode($this->get('/api/agent/auth/me'))['session']['name'], 'what their decisions are recorded under');
    }

    public function testAShopIsNamedByItsBotsTitleElseItsAgentAndSaysWhetherItRuns(): void
    {
        $this->agentBot($this->agent(overrides: ['telegram_id' => 601, 'first_name' => 'Sara']), 0, ['token' => '601000:AAsecond-agent-bot-token-0123456789', 'telegram_id' => 601000, 'username' => 'sara_shop_bot', 'title' => null]);
        $reza = $this->agent(overrides: ['telegram_id' => 602, 'first_name' => 'Reza']);
        CurrentBot::run(Bot::MAIN, fn() => $this->service(AgencyActions::class)->revoke($reza, null));
        $this->loginAsAdmin();

        $shops = $this->decode($this->get('/api/admin/shops'))['shops'];

        self::assertSame(['فروشگاه اصلی', 'فروشگاه نماینده', 'نماینده: Sara', 'نماینده: Reza'], array_column($shops, 'name'), 'the handle is said apart, never as the name');
        self::assertSame([null, 'agent_shop_bot', 'sara_shop_bot', null], array_column($shops, 'username'), "the main bot's is config.php's, none here");
        self::assertSame(['active', 'active', 'active', 'disabled'], array_column($shops, 'status'), 'an agency that ended keeps its shop, its bot off');

        $this->openShop($reza->ownBot ?? self::fail('no shop'));
        $opened = $this->decode($this->get('/api/admin/auth/me'))['session']['shop'];
        self::assertSame(['نماینده: Reza', 'disabled'], [$opened['name'], $opened['status']], 'the owner still opens it');
    }

    public function testAnAgentWhoseAgencyEndedIsSignedOutAndTheirLinkGoesWithIt(): void
    {
        $this->loginAsAgent($this->bot);
        self::assertSame(200, $this->get('/api/agent/auth/me')->getStatusCode());
        $unused = $this->loginCode($this->bot);

        $agent = $this->bot->agent ?? throw new \LogicException('no agent');
        CurrentBot::run(Bot::MAIN, fn() => $this->service(AgencyActions::class)->revoke($agent, null));

        self::assertSame(401, $this->get('/api/agent/auth/me')->getStatusCode());
        self::assertSame(401, $this->get('/api/agent/orders')->getStatusCode());
        self::assertSame(401, $this->postJson('/api/agent/auth/link', ['code' => $unused])->getStatusCode(), 'the link they had not used yet');
    }

    public function testALinkOpenedWhereAnotherAgentIsSignedInTakesTheirPlaceOnlyWhenAskedTo(): void
    {
        $other = $this->agentBot($this->agent(overrides: ['telegram_id' => 601]), 0, ['token' => '601000:AAsecond-agent-bot-token-0123456789', 'telegram_id' => 601000, 'username' => 'sara_shop_bot']);
        $this->loginAsAgent($this->bot);
        $code = $this->loginCode($other);

        $asked = $this->postJson('/api/agent/auth/link', ['code' => $code]);
        self::assertSame([409, ['replace']], [$asked->getStatusCode(), array_keys($this->decode($asked)['errors'])]);
        self::assertSame(['@agent_shop_bot', $this->bot->id], $this->session('agent'), 'the open session stays');

        $replaced = $this->postJson('/api/agent/auth/link', ['code' => $code, 'replace' => true]);
        self::assertSame(200, $replaced->getStatusCode(), 'the link was not spent by the question');
        self::assertSame(['@sara_shop_bot', $other->id], $this->session('agent'));

        // Their own fresh link, signed in already: nothing to ask.
        self::assertSame(200, $this->postJson('/api/agent/auth/link', ['code' => $this->loginCode($other)])->getStatusCode());
    }

    public function testAnAgentSignsEveryOtherBrowserOfTheirsOut(): void
    {
        $this->loginAsAgent($this->bot);
        $laptop = $_SESSION;
        $_SESSION = [];
        $this->loginAsAgent($this->bot);
        $phone = $_SESSION;

        self::assertSame(204, $this->postJson('/api/agent/auth/sessions/end')->getStatusCode());

        self::assertSame(['@agent_shop_bot', $this->bot->id], $this->session('agent'), 'this browser stays signed in');
        $_SESSION = $laptop;
        self::assertSame(401, $this->get('/api/agent/auth/me')->getStatusCode(), 'the other is signed out');
        $_SESSION = $phone;
        self::assertSame(1, $this->bot->refresh()->panel_epoch - $this->epochOf($laptop), 'one epoch on');
    }

    public function testOfTwoBrowsersSigningTheOthersOutAtOnceTheFirstEndsTheSecond(): void
    {
        $this->loginAsAgent($this->bot);
        $first = $_SESSION;
        $_SESSION = [];
        $this->loginAsAgent($this->bot);

        // The first browser moves the epoch between this one's sign-in being read and its own move.
        $answer = $this->whileListening(
            'eloquent.retrieved: ' . Bot::class,
            function (Bot $bot) use ($first): void {
                Bot::query()->whereKey($bot->id)->where('panel_epoch', $this->epochOf($first))->update(['panel_epoch' => $this->epochOf($first) + 1]);
            },
            fn(): ResponseInterface => $this->postJson('/api/agent/auth/sessions/end'),
        );

        self::assertSame(401, $answer->getStatusCode(), 'ended by the other: nothing to hold');
        self::assertSame(401, $this->get('/api/agent/auth/me')->getStatusCode());
    }

    public function testEveryRequestAsksAgainWhetherTheBotIsStillTheirsUnderTheSameEpoch(): void
    {
        $this->loginAsAgent($this->bot);
        $this->bot->forceFill(['panel_epoch' => $this->bot->panel_epoch + 1])->save();
        self::assertSame(401, $this->get('/api/agent/auth/me')->getStatusCode(), 'the shop ended their sessions');

        $this->loginAsAgent($this->bot->refresh());
        $other = $this->customer(['telegram_id' => 503, 'agency_level_id' => $this->bot->agent?->agency_level_id]);
        $this->bot->forceFill(['user_id' => $other->id])->save();
        self::assertSame(401, $this->get('/api/agent/users')->getStatusCode(), 'the bot is no longer theirs');
    }

    /** @param array<string, mixed> $session The epoch an agent's session of the browser was signed in under. */
    private function epochOf(array $session): int
    {
        $held = $session['auth.agent'] ?? null;

        return is_array($held) ? (int) $held['epoch'] : self::fail('No agent is signed in to that browser.');
    }

    /** @return array{string, int} Who is signed in to that panel and whose shop it shows. */
    private function session(string $panel): array
    {
        $response = $this->get("/api/{$panel}/auth/me");
        self::assertSame(200, $response->getStatusCode(), "/api/{$panel}/auth/me");
        $session = $this->decode($response)['session'];

        return [$session['name'], $session['shop']['id']];
    }
}
