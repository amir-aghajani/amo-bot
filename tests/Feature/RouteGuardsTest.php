<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Http\HttpKernel;
use App\Core\Http\Middleware\InstalledMiddleware;
use App\Core\Http\Urls;
use App\Modules\Accounts\Enums\SignInMethod;
use App\Modules\Accounts\Http\CustomerAuthMiddleware;
use App\Modules\Accounts\Http\RecentSignInMiddleware;
use App\Modules\Accounts\Services\CustomerSessions;
use App\Modules\Auth\PanelAuthMiddleware;
use App\Modules\Bots\CurrentBot;
use App\Modules\Store\Enums\StaffGrant;
use App\Modules\Store\Http\StaffMiddleware;
use App\Modules\Store\Http\StoreMiddleware;
use App\Modules\Store\Models\Website;
use App\Modules\Telegram\Broadcasts\Audience;
use App\Modules\Telegram\Broadcasts\BroadcastMode;
use App\Modules\Telegram\Broadcasts\BroadcastService;
use App\Modules\Telegram\Emoji\CustomEmojis;
use App\Modules\Users\Models\User;
use Psr\Http\Message\ResponseInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\HttpTestCase;

/**
 * Every route behind the guards its group gives it — walked over the router itself, so a route added later is held to
 * them too: a panel's API needs that panel's session (the owner's and an agent's are apart) and nothing else opens one,
 * a change needs the CSRF header, a fresh shop answers nothing but its installer and what tells a panel to open it, and
 * an installed shop has no installer. A row of one shop is no other's: a route of the shop's screens that names a row
 * finds it only in its own shop, whoever asks from another. The websites' API answers only under the key of a website
 * that is on, a customer's own only to their bearer token — no panel's session, no CSRF header —, and what changes how
 * their account is signed in to only to a token whose sign-in is recent. The router decides what a request is, so an
 * address spelled another way — encoded, under a sub-folder — is the same route behind the same guards.
 */
final class RouteGuardsTest extends HttpTestCase
{
    private const STATE_CHANGING = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** What a walk asks every route with: a field no operation takes — the guards answer before anything reads a body. */
    private const STRAY_BODY = ['stray' => true];

    /** What each panel answers without a session of its own: the ways in — the owner's sign-in and its recovery, an agent's link. */
    private const WAYS_IN = [
        '/api/admin' => ['/api/admin/auth/login', '/api/admin/auth/recovery/key', '/api/admin/auth/recovery'],
        '/api/agent' => ['/api/agent/auth/link'],
    ];

    /**
     * What each route of the shop's screens that names a row (`{id}`) names, by the address before it: the row's kind,
     * which testOneShopsRowIsNoOtherShops() makes in the main bot's shop. A route of a kind not here fails the walk.
     */
    private const ROW_KINDS = [
        '/broadcasts' => 'broadcast',
        '/plans/categories' => 'category',
        '/plans' => 'plan',
        '/users' => 'customer',
        '/customer-groups' => 'group',
        '/orders' => 'order',
        '/payments' => 'payment',
        '/tickets' => 'ticket',
        '/reviews' => 'review',
        '/subscriptions' => 'subscription',
        '/payment-methods' => 'method',
        '/bot/channels' => 'channel',
        '/bot/custom-emojis' => 'emoji',
    ];

    /** A premium emoji's picture and animation are no row of a shop's: any a text carries is drawn, kept or not. */
    private const NOT_A_ROW = ['/bot/custom-emojis/{id}/image', '/bot/custom-emojis/{id}/animation'];

    /**
     * What the row walk asks with: the stray field — no request of its is held to the API description —, and a switch a
     * route reads before it looks for its row (a plan's, a category's) on: the walk is about the row, which the request
     * names (a refused switch would say nothing of it either).
     */
    private const ROW_BODY = self::STRAY_BODY + ['is_active' => true];

    /** The websites' API, under a store key. */
    private const STORE = '/api/store/v1';

    /**
     * What a website answers its visitor before they sign in, under its base address: the shop, what it sells, its
     * reviews — one written too (a customer's own when their token comes with it) —, and the ways in.
     */
    private const STORE_PUBLIC = [
        '',
        '/plans',
        '/plans/1',
        '/status',
        '/reviews',
        '/auth/nonce',
        '/auth/telegram/authorize',
        '/auth/telegram',
        '/auth/google',
        '/auth/register',
        '/auth/register/verify',
        '/auth/login',
        '/auth/login/2fa',
        '/auth/password/forgot',
        '/auth/password/reset',
        '/captcha/challenge',
        '/captcha/verify',
    ];

    /** What changes how a website customer's account is signed in to — a recent sign-in's alone (Accounts\Http\RecentSignInMiddleware). */
    private const STORE_CREDENTIALS = [
        'PUT /me/password',
        'POST /me/2fa/setup',
        'POST /me/2fa/enable',
        'POST /me/2fa/disable',
        'POST /me/identities/telegram',
        'POST /me/identities/google',
        'POST /me/identities/email',
        'POST /me/identities/email/verify',
        'DELETE /me/identities/telegram',
        'POST /me/merge',
    ];

    public function testAPanelsApiNeedsThatPanelsSession(): void
    {
        foreach (self::WAYS_IN as $panel => $waysIn) {
            foreach ($this->routes($panel) as [$method, $path]) {
                if (in_array($path, $waysIn, true)) {
                    continue;
                }
                $response = $this->ask($method, $path);

                self::assertSame(401, $response->getStatusCode(), "{$method} {$path}");
                self::assertSame(PanelAuthMiddleware::SIGNED_OUT, $this->decode($response)['message'], "{$method} {$path}");
            }
        }
    }

    public function testOnePanelsSessionOpensNothingOfTheOthers(): void
    {
        $this->loginAsAdmin();
        foreach ($this->routes('/api/agent') as [$method, $path]) {
            if (!in_array($path, self::WAYS_IN['/api/agent'], true)) {
                self::assertSame(401, $this->ask($method, $path)->getStatusCode(), "the owner's session on {$method} {$path}");
            }
        }

        $_SESSION = [];
        $this->loginAsAgent($this->agentBot());
        foreach ($this->routes('/api/admin') as [$method, $path]) {
            if (!in_array($path, self::WAYS_IN['/api/admin'], true)) {
                self::assertSame(401, $this->ask($method, $path)->getStatusCode(), "an agent's session on {$method} {$path}");
            }
        }
    }

    /**
     * Every route of the shop's screens that names a row, called with the main bot's row of its kind: a 404 under an
     * agent's session (their bot's shop) and under the owner naming the agent's shop — whatever it would have done —,
     * while the owner in the main bot's shop reads every one of them.
     */
    public function testOneShopsRowIsNoOtherShops(): void
    {
        $rows = $this->mainShopRows();
        $routes = $this->rowRoutes($rows);
        $bot = $this->agentBot();

        $this->loginAsAgent($bot);
        foreach ($routes as [$method, $path]) {
            self::assertSame(404, $this->unchecked()->json($method, '/api/agent' . $path, self::ROW_BODY)->getStatusCode(), "an agent's session on {$method} {$path}");
        }

        $_SESSION = [];
        $this->loginAsAdmin();
        $this->openShop($bot);
        foreach ($routes as [$method, $path]) {
            self::assertSame(404, $this->unchecked()->json($method, '/api/admin' . $path, self::ROW_BODY)->getStatusCode(), "the owner in the agent's shop on {$method} {$path}");
        }

        // The rows are what their routes name: in their own shop, the reads find them.
        $this->openShop(CurrentBot::main());
        foreach ($routes as [$method, $path]) {
            if ($method === 'GET' && !str_ends_with($path, '/receipt') && !str_ends_with($path, '/attachment')) {
                self::assertSame(200, $this->get('/api/admin' . $path)->getStatusCode(), "the owner in the main shop on GET {$path}");
            }
        }

        // The agent's shop's admins on its website, granted everything: the main bot's rows are not there either.
        $_SESSION = [];
        $website = CurrentBot::run($bot, fn(): Website => $this->website(['staff_grants' => array_map(static fn(StaffGrant $grant): string => $grant->value, StaffGrant::cases())]));
        $this->loginAsStaff($website, CurrentBot::run($bot, fn(): User => $this->customer(['telegram_id' => 7002])));
        $staffRoutes = $this->rowRoutes($rows, Urls::STORE . '/admin');
        self::assertGreaterThan(30, count($staffRoutes));
        foreach ($staffRoutes as [$method, $path]) {
            self::assertSame(404, $this->unchecked()->json($method, $this->storeApi($website, '/admin' . $path), self::ROW_BODY)->getStatusCode(), "the agent's shop's admin on {$method} {$path}");
        }
    }

    public function testOnlyThePanelsApiOpensASession(): void
    {
        foreach ($this->routes('') as [$method, $path]) {
            if (str_starts_with($path, '/api/admin/') || str_starts_with($path, '/api/agent/')) {
                continue;
            }
            $_SESSION = [];
            $this->ask($method, $path);

            self::assertSame([], $_SESSION, "{$method} {$path} is stateless");
        }

        $this->get('/api/agent/auth/me');
        self::assertNotSame([], $_SESSION, 'a panel\'s API opens one');
    }

    public function testAChangeWithoutTheCsrfHeaderIsRefused(): void
    {
        $this->loginAsAdmin();
        $this->installed(true);

        foreach (['/api/admin', '/api/agent'] as $panel) {
            foreach ($this->routes($panel) as [$method, $path]) {
                if (in_array($method, self::STATE_CHANGING, true)) {
                    self::assertSame(403, $this->ask($method, $path, csrfHeader: false)->getStatusCode(), "{$method} {$path}");
                }
            }
        }

        $this->installed(false);
        foreach ($this->routes('/api/install') as [$method, $path]) {
            if (in_array($method, self::STATE_CHANGING, true)) {
                self::assertSame(403, $this->ask($method, $path, csrfHeader: false)->getStatusCode(), "{$method} {$path}");
            }
        }
    }

    public function testAFreshShopAnswersOnlyItsInstallerAndWhatOpensIt(): void
    {
        $this->installed(false);

        foreach ($this->routes('') as [$method, $path]) {
            if (in_array($path, ['/', '/health', '/api/app'], true) || str_starts_with($path, '/api/install')) {
                continue;
            }
            $response = $this->ask($method, $path);

            self::assertSame(503, $response->getStatusCode(), "{$method} {$path}");
            self::assertSame(InstalledMiddleware::NOT_INSTALLED, $this->decode($response)['message'], "{$method} {$path}");
        }
    }

    public function testAnInstalledShopHasNoInstaller(): void
    {
        foreach ($this->routes('/api/install') as [$method, $path]) {
            $response = $this->ask($method, $path);

            self::assertSame(404, $response->getStatusCode(), "{$method} {$path}");
            self::assertSame(InstalledMiddleware::INSTALLER_GONE, $this->decode($response)['message'], "{$method} {$path}");
        }
    }

    public function testTheStoreApiAnswersOnlyUnderTheKeyOfAWebsiteThatIsOn(): void
    {
        $off = $this->website(['enabled' => false]);

        foreach ([str_repeat('0', 24), $off->key] as $key) {
            foreach ($this->routes(self::STORE, $key) as [$method, $path]) {
                $response = $this->ask($method, $path);

                self::assertSame([404, StoreMiddleware::CLOSED], [$response->getStatusCode(), $this->decode($response)['message']], "{$method} {$path}");
            }
        }
    }

    public function testAWebsiteCustomersOwnNeedTheirSignInAndNoPanelsSessionIsOne(): void
    {
        $website = $this->website();
        $base = $this->storeApi($website);

        foreach ([false, true] as $panelSession) {
            if ($panelSession) {
                $this->loginAsAdmin();
            }
            foreach ($this->routes(self::STORE, $website->key) as [$method, $path]) {
                if (in_array(substr($path, strlen($base)), self::STORE_PUBLIC, true)) {
                    continue;
                }
                $response = $this->ask($method, $path);

                self::assertSame([401, CustomerAuthMiddleware::SIGNED_OUT], [$response->getStatusCode(), $this->decode($response)['message']], "{$method} {$path}");
            }
        }
    }

    public function testWhatChangesAWebsiteCustomersWaysInAsksARecentSignInAndNothingElseDoes(): void
    {
        $website = $this->website();
        $base = $this->storeApi($website);
        $sara = $this->webCustomer();

        foreach ($this->routes(self::STORE, $website->key) as [$method, $path]) {
            $route = substr($path, strlen($base));
            if (in_array($route, self::STORE_PUBLIC, true)) {
                continue;
            }
            // A session of her own each — a walked route may end one —, signed in long ago: a token that leaked.
            $this->bearer($this->customerSession($sara, ['authenticated_at' => now()->subSeconds(CustomerSessions::RECENT_SECONDS + 1)]));
            $response = $this->ask($method, $path);

            $asked = $response->getStatusCode() === 403 && $response->getHeaderLine('WWW-Authenticate') === RecentSignInMiddleware::CHALLENGE;
            self::assertSame(in_array("{$method} {$route}", self::STORE_CREDENTIALS, true), $asked, "{$method} {$path}");
        }
    }

    /**
     * The website's admin API is the shop's admins' alone, every route of it, each refusal at its door: a customer who is
     * no admin of the shop; an admin while the website lets none in; a session signed in with a password alone while the
     * website asks a strong sign-in (the challenge naming the strong ways); a session whose sign-in is older than a
     * working day; a route that names a grant the website has not given; a granted one — or approving a payment — whose
     * sign-in is not recent — a 403 each, before anything reads the request.
     */
    public function testTheWebsitesAdminApiIsTheShopsAdminsAloneEveryRouteOfIt(): void
    {
        $website = $this->website();
        $routes = $this->staffRoutes($website);
        $sara = $this->customer(['telegram_id' => 7001, 'username' => 'sara']);
        $refused = function (string $message, ?string $challenge = null) use ($routes): void {
            foreach ($routes as [$method, $path]) {
                $response = $this->ask($method, $path);
                self::assertSame([403, $message, $challenge ?? ''], [$response->getStatusCode(), $this->decode($response)['message'] ?? null, $response->getHeaderLine('WWW-Authenticate')], "{$method} {$path}");
            }
        };

        $this->bearer($this->customerSession($sara, ['method' => SignInMethod::Telegram]));
        $refused(StaffMiddleware::NOT_STAFF);

        $this->loginAsStaff($website, $sara);
        $website->forceFill(['staff_enabled' => false])->save();
        $refused(StaffMiddleware::STAFF_OFF);

        $website->forceFill(['staff_enabled' => true])->save();
        $this->bearer($this->customerSession($sara));
        $refused(StaffMiddleware::SIGN_IN_STRONGLY, StaffMiddleware::STRONG_CHALLENGE);

        $this->bearer($this->customerSession($sara, ['method' => SignInMethod::Telegram, 'authenticated_at' => now()->subHours(StaffMiddleware::SIGN_IN_HOURS)->subSecond()]));
        $refused(RecentSignInMiddleware::SIGN_IN_AGAIN, RecentSignInMiddleware::CHALLENGE);

        $this->loginAsStaff($website, $sara);
        $granted = array_values(array_filter($routes, static fn(array $route): bool => $route[2] !== null));
        self::assertCount(17, $granted, 'the operations a grant opens');
        foreach ($granted as [$method, $path]) {
            self::assertSame([403, StaffMiddleware::NOT_GRANTED], [$this->ask($method, $path)->getStatusCode(), $this->decode($this->ask($method, $path))['message'] ?? null], "{$method} {$path}");
        }

        $website->forceFill(['staff_grants' => array_map(static fn(StaffGrant $grant): string => $grant->value, StaffGrant::cases())])->save();
        $this->bearer($this->customerSession($sara, ['method' => SignInMethod::Telegram, 'authenticated_at' => now()->subSeconds(CustomerSessions::RECENT_SECONDS + 1)]));
        self::assertCount(18, array_filter($routes, static fn(array $route): bool => $route[3]), 'the operations a grant opens, and approving a payment');
        foreach ($routes as [$method, $path, , $recent]) {
            $response = $this->ask($method, $path);
            $asked = $response->getStatusCode() === 403 && $response->getHeaderLine('WWW-Authenticate') === RecentSignInMiddleware::CHALLENGE;
            self::assertSame($recent, $asked, "{$method} {$path}: a granted operation, and approving a payment, asks a recent sign-in — and only they do");
        }
    }

    /**
     * What is the shop's configuration — its payment methods, the bot's settings, texts and keyboards, its report group,
     * its website, who its admins are — and what one panel alone has is not on the website's admin API at all: every
     * route of the agents' panel the admin API does not have answers its admins a 404, whatever they were granted.
     */
    public function testTheShopsConfigurationIsNoAdminsOnTheWebsite(): void
    {
        $website = $this->website(['staff_grants' => array_map(static fn(StaffGrant $grant): string => $grant->value, StaffGrant::cases())]);
        $this->loginAsStaff($website, $this->customer(['telegram_id' => 7001]));
        $staff = array_map(static fn(array $route): string => "{$route[0]} " . substr($route[1], strlen("/api/store/v1/{$website->key}/admin")), $this->staffRoutes($website));

        $absent = 0;
        foreach ($this->routes('/api/agent') as [$method, $path]) {
            $relative = substr($path, strlen('/api/agent'));
            if (in_array("{$method} {$relative}", $staff, true)) {
                continue;
            }
            $absent++;
            self::assertSame(404, $this->ask($method, $this->storeApi($website, '/admin' . $relative))->getStatusCode(), "{$method} {$relative}");
        }
        self::assertGreaterThan(40, $absent);
    }

    public function testTheStoreApiAsksForNoCsrfHeader(): void
    {
        // A bearer token, never a cookie: what the header guards against cannot happen there.
        $website = $this->website();

        foreach ($this->routes(self::STORE, $website->key) as [$method, $path]) {
            if (in_array($method, self::STATE_CHANGING, true)) {
                self::assertNotSame(403, $this->ask($method, $path, csrfHeader: false)->getStatusCode(), "{$method} {$path}");
            }
        }
    }

    public function testAShopInASubFolderIsGuardedUnderIt(): void
    {
        $container = $this->app()->container();
        $mountedAt = $container->get('http.base_path');
        $container->set('http.base_path', '/shop');
        try {
            $slim = HttpKernel::create($this->app());
        } finally {
            $container->set('http.base_path', $mountedAt);
        }
        $ask = static fn(string $method, string $path) => $slim->handle((new ServerRequestFactory())->createServerRequest($method, 'http://localhost' . $path)->withHeader('X-Requested-With', 'XMLHttpRequest'));

        self::assertSame(200, $ask('GET', '/shop/health')->getStatusCode());
        self::assertSame(401, $ask('GET', '/shop/api/admin/auth/me')->getStatusCode());
        self::assertSame(404, $ask('POST', '/shop/api/%69nstall/finish')->getStatusCode(), 'the installer is gone, however its address is spelled');
        self::assertSame(404, $ask('GET', '/shopping/health')->getStatusCode(), 'a prefix is a whole segment');
        self::assertSame('/shop/admin/', $ask('GET', '/shop/')->getHeaderLine('Location'), 'the root opens the panel under the sub-folder');
    }

    /**
     * A row of every kind ROW_KINDS names, in the main bot's shop: its id by its kind, and the ticket's first message's.
     *
     * @return array<string, string>
     */
    private function mainShopRows(): array
    {
        $this->fakePanel();
        $this->telegram()->on('getCustomEmojiStickers', static fn(): array => []);
        $server = $this->fakeServer();
        $customer = $this->customer(['telegram_id' => 7001]);
        $plan = $this->plan([], $server);
        $order = $this->purchaseOrder($customer, $plan, $server);
        $ticket = $this->ticket($customer);
        $this->service(CustomEmojis::class)->remember([['id' => '111', 'emoji' => '🔥']]);
        $broadcast = $this->service(BroadcastService::class)->start($this->admin(), ['message_id' => 5, 'mode' => BroadcastMode::Copy, 'audience' => Audience::ALL, 'pin' => false], 77);

        return [
            'broadcast' => (string) $broadcast->id,
            'category' => (string) $this->category()->id,
            'plan' => (string) $plan->id,
            'customer' => (string) $customer->id,
            'group' => (string) $this->customerGroup('VIP', [$customer])->id,
            'order' => (string) $order->id,
            'payment' => (string) $this->receipt($this->cardPayment($order, $this->cardMethod('کارت سفارش')))->id,
            'ticket' => (string) $ticket->id,
            'message' => (string) $ticket->messages()->value('id'),
            'review' => (string) $this->review($customer)->id,
            'subscription' => (string) $this->subscription($customer, $plan, $server, 'main_1')->id,
            'method' => (string) $this->cardMethod()->id,
            'channel' => (string) $this->channel(-1001234, 'کانال اصلی')->id,
            'emoji' => '111',
        ];
    }

    /**
     * The routes of the shop's screens that name a row, as requests under an API of them — an agent's panel's, unless
     * another route prefix is given (the websites' admin API's): [method, path] after the prefix, each `{id}` the row of
     * its kind (ROW_KINDS) and a ticket's `{message}` its first message.
     *
     * @param array<string, string> $rows
     * @return list<array{string, string}>
     */
    private function rowRoutes(array $rows, string $prefix = '/api/agent'): array
    {
        $routes = [];
        foreach ($this->app()->http()->getRouteCollector()->getRoutes() as $route) {
            /** @var RouteInterface $route */
            $pattern = $route->getPattern();
            if (!str_starts_with($pattern, $prefix . '/') || !str_contains(substr($pattern, strlen($prefix)), '{id')) {
                continue;
            }
            $template = (string) preg_replace(Urls::PLACEHOLDER, '{$1}', substr($pattern, strlen($prefix)));
            if (in_array($template, self::NOT_A_ROW, true)) {
                continue;
            }
            $kind = self::ROW_KINDS[strstr($template, '/{id}', true)] ?? self::fail("{$template} names a row of a kind the walk does not make: add it to ROW_KINDS");
            $path = strtr($template, ['{id}' => $rows[$kind], '{message}' => $rows['message']]);
            foreach ($route->getMethods() as $method) {
                $routes[] = [$method, $path];
            }
        }
        self::assertGreaterThan(30, count($routes));

        return $routes;
    }

    /**
     * The routes of the website's admin API as requests under its address: [method, path, the grant the route asks
     * (null: none), whether it asks a recent sign-in — granted, or marked so], each placeholder filled as routes() fills it.
     *
     * @return list<array{string, string, string|null, bool}>
     */
    private function staffRoutes(Website $website): array
    {
        $doors = [];
        foreach ($this->app()->http()->getRouteCollector()->getRoutes() as $route) {
            foreach ($route->getMethods() as $method) {
                $grant = $route->getArgument(StaffGrant::ARGUMENT);
                $doors["{$method} {$route->getPattern()}"] = [$grant, $grant !== null || $route->getArgument(StaffMiddleware::RECENT_SIGN_IN) !== null];
            }
        }

        $routes = [];
        foreach ($this->routes(Urls::STORE . '/admin', $website->key) as [$method, $path, $pattern]) {
            $routes[] = [$method, $path, ...$doors["{$method} {$pattern}"]];
        }
        self::assertGreaterThan(50, count($routes));

        return $routes;
    }

    /**
     * A walked route asked as no panel asks it — the stray field, so its request is held to the API description no more
     * (unchecked()), its answer still is —, with the CSRF header or without it.
     */
    private function ask(string $method, string $path, bool $csrfHeader = true): ResponseInterface
    {
        $this->unchecked();

        return $csrfHeader ? $this->json($method, $path, self::STRAY_BODY) : $this->send($method, $path, self::STRAY_BODY);
    }

    /**
     * The app's routes under `$prefix` as requests: [method, path, the route's pattern] with each placeholder filled by a
     * value its pattern takes (the guards answer before any handler reads it) — a Store API address under `$store`, a
     * store key no website has unless one is given.
     *
     * @return list<array{string, string, string}>
     */
    private function routes(string $prefix, ?string $store = null): array
    {
        $routes = [];
        foreach ($this->app()->http()->getRouteCollector()->getRoutes() as $route) {
            /** @var RouteInterface $route */
            $pattern = $route->getPattern();
            if ($prefix !== '' && !str_starts_with($pattern, $prefix . '/') && $pattern !== $prefix) {
                continue;
            }
            $path = (string) preg_replace_callback(Urls::PLACEHOLDER, static fn(array $placeholder): string => match (true) {
                ($placeholder[2] ?? '') === '' => 'x',
                $placeholder[1] === 'store' => $store ?? str_repeat('0', 24),
                str_contains($placeholder[2], '|') => explode('|', $placeholder[2])[0],
                str_starts_with($placeholder[2], '[0-9]') => '1',
                default => 'x',
            }, $pattern);
            foreach ($route->getMethods() as $method) {
                $routes[] = [$method, $path, $pattern];
            }
        }
        self::assertNotEmpty($routes, "no route under {$prefix}");

        return $routes;
    }
}
