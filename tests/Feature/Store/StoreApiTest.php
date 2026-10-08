<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Accounts\Http\CustomerAuthMiddleware;
use App\Modules\Agency\Services\AgencyActions;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Store\Http\StoreMiddleware;
use App\Modules\Store\Models\Website;
use App\Modules\Telegram\BotSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\HttpTestCase;

/**
 * The Store API's door: its address's store key names a shop — the main bot's or an agent's — whose website is on, and
 * the request is worked in that shop; any other key is a 404 in the error shape. `GET /` introduces the shop. A page on
 * the website's own origin, or one it lists, may call it from a browser (CORS): a preflight is answered before routing,
 * and every answer — an error's too — says the origin may read it; any other origin, or a request without one (a
 * server's), gets nothing of the kind, and the panels' API answers no origin at all.
 */
final class StoreApiTest extends HttpTestCase
{
    public function testTheShopIsIntroducedAsItsWebsiteShowsIt(): void
    {
        $this->config(['app.name' => 'آب‌سردکن', 'telegram.username' => 'amo_shop_bot']);
        $this->botSettings('general', ['enabled' => true, 'phone_required' => false, 'support_contact' => '@amo_support']);
        $this->referralProgram(rate: 15, firstOnly: true);
        $website = $this->website();

        self::assertSame([
            'shop' => ['name' => 'آب‌سردکن', 'bot' => ['username' => 'amo_shop_bot', 'url' => 'https://t.me/amo_shop_bot'], 'taking_orders' => true],
            'support' => ['url' => 'https://t.me/amo_support'],
            'sign_in' => ['telegram' => ['client_id' => self::WEBSITE_CLIENT_ID, 'redirect' => false], 'google' => null, 'email' => false],
            'captcha' => null,
            'referral' => ['enabled' => true, 'rate' => 15, 'first_only' => true],
        ], $this->decode($this->get($this->storeApi($website))));

        $website->forceFill(['telegram_client_secret' => 'tg-secret-1'])->save();
        self::assertTrue($this->decode($this->get($this->storeApi($website)))['sign_in']['telegram']['redirect'], 'with a secret, the redirect flow too');
        $website->forceFill(['telegram_login' => false])->save();
        self::assertNull($this->decode($this->get($this->storeApi($website)))['sign_in']['telegram'], 'switched off, no Telegram sign-in');

        $this->botSettings('general', ['enabled' => false, 'phone_required' => false, 'support_contact' => '@amo_support']);
        self::assertFalse($this->decode($this->get($this->storeApi($website)))['shop']['taking_orders'], 'the bot switched off: no orders taken');
    }

    public function testTheWaysInAreThoseTheWebsiteSetUp(): void
    {
        $website = $this->website(['email_signup' => true, 'google_client_id' => self::GOOGLE_CLIENT_ID]);
        $shop = fn(): array => $this->decode($this->get($this->storeApi($website)));

        self::assertSame(['google' => ['client_id' => self::GOOGLE_CLIENT_ID], 'email' => false], array_slice($shop()['sign_in'], 1), 'no email goes out: no email sign-up to offer');
        self::assertNull($shop()['captcha'], 'no captcha asked');

        $this->mail();
        $website->forceFill(['captcha_driver' => 'turnstile', 'captcha_config' => ['site_key' => '0x4AAAAAAAsitekey', 'secret_key' => '0x4AAAAAAAsecret']])->save();
        self::assertSame(['google' => ['client_id' => self::GOOGLE_CLIENT_ID], 'email' => true], array_slice($shop()['sign_in'], 1));
        self::assertSame(['driver' => 'turnstile', 'site_key' => '0x4AAAAAAAsitekey', 'challenge_url' => null], $shop()['captcha'], "Turnstile's widget, drawn with its site key — never its secret");

        $website->forceFill(['captcha_driver' => 'altcha', 'captcha_config' => null])->save();
        self::assertSame(['driver' => 'altcha', 'site_key' => null, 'challenge_url' => "http://localhost/api/store/v1/{$website->key}/captcha/challenge"], $shop()['captcha'], "ALTCHA's widget asks the shop for its challenge");

        $website->forceFill(['email_signup' => false, 'google_client_id' => null])->save();
        self::assertSame(['google' => null, 'email' => false], array_slice($shop()['sign_in'], 1, 2), 'switched off');
    }

    public function testAnAgentsWebsiteIsTheirShop(): void
    {
        $this->config(['app.name' => 'آب‌سردکن']);
        $bot = $this->agentBot();
        $website = CurrentBot::run($bot, function (): Website {
            $this->botSettings('general', ['enabled' => true, 'phone_required' => false, 'support_contact' => '']);

            return $this->website();
        });

        $shop = $this->decode($this->get($this->storeApi($website)));

        self::assertSame(['name' => 'فروشگاه نماینده', 'bot' => ['username' => 'agent_shop_bot', 'url' => 'https://t.me/agent_shop_bot'], 'taking_orders' => true], $shop['shop']);
        self::assertNull($shop['support'], 'no contact set in their shop');
    }

    public function testAKeyThatOpensNoWebsiteIsA404WhateverTheAddressUnderIt(): void
    {
        $this->telegram();
        $off = $this->website(['enabled' => false]);
        $bot = $this->agentBot();
        $agents = CurrentBot::run($bot, fn(): Website => $this->website());
        self::assertSame(200, $this->get($this->storeApi($agents))->getStatusCode());
        // Their agency ends: their bot stops, and their website with it, as their panel does.
        $this->service(AgencyActions::class)->revoke($bot->agent ?? self::fail('No agent.'), null);

        foreach ([str_repeat('0', 24), $off->key, $agents->key] as $key) {
            foreach (['', '/auth/nonce'] as $path) {
                $response = $path === '' ? $this->get("/api/store/v1/{$key}") : $this->postJson("/api/store/v1/{$key}{$path}");

                self::assertSame([404, StoreMiddleware::CLOSED], [$response->getStatusCode(), $this->decode($response)['message']], "{$key}{$path}");
            }
        }
        self::assertSame(404, $this->unchecked()->get('/api/store/v1/NOT-A-KEY')->getStatusCode(), 'no store key at all: no route');
    }

    /** @return array<string, array{string, string|null}> What the bot's «پشتیبانی» says, and the link a page opens for it */
    public static function contacts(): array
    {
        return [
            'a handle' => ['@amo_support', 'https://t.me/amo_support'],
            'a web address' => ['https://amo.example/support?from=site', 'https://amo.example/support?from=site'],
            'a t.me address without its scheme' => ['t.me/amo_support', 'https://t.me/amo_support'],
            'a phone number, in Persian digits' => ['+۹۸ ۹۱۲ ۳۴۵ ۶۷۸۹', 'tel:+989123456789'],
            'words' => ['از طریق ربات پیام بدهید', null],
            'a bare name' => ['amo_support', null],
            'nothing' => ['', null],
        ];
    }

    #[DataProvider('contacts')]
    public function testSupportIsALinkWhenTheContactCanBeOne(string $contact, ?string $link): void
    {
        $this->botSettings('general', ['enabled' => true, 'phone_required' => false, 'support_contact' => $contact]);

        self::assertSame($link, $this->service(BotSettings::class)->supportUrl());
    }

    public function testAPageOnTheWebsitesOriginsMayReadEveryAnswer(): void
    {
        $website = $this->website();

        foreach (['https://shop.example', 'http://localhost:3000'] as $origin) {
            $answer = $this->get($this->storeApi($website), ['Origin' => $origin]);
            self::assertSame(200, $answer->getStatusCode());
            self::assertAllowed($answer, $origin);
        }

        // An error answer too: a customer's own without a token, an address the router has nothing at.
        $signedOut = $this->unchecked()->get($this->storeApi($website, '/me'), ['Origin' => 'https://shop.example']);
        self::assertSame([401, CustomerAuthMiddleware::SIGNED_OUT], [$signedOut->getStatusCode(), $this->decode($signedOut)['message']]);
        self::assertAllowed($signedOut, 'https://shop.example');
        $nowhere = $this->get($this->storeApi($website, '/nowhere'), ['Origin' => 'https://shop.example']);
        self::assertSame(404, $nowhere->getStatusCode());
        self::assertAllowed($nowhere, 'https://shop.example');
    }

    public function testAPreflightIsAnsweredBeforeRouting(): void
    {
        $website = $this->website();

        $preflight = $this->raw('OPTIONS', $this->storeApi($website, '/auth/telegram'), ['Origin' => 'http://localhost:3000', 'Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'content-type']);

        self::assertSame(204, $preflight->getStatusCode());
        self::assertSame('http://localhost:3000', $preflight->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('GET, POST, PUT, PATCH, DELETE', $preflight->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertSame('Authorization, Content-Type, Idempotency-Key', $preflight->getHeaderLine('Access-Control-Allow-Headers'));
        self::assertSame('600', $preflight->getHeaderLine('Access-Control-Max-Age'));
        self::assertSame('Origin', $preflight->getHeaderLine('Vary'));
        self::assertFalse($preflight->hasHeader('Access-Control-Allow-Credentials'), 'a bearer token, never a cookie');
        self::assertNotSame('', $preflight->getHeaderLine('X-Request-Id'), 'an answer like any other');
    }

    public function testAnOriginTheWebsiteDoesNotHaveIsToldNothing(): void
    {
        $website = $this->website();
        CurrentBot::run($this->agentBot(), fn(): Website => $this->website(['url' => 'https://agent.example']));

        foreach (['https://evil.example', 'https://shop.example.evil.example', 'http://shop.example', 'https://agent.example', 'null'] as $origin) {
            self::assertFalse($this->get($this->storeApi($website), ['Origin' => $origin])->hasHeader('Access-Control-Allow-Origin'), $origin);
            self::assertNotPreflighted($this->raw('OPTIONS', $this->storeApi($website, '/me'), ['Origin' => $origin, 'Access-Control-Request-Method' => 'GET']), $origin);
        }
        self::assertFalse($this->get($this->storeApi($website))->hasHeader('Access-Control-Allow-Origin'), "a server's request, without an Origin: none of CORS's business");

        $website->forceFill(['enabled' => false])->save();
        self::assertFalse($this->get($this->storeApi($website), ['Origin' => 'https://shop.example'])->hasHeader('Access-Control-Allow-Origin'), 'a website switched off allows none');
    }

    public function testThePanelsApiAnswersNoOtherOrigin(): void
    {
        $this->website();
        $this->loginAsAdmin();

        $answer = $this->get('/api/admin/auth/me', ['Origin' => 'https://shop.example']);

        self::assertSame(200, $answer->getStatusCode());
        self::assertFalse($answer->hasHeader('Access-Control-Allow-Origin'));
        self::assertNotPreflighted($this->raw('OPTIONS', '/api/admin/users', ['Origin' => 'https://shop.example', 'Access-Control-Request-Method' => 'DELETE']), 'a panel');
    }

    public function testAnAgentsWebsiteRunsInTheirShop(): void
    {
        $bot = $this->agentBot();
        $customer = CurrentBot::run($bot, fn() => $this->customer(['telegram_id' => 9191, 'first_name' => 'Sara']));
        $website = CurrentBot::run($bot, fn(): Website => $this->website());
        $this->customer(['telegram_id' => 9191, 'first_name' => 'Main']);
        $this->bearer(CurrentBot::run($bot, fn(): string => $this->customerSession($customer)));

        $me = $this->decode($this->get($this->storeApi($website, '/me')))['customer'];

        self::assertSame([$customer->id, 'Sara'], [$me['id'], $me['first_name']], "the agent's shop's customer, not the main bot's of the same Telegram account");
        self::assertSame(Bot::MAIN, CurrentBot::id(), 'and back where the request found the process');
    }

    private static function assertAllowed(ResponseInterface $answer, string $origin): void
    {
        self::assertSame($origin, $answer->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('Origin', $answer->getHeaderLine('Vary'));
        self::assertSame('X-Request-Id, Retry-After, WWW-Authenticate', $answer->getHeaderLine('Access-Control-Expose-Headers'), 'what a 401 or a 403 asks of the token too');
    }

    /** A preflight nothing allowed: whatever the router makes of it, no CORS header — the browser sends nothing after it. */
    private static function assertNotPreflighted(ResponseInterface $answer, string $what): void
    {
        self::assertNotSame(204, $answer->getStatusCode(), $what);
        self::assertFalse($answer->hasHeader('Access-Control-Allow-Origin'), $what);
        self::assertFalse($answer->hasHeader('Access-Control-Allow-Methods'), $what);
    }

    /**
     * A request straight into the app — a preflight is no operation of the API's description, so it is not held to it.
     *
     * @param array<string, string> $headers
     */
    private function raw(string $method, string $path, array $headers): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost' . $path);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->app()->http()->handle($request);
    }
}
