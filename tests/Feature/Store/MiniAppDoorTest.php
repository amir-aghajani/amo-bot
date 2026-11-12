<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Core\Http\ErrorHandler;
use App\Core\Http\Urls;
use App\Modules\Accounts\Enums\SignInMethod;
use App\Modules\Bots\CurrentBot;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Exceptions\SignInToReviewException;
use App\Modules\Store\Api\IdentitiesController;
use App\Modules\Store\Exceptions\ReviewsOffException;
use App\Modules\Store\Http\StaffMiddleware;
use App\Modules\Store\Http\StoreMiddleware;
use App\Modules\Store\Models\MiniApp;
use App\Modules\Store\Models\Website;
use App\Modules\Users\Enums\UserRole;
use App\Support\LocalTime;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\HttpTestCase;
use Tests\Support\ApiDescription;

/**
 * The Store API's two doors: a shop's website and its Telegram mini app, each under a key of its own, open the same API —
 * but for what only one of them has (the operations `x-door` marks), which under the other's key is the router's own 404
 * before anything else is asked. Through the mini app `GET /` says how it signs in and how it looks, the reviews follow
 * its own switch, no other origin may call it and no admin API is there — nor through the website for a session the mini
 * app signed in; a mini app switched off, or of an agent whose agency ended, opens nothing.
 */
final class MiniAppDoorTest extends HttpTestCase
{
    /** What the walk asks every route with: a field no operation takes — the guards answer before anything reads a body. */
    private const STRAY_BODY = ['stray' => true];

    private Website $website;

    private MiniApp $miniApp;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-27 12:00:00');
        $this->telegram();
        $this->website = $this->website();
        $this->miniApp = $this->miniApp();
    }

    protected function tearDown(): void
    {
        LocalTime::use('UTC');

        parent::tearDown();
    }

    public function testTheTelegramAccountIsNotTakenOffThroughTheMiniApp(): void
    {
        $customer = $this->customer(['email' => self::WEB_EMAIL, 'password_hash' => password_hash(self::WEB_PASSWORD, PASSWORD_BCRYPT, ['cost' => 4])]);
        $this->bearer($this->customerSession($customer));

        $refused = $this->send('DELETE', $this->storeApi($this->miniApp, '/me/identities/telegram'));
        self::assertSame([422, [IdentitiesController::TELEGRAM_STAYS]], [$refused->getStatusCode(), $this->decode($refused)['errors']['kind']], 'the app and the bot know them by it');
        self::assertSame(self::TELEGRAM_ID, $customer->refresh()->telegram_id);

        $removed = $this->send('DELETE', $this->storeApi($this->website, '/me/identities/telegram'));
        self::assertSame(200, $removed->getStatusCode(), 'the website takes it off');
        self::assertNull($customer->refresh()->telegram_id);
    }

    /**
     * Every route of the Store API asked under each door's key, as nobody signed in: what one door alone has is the
     * router's 404 under the other's — before a sign-in or a recent one is asked, as if it were not there — and exactly
     * the operations the description marks `x-door` for it; the website's admin API is not under the mini app's key at all.
     */
    public function testWhatOneDoorAloneHasIsNoRouteThroughTheOther(): void
    {
        $absent = ['website' => [], 'mini_app' => []];
        $admin = 0;
        foreach ($this->storeRoutes() as [$method, $template, $path]) {
            $throughWebsite = $this->ask($method, $this->storeApi($this->website, $path));
            $throughMiniApp = $this->ask($method, $this->storeApi($this->miniApp, $path));
            if (str_starts_with($template, '/admin/')) {
                $admin++;
                self::assertSame([404, ErrorHandler::MESSAGES[404]], self::answer($throughMiniApp), "{$method} {$template} under the mini app's key");

                continue;
            }
            if (self::absent($throughMiniApp)) {
                $absent['website'][] = "{$method} {$template}";
            }
            if (self::absent($throughWebsite)) {
                $absent['mini_app'][] = "{$method} {$template}";
            }
        }

        $marked = self::marked();
        self::assertGreaterThan(50, $admin);
        self::assertCount(20, $marked['website'], "the website's ways in, its captcha, a signed-in customer's Telegram, Google and mobile");
        self::assertSame(['DELETE /me/biometric', 'GET /app/logo', 'POST /auth/mini-app', 'PUT /me/biometric'], $marked['mini_app'], 'its sign-in, its logo, its fingerprint secret');
        foreach ($absent as $door => $routes) {
            sort($routes);
            self::assertSame($marked[$door], $routes, "the routes no route under the other door's key are those x-door: {$door} marks");
        }
    }

    public function testGetSlashSaysWhichDoorItIsAndHowTheMiniAppLooks(): void
    {
        $throughWebsite = $this->decode($this->get($this->storeApi($this->website)));
        self::assertSame(['telegram' => ['client_id' => self::WEBSITE_CLIENT_ID, 'redirect' => false], 'google' => null, 'email' => false, 'phone' => false, 'mini_app' => false], $throughWebsite['sign_in']);
        self::assertNull($throughWebsite['app'], 'no mini app look through a website');

        $plain = $this->decode($this->get($this->storeApi($this->miniApp)));
        self::assertSame(['telegram' => null, 'google' => null, 'email' => false, 'phone' => false, 'mini_app' => true], $plain['sign_in'], "the launch data alone: the website's ways in are not the mini app's");
        self::assertNull($plain['captcha']);
        self::assertSame(['title' => $plain['shop']['name'], 'accent_color' => null, 'logo_url' => null, 'announcement' => null, 'fullscreen' => true, 'time_zone' => 'UTC'], $plain['app'], "the shop's own name while it sets none");
        self::assertSame(array_diff_key($throughWebsite, ['sign_in' => 0, 'captcha' => 0, 'app' => 0]), array_diff_key($plain, ['sign_in' => 0, 'captcha' => 0, 'app' => 0]), 'the same shop through either door');
        self::assertSame(['test_accounts', 'wheel', 'lottery', 'gift_codes', 'loyalty', 'cashback', 'wallet_transfers', 'reviews'], array_keys($plain['shop']['programs']));

        // The reviews are each door's own switch.
        $this->website->forceFill(['reviews_enabled' => true])->save();
        self::assertSame([true, false], [
            $this->decode($this->get($this->storeApi($this->website)))['shop']['programs']['reviews'],
            $this->decode($this->get($this->storeApi($this->miniApp)))['shop']['programs']['reviews'],
        ]);
        $this->website->forceFill(['reviews_enabled' => false])->save();

        $this->miniApp->forceFill([
            'title' => 'فروشگاه آبی',
            'accent_color' => '#2481cc',
            'logo_path' => 'mini-app-1.png',
            'announcement' => "تخفیف ویژه\nفقط امروز",
            'announcement_url' => 'https://t.me/amo_channel',
            'fullscreen' => false,
        ])->save();
        LocalTime::use('Asia/Tehran');

        self::assertSame([
            'title' => 'فروشگاه آبی',
            'accent_color' => '#2481cc',
            'logo_url' => "http://localhost/api/store/v1/{$this->miniApp->key}/app/logo?v=" . now()->getTimestamp(),
            'announcement' => ['text' => "تخفیف ویژه\nفقط امروز", 'url' => 'https://t.me/amo_channel'],
            'fullscreen' => false,
            'time_zone' => 'Asia/Tehran',
        ], $this->decode($this->get($this->storeApi($this->miniApp)))['app']);
    }

    public function testTheMiniAppShowsTheReviewsByItsOwnSwitch(): void
    {
        $this->review(overrides: ['status' => ReviewStatus::Approved]);
        $this->website->forceFill(['reviews_enabled' => true])->save();

        $off = $this->get($this->storeApi($this->miniApp, '/reviews'));
        self::assertSame([404, ReviewsOffException::MESSAGE], self::answer($off), "the website's switch is not the mini app's");

        $this->miniApp->forceFill(['reviews_enabled' => true])->save();
        $this->website->forceFill(['reviews_enabled' => false])->save();
        self::assertSame(200, $this->get($this->storeApi($this->miniApp, '/reviews'))->getStatusCode());
        self::assertSame(404, $this->get($this->storeApi($this->website, '/reviews'))->getStatusCode());

        $review = ['name' => 'Ali R.', 'rating' => 5, 'body' => 'سرعت عالی و پشتیبانی خوب.'];
        self::assertSame([403, SignInToReviewException::MESSAGE], self::answer($this->postJson($this->storeApi($this->miniApp, '/reviews'), $review)), 'no captcha there: a guest signs in first');
        $this->bearer($this->customerSession($this->customer(), ['method' => SignInMethod::MiniApp]));
        self::assertSame(201, $this->postJson($this->storeApi($this->miniApp, '/reviews'), $review)->getStatusCode(), "a customer's, waiting on support");
    }

    /** The mini app is on the shop's own address: no page anywhere — the website's, or one on the shop's address itself — gets CORS under its key. */
    public function testNoOriginMayCallTheApiUnderTheMiniAppsKey(): void
    {
        foreach (['https://shop.example', 'http://localhost:3000', 'http://localhost', 'https://web.telegram.org'] as $origin) {
            $read = $this->get($this->storeApi($this->miniApp), ['Origin' => $origin]);
            self::assertSame(200, $read->getStatusCode());
            self::assertSame('', $read->getHeaderLine('Access-Control-Allow-Origin'), $origin);

            $preflight = $this->preflight($this->storeApi($this->miniApp, '/auth/mini-app'), $origin);
            self::assertNotSame(204, $preflight->getStatusCode(), "{$origin}: no preflight answered");
            self::assertFalse($preflight->hasHeader('Access-Control-Allow-Origin'), $origin);
            self::assertFalse($preflight->hasHeader('Access-Control-Allow-Methods'), $origin);
        }

        self::assertSame('https://shop.example', $this->get($this->storeApi($this->website), ['Origin' => 'https://shop.example'])->getHeaderLine('Access-Control-Allow-Origin'), "the website's own origin, under its key");
    }

    public function testAMiniAppSwitchedOffOrOfAnAgencyThatEndedOpensNothing(): void
    {
        $this->miniApp->forceFill(['enabled' => false])->save();
        self::assertSame([404, StoreMiddleware::CLOSED], self::answer($this->get($this->storeApi($this->miniApp))));
        self::assertSame([404, StoreMiddleware::CLOSED], self::answer($this->postJson($this->storeApi($this->miniApp, '/auth/mini-app'), ['init_data' => $this->launchData()])));

        $bot = $this->agentBot();
        $agents = CurrentBot::run($bot, fn(): MiniApp => $this->miniApp());
        self::assertSame([200, 'فروشگاه نماینده'], [$this->get($this->storeApi($agents))->getStatusCode(), $this->decode($this->get($this->storeApi($agents)))['shop']['name'] ?? null], "the agent's shop");

        $bot->agent?->forceFill(['agency_level_id' => null])->save();
        self::assertSame([404, StoreMiddleware::CLOSED], self::answer($this->get($this->storeApi($agents))), 'closed with their panel');
    }

    public function testAnAdminOfTheShopHasNoAdminApiThroughTheMiniApp(): void
    {
        $admin = $this->customer(['role' => UserRole::Admin]);
        $this->website->forceFill(['staff_enabled' => true])->save();
        $this->bearer($this->customerSession($admin, ['method' => SignInMethod::Telegram]));

        self::assertNotNull($this->decode($this->get($this->storeApi($this->website, '/me')))['staff'], 'on the website, what its admin API lets them do');
        self::assertNull($this->decode($this->get($this->storeApi($this->miniApp, '/me')))['staff'], 'the same session through the mini app: nothing of the kind');
        self::assertSame(404, $this->get($this->storeApi($this->miniApp, '/admin/orders'))->getStatusCode());
        self::assertSame(200, $this->get($this->storeApi($this->website, '/admin/orders'))->getStatusCode());
    }

    /**
     * The mini app is the customer side alone: a session it signed in works no admin API through the website's key either
     * — nor does a password alone its launch data made strong —, whatever is proven on it since; `GET /me` offers none.
     */
    public function testASessionTheMiniAppSignedInWorksNoAdminApiThroughTheWebsite(): void
    {
        $admin = $this->customer(['role' => UserRole::Admin, 'email' => self::WEB_EMAIL, 'password_hash' => password_hash(self::WEB_PASSWORD, PASSWORD_BCRYPT, ['cost' => 4])]);
        $this->website->forceFill(['staff_enabled' => true, 'staff_strong_sign_in' => false])->save();
        $orders = fn(): array => self::answer($this->get($this->storeApi($this->website, '/admin/orders')));

        $signedIn = $this->postJson($this->storeApi($this->miniApp, '/auth/mini-app'), ['init_data' => $this->launchData()]);
        $this->bearer($this->decode($signedIn)['token']);
        self::assertSame([403, StaffMiddleware::MINI_APP_SESSION], $orders(), 'signed in this minute, by the admin themselves');
        self::assertNull($this->decode($this->get($this->storeApi($this->website, '/me')))['staff'], 'nothing for the site to offer');
        self::assertSame(200, $this->postJson($this->storeApi($this->website, '/me/reauthenticate'), ['method' => 'password', 'password' => self::WEB_PASSWORD])->getStatusCode());
        self::assertSame([403, StaffMiddleware::MINI_APP_SESSION], $orders(), 'a way proven since changes nothing');

        $this->bearer($this->customerSession($admin, ['authenticated_at' => now()->subHour()]));
        self::assertSame(200, $this->get($this->storeApi($this->website, '/admin/orders'))->getStatusCode(), 'a password alone, while no strong sign-in is asked');
        self::assertSame(200, $this->postJson($this->storeApi($this->miniApp, '/me/reauthenticate'), ['method' => 'mini_app', 'init_data' => $this->launchData()])->getStatusCode());
        self::assertSame([403, StaffMiddleware::MINI_APP_SESSION], $orders(), "made strong by the launch data: its way in is the mini app's now");

        $this->bearer($this->customerSession($admin, ['method' => SignInMethod::Telegram]));
        self::assertSame(200, $this->get($this->storeApi($this->website, '/admin/orders'))->getStatusCode(), 'a session the website signed in');
    }

    /**
     * The Store API's routes as requests under a door's base address: [method, the route's path as the description has
     * it, a path to ask], each placeholder filled with a value its pattern takes (the guards answer before a handler
     * reads it).
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
        self::assertGreaterThan(100, count($routes));

        return $routes;
    }

    /**
     * The operations resources/api/openapi.yaml marks `x-door`, by door: `METHOD /path` under the store's base address.
     *
     * @return array{website: list<string>, mini_app: list<string>}
     */
    private static function marked(): array
    {
        $marked = ['website' => [], 'mini_app' => []];
        foreach (ApiDescription::read()['paths'] as $path => $item) {
            foreach ($item as $method => $operation) {
                $door = is_array($operation) ? $operation['x-door'] ?? null : null;
                if ($door !== null) {
                    $marked[$door][] = strtoupper($method) . ' ' . substr($path, strlen('/api/store/v1/{store}'));
                }
            }
        }
        sort($marked['website']);
        sort($marked['mini_app']);

        return $marked;
    }

    /** A walked route asked as nobody signed in, with the stray field — so its request is held to the description no more. */
    private function ask(string $method, string $path): ResponseInterface
    {
        return $this->unchecked()->json($method, $path, self::STRAY_BODY);
    }

    /** A browser's preflight of a POST from a page on `$origin`, straight into the app: no operation of the description. */
    private function preflight(string $path, string $origin): ResponseInterface
    {
        return $this->app()->http()->handle((new ServerRequestFactory())->createServerRequest('OPTIONS', 'http://localhost' . $path)
            ->withHeader('Origin', $origin)
            ->withHeader('Access-Control-Request-Method', 'POST'));
    }

    /** Whether the answer is the router's own 404: no route there. */
    private static function absent(ResponseInterface $response): bool
    {
        return self::answer($response) === [404, ErrorHandler::MESSAGES[404]];
    }

    /** @return array{int, mixed} The answer's status and its message */
    private static function answer(ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true);

        return [$response->getStatusCode(), is_array($body) ? $body['message'] ?? null : null];
    }
}
