<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Core\Http\ErrorHandler;
use App\Core\Http\HttpKernel;
use App\Core\Http\Urls;
use App\Modules\Accounts\Enums\SignInMethod;
use App\Modules\Accounts\Http\CustomerAuthMiddleware;
use App\Modules\Accounts\Models\CustomerSession;
use App\Modules\Accounts\Services\CustomerSessions;
use App\Modules\Accounts\Tasks\PruneAccountsTask;
use App\Modules\Bots\CurrentBot;
use App\Modules\Notifications\Enums\NoticeType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Store\Http\StoreMiddleware;
use App\Modules\Store\Models\MiniApp;
use App\Modules\Store\Models\Website;
use App\Modules\Store\Services\MiniAppPreviews;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Support\Models\Ticket;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Tests\HttpTestCase;
use Tests\Support\ApiDescription;

/**
 * A panel's look at the mini app as one of the shop's customers (`POST /api/{panel}/mini-app/preview`): a session of
 * theirs for 30 minutes that reads what their own reads — through the mini app's key alone, even while the app is
 * switched off — and writes nothing (a 403 before any route runs), counts as no visit, is none of their devices and
 * tells them nothing; each panel previews its own shop's customers, never a banned one.
 */
final class MiniAppPreviewTest extends HttpTestCase
{
    private const URL = '/api/admin/mini-app/preview';

    /** What the route walk sends: a field no operation takes — the guard answers before anything reads a body. */
    private const STRAY_BODY = ['stray' => true];

    private Website $website;

    private MiniApp $miniApp;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-11 12:00:00');
        $this->telegram();
        $this->website = $this->website();
        $this->miniApp = $this->miniApp();
        $this->customer = $this->wallet($this->customer(['username' => 'ali']), '500000');
        $this->customer->forceFill(['last_seen_at' => now()->subHour()])->save();
        $this->loginAsAdmin();
    }

    public function testThePanelOpensAPreviewAsTheCustomerForThirtyMinutes(): void
    {
        $logs = $this->logs();

        $response = $this->postJson(self::URL, ['user_id' => $this->customer->id]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $preview = $this->decode($response)['preview'];
        self::assertSame(['token', 'expires_at', 'path'], array_keys($preview));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $preview['token']);
        self::assertSame(['2026-11-11T12:30:00+00:00', "/app/{$this->miniApp->key}/"], [$preview['expires_at'], $preview['path']]);

        $session = CustomerSession::query()->sole();
        self::assertSame([$this->customer->id, SignInMethod::Preview, CustomerSessions::PREVIEW_DEVICE, null, null], [$session->user_id, $session->method, $session->device, $session->ip, $session->authenticated_at]);
        self::assertSame(hash('sha256', $preview['token']), $session->token_hash, 'only its hash kept');
        self::assertEquals(now()->addMinutes(30), $session->expires_at);
        self::assertTrue($logs->hasInfoThatContains("root opened a preview of the mini app as customer #{$this->customer->id}"), 'who looked, and as whom');

        $this->bearer($preview['token']);
        $me = $this->decode($this->get($this->storeApi($this->miniApp, '/me')));
        self::assertSame($this->customer->id, $me['customer']['id']);
        self::assertSame(['method' => 'preview', 'biometric' => false, 'recent_until' => null], $me['session'], 'never a recent sign-in');
        self::assertNull($me['staff']);
    }

    public function testEachPanelPreviewsItsOwnShopsCustomersOnly(): void
    {
        $bot = $this->agentBot();
        $agents = CurrentBot::run($bot, fn(): MiniApp => $this->miniApp());
        $theirs = CurrentBot::run($bot, fn(): User => $this->customer(['telegram_id' => 8383, 'first_name' => 'Reza']));

        $this->assertRefusedUnder('user_id', MiniAppPreviews::NOT_A_CUSTOMER, $this->postJson(self::URL, ['user_id' => $theirs->id]), message: "the agent's customer, from the owner's main shop");

        $_SESSION = [];
        $this->loginAsAgent($bot);
        $this->assertRefusedUnder('user_id', MiniAppPreviews::NOT_A_CUSTOMER, $this->postJson('/api/agent/mini-app/preview', ['user_id' => $this->customer->id]), message: "the owner's customer, from the agent's panel");
        $response = $this->postJson('/api/agent/mini-app/preview', ['user_id' => $theirs->id]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $preview = $this->decode($response)['preview'];
        self::assertSame("/app/{$agents->key}/", $preview['path'], "the agent's own app");

        $this->bearer($preview['token']);
        self::assertSame($theirs->id, $this->decode($this->get($this->storeApi($agents, '/me')))['customer']['id'] ?? null);
        self::assertSame(401, $this->get($this->storeApi($this->miniApp, '/me'))->getStatusCode(), "the owner's shop's app knows no such session");
    }

    public function testABannedCustomerOrNobodyOfTheShopIsRefused(): void
    {
        $banned = $this->customer(['telegram_id' => 7272, 'status' => UserStatus::Banned]);

        $this->assertRefusedUnder('user_id', MiniAppPreviews::BANNED, $this->postJson(self::URL, ['user_id' => $banned->id]));
        $this->assertRefusedUnder('user_id', MiniAppPreviews::NOT_A_CUSTOMER, $this->postJson(self::URL, ['user_id' => 999_999]));
        $this->assertRefusedUnder('user_id', MiniAppPreviews::NO_USER, $this->unchecked()->postJson(self::URL, []));
        $this->assertRefusedUnder('user_id', MiniAppPreviews::NO_USER, $this->unchecked()->postJson(self::URL, ['user_id' => 'ali']));
        self::assertSame(0, CustomerSession::query()->count(), 'no session opened');
    }

    public function testThePathIsUnderTheFolderTheShopLivesIn(): void
    {
        $container = $this->app()->container();
        $mountedAt = $container->get('http.base_path');
        $container->set('http.base_path', '/shop');
        try {
            $slim = HttpKernel::create($this->app());
        } finally {
            $container->set('http.base_path', $mountedAt);
        }

        $response = $slim->handle((new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/shop/api/admin/mini-app/preview')
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream((string) json_encode(['user_id' => $this->customer->id]))));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame("/shop/app/{$this->miniApp->key}/", $this->decode($response)['preview']['path']);
    }

    /** The customer's account as their own session reads it, through the mini app, writing nothing: no visit, no use. */
    public function testItReadsWhatTheirOwnSessionReadsAndWritesNothing(): void
    {
        $server = $this->sellingServer();
        $subscription = $this->subscription($this->customer, $this->plan([], $server), $server, 'ali_1');
        $ticket = $this->ticket($this->customer, overrides: ['customer_unread' => true]);
        Notification::query()->create(['user_id' => $this->customer->id, 'type' => NoticeType::PaymentSettled, 'text' => 'پرداخت شما تایید شد.']);
        $own = $this->customerSession($this->customer, ['method' => SignInMethod::MiniApp]);
        $preview = $this->preview($this->customer);
        $reads = ['', '/me', '/subscriptions', "/subscriptions/{$subscription->id}", '/wallet', '/tickets', "/tickets/{$ticket->id}", '/orders', '/notifications', '/plans', '/me/sessions'];
        $rows = $this->everyRow();

        $this->bearer($preview);
        $previewed = [];
        foreach ($reads as $path) {
            $read = $this->get($this->storeApi($this->miniApp, $path));
            self::assertSame(200, $read->getStatusCode(), "GET {$path}: " . $read->getBody());
            $previewed[$path] = $this->decode($read);
        }

        self::assertSame($rows, $this->everyRow(), 'nothing written: no visit of theirs, no use of the session, the ticket and the notice unread');
        self::assertSame([], $this->telegram()->calls(), 'Telegram told nothing');
        self::assertSame([1, 1, 1], [$previewed['/subscriptions']['meta']['total'], $previewed['/tickets']['meta']['total'], $previewed['/notifications']['meta']['unread']]);
        self::assertSame('500000.00', $previewed['/wallet']['balance']);
        self::assertSame([CustomerSession::query()->where('method', SignInMethod::MiniApp)->sole()->id], array_column($previewed['/me/sessions']['sessions'], 'id'), 'their own devices, the preview none of them');
        self::assertSame([false], array_column($previewed['/me/sessions']['sessions'], 'current'));

        $this->bearer($own);
        foreach (['/subscriptions', "/subscriptions/{$subscription->id}", '/wallet', '/tickets', "/tickets/{$ticket->id}", '/orders', '/notifications', '/plans'] as $path) {
            self::assertSame($this->decode($this->get($this->storeApi($this->miniApp, $path))), $previewed[$path], "GET {$path}: as their own session reads it");
        }
        $me = $this->decode($this->get($this->storeApi($this->miniApp, '/me')));
        self::assertSame(array_diff_key($me, ['session' => 0]), array_diff_key($previewed['/me'], ['session' => 0]));
    }

    /** A wide sample of what a customer does, each with what it needs to work: every one refused, nothing changed. */
    public function testEveryWriteIsRefusedAndNothingIsWritten(): void
    {
        $server = $this->sellingServer();
        $plan = $this->plan([], $server);
        $subscription = $this->subscription($this->customer, $plan, $server, 'ali_1');
        $card = $this->cardMethod();
        $ticket = $this->ticket($this->customer, overrides: ['customer_unread' => true]);
        $this->wheelOn();
        $this->wheelPrize();
        $this->miniApp->forceFill(['reviews_enabled' => true])->save();
        $notice = Notification::query()->create(['user_id' => $this->customer->id, 'type' => NoticeType::PaymentSettled, 'text' => 'پرداخت شما تایید شد.']);
        $this->customerSession($this->customer, ['method' => SignInMethod::MiniApp]);
        $own = CustomerSession::query()->sole();
        $this->bearer($this->preview($this->customer));
        $rows = $this->everyRow();
        $api = fn(string $path): string => $this->storeApi($this->miniApp, $path);

        $answers = [
            'POST /orders' => $this->send('POST', $api('/orders'), ['plan_id' => $plan->id, 'server_id' => $server->id, 'method_id' => $card->id], ['Idempotency-Key' => 'preview-1']),
            'POST /wallet/top-up' => $this->send('POST', $api('/wallet/top-up'), ['amount' => 50_000, 'method_id' => $card->id], ['Idempotency-Key' => 'preview-2']),
            'POST /tickets' => $this->postJson($api('/tickets'), ['subject' => 'تمدید سرویس', 'body' => 'سرویس من کی تمام می‌شود؟']),
            'POST /tickets/{id}/read' => $this->postJson($api("/tickets/{$ticket->id}/read")),
            'POST /tickets/{id}/messages' => $this->postJson($api("/tickets/{$ticket->id}/messages"), ['body' => 'هنوز وصل نمی‌شود.']),
            'POST /wheel/spin' => $this->postJson($api('/wheel/spin')),
            'PATCH /subscriptions/{id}' => $this->patchJson($api("/subscriptions/{$subscription->id}"), ['auto_renew' => true, 'label' => 'گوشی']),
            'POST /subscriptions/{id}/refresh' => $this->postJson($api("/subscriptions/{$subscription->id}/refresh")),
            'POST /me/reauthenticate' => $this->postJson($api('/me/reauthenticate'), ['method' => 'mini_app', 'init_data' => $this->launchData()]),
            'PUT /me/biometric' => $this->putJson($api('/me/biometric')),
            'POST /reviews' => $this->postJson($api('/reviews'), ['name' => 'Ali R.', 'rating' => 5, 'body' => 'سرعت عالی و پشتیبانی خوب.']),
            'POST /notifications/read' => $this->postJson($api('/notifications/read'), ['ids' => [$notice->id]]),
            'POST /test-account' => $this->postJson($api('/test-account'), ['server_id' => $server->id]),
            'PATCH /me' => $this->patchJson($api('/me'), ['first_name' => 'Reza']),
            'DELETE /me/sessions/{id}' => $this->deleteJson($api("/me/sessions/{$own->id}")),
            'POST /auth/logout' => $this->postJson($api('/auth/logout')),
        ];

        foreach ($answers as $request => $answer) {
            self::assertSame([403, CustomerAuthMiddleware::PREVIEW], self::answer($answer), $request);
        }
        self::assertSame($rows, $this->everyRow(), 'nothing written: no order, payment, ticket, spin, review or change, the ticket and the notice unread');
        self::assertTrue(Ticket::query()->whereKey($ticket->id)->sole()->customer_unread);
        self::assertEquals(now()->subHour(), $this->customer->refresh()->last_seen_at, 'no visit of theirs');
        self::assertFalse(Subscription::query()->whereKey($subscription->id)->sole()->auto_renew);
        self::assertSame(200, $this->get($api('/me'))->getStatusCode(), 'signing out refused, the preview goes on');
    }

    /**
     * Every route of the Store API asked through the mini app's key with a preview's token, by every method but GET: a 403
     * before anything — the stray body is never read —, but for what the website alone has (the router's 404) and the
     * builder's price, which only works something out (to its own refusal here: the builder is off).
     */
    public function testNoRouteButAReadRunsForAPreview(): void
    {
        $this->bearer($this->preview($this->customer));
        $rows = $this->everyRow();

        $refused = 0;
        $other = [];
        foreach ($this->storeRoutes() as [$method, $template, $path]) {
            // The launch data's sign-in takes no token: a sign-in like any other, with or without a preview's.
            if ($method === 'GET' || $template === '/auth/mini-app' || str_starts_with($template, '/admin/')) {
                continue;
            }
            $answer = self::answer($this->unchecked()->json($method, $this->storeApi($this->miniApp, $path), self::STRAY_BODY));
            if ($answer === [403, CustomerAuthMiddleware::PREVIEW]) {
                $refused++;
            } else {
                $other["{$method} {$template}"] = $answer;
            }
        }

        self::assertGreaterThan(35, $refused, 'the walk reached them');
        self::assertSame(422, ($other['POST /custom-services/quote'] ?? [0])[0], "the builder's price, let through");
        unset($other['POST /custom-services/quote']);
        $websiteOnly = self::websiteOnlyWrites();
        self::assertSame(array_fill_keys($websiteOnly, [404, ErrorHandler::MESSAGES[404]]), $other, "what the website alone has is no route under the mini app's key");
        self::assertSame($rows, $this->everyRow());
    }

    /**
     * The builder's price, which the mini app asks by itself as the customer builds: a preview may ask it — it keeps
     * nothing —, but not with a discount code, whose tries that do not hold are the customer's to spend; a switched-off
     * app's preview the same.
     */
    public function testTheBuildersPriceIsWorkedOutForAPreviewWithoutACode(): void
    {
        $server = $this->sellingServer();
        $this->customServicesOn([$server]);
        $package = ['gb' => 50, 'days' => 30, 'server_id' => $server->id];
        $quote = fn(array $body): ResponseInterface => $this->postJson($this->storeApi($this->miniApp, '/custom-services/quote'), $body);
        $this->bearer($this->customerSession($this->customer, ['method' => SignInMethod::MiniApp]));
        $own = $this->decode($quote($package));
        $this->bearer($this->preview($this->customer));
        $rows = $this->everyRow();

        $previewed = $quote($package);
        self::assertSame(200, $previewed->getStatusCode(), (string) $previewed->getBody());
        self::assertSame($own, $this->decode($previewed), 'as their own session works it out');
        self::assertSame(200, $quote($package + ['discount_code' => ' '])->getStatusCode(), 'a blank code is none');
        self::assertSame([403, CustomerAuthMiddleware::PREVIEW], self::answer($quote($package + ['discount_code' => 'NOWRUZ'])), "a code's tries are the customer's");
        self::assertSame($rows, $this->everyRow(), 'nothing kept');

        $this->miniApp->forceFill(['enabled' => false])->save();
        self::assertSame($own, $this->decode($quote($package)), 'a switched-off app, too');
        self::assertSame([403, CustomerAuthMiddleware::PREVIEW], self::answer($quote($package + ['discount_code' => 'NOWRUZ'])));
    }

    public function testUnderAWebsitesKeyItOpensNothing(): void
    {
        $this->customer->forceFill(['role' => UserRole::Admin])->save();
        $this->website->forceFill(['staff_enabled' => true, 'staff_strong_sign_in' => false])->save();
        $guest = $this->decode($this->get($this->storeApi($this->website, '/plans')));
        $this->bearer($this->preview($this->customer));

        $me = $this->get($this->storeApi($this->website, '/me'));
        self::assertSame([401, CustomerAuthMiddleware::SIGNED_OUT, 'Bearer'], [...self::answer($me), $me->getHeaderLine('WWW-Authenticate')]);
        self::assertSame([401, CustomerAuthMiddleware::SIGNED_OUT], self::answer($this->unchecked()->postJson($this->storeApi($this->website, '/tickets'), self::STRAY_BODY)), 'a write: signed out there too');
        self::assertSame($guest, $this->decode($this->get($this->storeApi($this->website, '/plans'))), 'where a guest may read: as if no token came');
        self::assertSame(401, $this->get($this->storeApi($this->website, '/admin/orders'))->getStatusCode(), "an admin's preview works no admin API");
    }

    public function testASwitchedOffMiniAppOpensToItsPreviewAlone(): void
    {
        $this->miniApp->forceFill(['enabled' => false])->save();
        $preview = $this->preview($this->customer);
        $closed = [404, StoreMiddleware::CLOSED];

        self::assertSame($closed, self::answer($this->get($this->storeApi($this->miniApp))), 'without a token');
        $this->bearer($this->customerSession($this->customer, ['method' => SignInMethod::MiniApp]));
        self::assertSame($closed, self::answer($this->get($this->storeApi($this->miniApp))), "the customer's own session");
        self::assertSame($closed, self::answer($this->get($this->storeApi($this->miniApp, '/me'))));
        self::assertSame($closed, self::answer($this->postJson($this->storeApi($this->miniApp, '/auth/mini-app'), ['init_data' => $this->launchData()])));

        $this->bearer($preview);
        foreach (['', '/me', '/plans', '/status', '/wallet'] as $path) {
            self::assertSame(200, $this->get($this->storeApi($this->miniApp, $path))->getStatusCode(), "GET {$path}: as if it were on");
        }
        self::assertNotNull($this->decode($this->get($this->storeApi($this->miniApp)))['app'], 'the mini app as it looks');
        self::assertSame([403, CustomerAuthMiddleware::PREVIEW], self::answer($this->postJson($this->storeApi($this->miniApp, '/auth/mini-app'), ['init_data' => $this->launchData()])), 'only to read, whatever the route');
        self::assertSame(1, CustomerSession::query()->where('method', SignInMethod::MiniApp)->count(), 'nobody signed in through it');
        self::assertSame([403, CustomerAuthMiddleware::PREVIEW], self::answer($this->postJson($this->storeApi($this->miniApp, '/me/reauthenticate'), ['method' => 'mini_app', 'init_data' => $this->launchData()])));

        $bot = $this->agentBot();
        $agents = CurrentBot::run($bot, fn(): MiniApp => $this->miniApp(['enabled' => false]));
        self::assertSame($closed, self::answer($this->get($this->storeApi($agents))), "another shop's app: not this preview's");

        Carbon::setTestNow(now()->addMinutes(31));
        self::assertSame($closed, self::answer($this->get($this->storeApi($this->miniApp))), 'the preview ended: closed again');
    }

    public function testAPreviewEndsThirtyMinutesAfterItOpenedWhateverItsUse(): void
    {
        $this->bearer($this->preview($this->customer));
        $session = CustomerSession::query()->sole();

        Carbon::setTestNow(now()->addMinutes(29));
        self::assertSame(200, $this->get($this->storeApi($this->miniApp, '/me'))->getStatusCode());
        self::assertEquals(Carbon::parse('2026-11-11 12:30:00'), $session->refresh()->expires_at, 'never extended');
        self::assertEquals(Carbon::parse('2026-11-11 12:00:00'), $session->last_used_at, 'its use not written');

        Carbon::setTestNow(Carbon::parse('2026-11-11 12:30:00'));
        self::assertSame([401, CustomerAuthMiddleware::SIGNED_OUT], self::answer($this->get($this->storeApi($this->miniApp, '/me'))));

        $this->service(PruneAccountsTask::class)->run();
        self::assertSame(0, CustomerSession::query()->count(), 'gone with the ended sessions');
    }

    /** Nothing of the preview reaches the customer: no notice, no device of theirs, none of theirs pushed out. */
    public function testTheCustomerIsToldNothingAndKeepsTheirOwnSessions(): void
    {
        foreach (range(1, CustomerSessions::MAX_SESSIONS) as $n) {
            Carbon::setTestNow(now()->addSecond());
            $this->customerSession($this->customer);
        }
        $theirs = CustomerSession::query()->orderBy('id')->pluck('id')->all();

        $preview = $this->preview($this->customer);
        $previewId = CustomerSession::query()->where('method', SignInMethod::Preview)->sole()->id;

        self::assertSame($theirs, CustomerSession::query()->whereKeyNot($previewId)->orderBy('id')->pluck('id')->all(), 'their twenty all stay');
        self::assertSame([], $this->telegram()->calls(), 'Telegram told nothing');
        self::assertSame(0, Notification::query()->count(), 'no notice');
        self::assertSame(CustomerSessions::MAX_SESSIONS, $this->decode($this->get("/api/admin/users/{$this->customer->id}"))['account']['sessions'], 'the panel counts their devices: the preview is none');

        $this->bearer($this->customerSession($this->customer));
        $kept = CustomerSession::query()->pluck('id')->all();
        self::assertNotContains($theirs[0], $kept, 'a 21st of theirs ends their oldest…');
        self::assertContains($previewId, $kept, '…never the preview');
        self::assertCount(CustomerSessions::MAX_SESSIONS + 1, $kept);
        self::assertNotContains($previewId, array_column($this->decode($this->get($this->storeApi($this->website, '/me/sessions')))['sessions'], 'id'));
        self::assertSame(404, $this->deleteJson($this->storeApi($this->website, "/me/sessions/{$previewId}"))->getStatusCode(), 'none of theirs to end');

        $this->bearer($preview);
        self::assertSame(200, $this->get($this->storeApi($this->miniApp, '/me'))->getStatusCode(), 'the preview goes on');
    }

    /** POST the preview for `$user` from the owner's panel, which must be opened: its token. */
    private function preview(User $user): string
    {
        $response = $this->postJson(self::URL, ['user_id' => $user->id]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->decode($response)['preview']['token'];
    }

    /**
     * Every row of every table, by table: what a preview may not change.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function everyRow(): array
    {
        $rows = [];
        foreach ($this->db()->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
            $rows[$table->name] = array_map(static fn(object $row): array => (array) $row, $this->db()->select("SELECT * FROM \"{$table->name}\" ORDER BY rowid"));
        }

        return $rows;
    }

    /**
     * The Store API's routes as requests under a door's base address: [method, the route's path as the description has
     * it, a path to ask], each placeholder filled with a value its pattern takes.
     *
     * @return list<array{string, string, string}>
     */
    private function storeRoutes(): array
    {
        $routes = [];
        foreach ($this->app()->http()->getRouteCollector()->getRoutes() as $route) {
            /** @var RouteInterface $route */
            $pattern = $route->getPattern();
            if (!str_starts_with($pattern, Urls::STORE)) {
                continue;
            }
            $relative = substr($pattern, strlen(Urls::STORE));
            $template = (string) preg_replace(Urls::PLACEHOLDER, '{$1}', $relative);
            $path = (string) preg_replace_callback(Urls::PLACEHOLDER, static fn(array $placeholder): string => match (true) {
                str_contains($placeholder[2] ?? '', '|') => explode('|', $placeholder[2])[0],
                str_starts_with($placeholder[2] ?? '', '[0-9]') => '1',
                default => 'x',
            }, $relative);
            foreach ($route->getMethods() as $method) {
                $routes[] = [$method, $template, $path];
            }
        }

        return $routes;
    }

    /** @return list<string> The writes resources/api/openapi.yaml marks `x-door: website`: `METHOD /path` under the store's base address. */
    private static function websiteOnlyWrites(): array
    {
        $writes = [];
        foreach (ApiDescription::read()['paths'] as $path => $item) {
            foreach ($item as $method => $operation) {
                if ($method !== 'get' && is_array($operation) && ($operation['x-door'] ?? null) === 'website') {
                    $writes[] = strtoupper($method) . ' ' . substr($path, strlen('/api/store/v1/{store}'));
                }
            }
        }

        return $writes;
    }

    /** @return array{int, mixed} The answer's status and its message */
    private static function answer(ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true);

        return [$response->getStatusCode(), is_array($body) ? $body['message'] ?? null : null];
    }
}
