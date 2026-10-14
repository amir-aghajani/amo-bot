<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\DTO\Expiry;
use App\Modules\Providers\Models\Server;
use App\Modules\Settings\Services\Settings;
use App\Modules\Store\Models\Website;
use App\Modules\Store\Services\CustomerSubscriptions;
use App\Modules\Subscriptions\Enums\ClaimRefusal;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ServiceClaims;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\Topic;
use App\Modules\Users\Models\User;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\Fakes\FakeProvider;
use Tests\HttpTestCase;

/**
 * «افزودن سرویس با لینک» on the website: a subscription link or a config adds its service to the customer's account, as
 * the website then shows it — no plan, renewed on one to pick; every refusal under `link`, the shop's flag, its limit.
 */
final class StoreServiceClaimsTest extends HttpTestCase
{
    private const LINK = 'https://fake.test/sub/abcd1234efgh5678';
    private const UUID = '0f8e2a4c-6b1d-4e3f-9a7b-5c2d1e0f9a8b';

    private Website $website;

    private User $ali;

    private Server $server;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-12 12:00:00');
        $this->telegram();
        $this->reportGroup();
        $this->website = $this->website();
        $this->server = $this->sellingServer('Berlin');
        $this->plan = $this->plan([], $this->server);
        $this->ali = $this->customer(['username' => 'ali']);
        $this->claimsOn();
        // A client support made on the panel by hand: 5 GB of its 50 used, 20 days left.
        FakeProvider::put($this->server, new ClientInfo(
            name: 'friend_7',
            enabled: true,
            downloadBytes: 5 * Traffic::GIGABYTE,
            totalBytes: 50 * Traffic::GIGABYTE,
            expiry: Expiry::at(now()->addDays(20)->toDateTimeImmutable()),
            subscriptionUrl: self::LINK,
            ipLimit: 2,
        ));
        FakeProvider::$credentials[$this->server->id]['friend_7'] = [self::UUID];
        $this->bearer($this->customerSession($this->ali));
    }

    public function testASubscriptionLinkAmongOtherWordsAddsItsServiceToTheirAccount(): void
    {
        $response = $this->claim('سلام، این لینک سرویس من است: https://other.example/sub/abcd1234efgh5678 ممنون');

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $claimed = Subscription::query()->sole();
        self::assertSame([$this->ali->id, null, '2026-10-12 12:00:00'], [(int) $claimed->user_id, $claimed->plan_id, $claimed->claimed_at?->toDateTimeString()]);

        $shown = $this->decode($response)['subscription'];
        self::assertSame(['subscription' => $shown], $this->decode($this->get($this->storeApi($this->website, "/subscriptions/{$claimed->id}"))), 'as the service is shown');
        self::assertSame(
            [$claimed->id, 'friend_7', '2026-10-12T12:00:00+00:00', null, ['id' => $this->server->id, 'name' => 'Berlin'], self::LINK, false],
            [$shown['id'], $shown['name'], $shown['claimed_at'], $shown['plan'], $shown['server'], $shown['link'], $shown['is_test']],
        );
        self::assertSame(['limit_bytes' => 50 * Traffic::GIGABYTE, 'used_bytes' => 5 * Traffic::GIGABYTE, 'remaining_bytes' => 45 * Traffic::GIGABYTE], $shown['traffic'], 'as its panel has it');
        self::assertSame([false, true], [$shown['renewable'], $shown['picks_plan']], 'no plan of its own: renewed on one picked among those sold on its server');

        $reports = ReportMessage::query()->where('topic', Topic::Purchases)->pluck('text')->all();
        self::assertCount(1, $reports, 'the report group hears of it');
        self::assertStringContainsString('friend_7', $reports[0]);
    }

    public function testAConfigOfTheServiceFindsItToo(): void
    {
        $response = $this->claim('vless://' . self::UUID . '@berlin.example:443?type=tcp&security=reality#friend');

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('friend_7', $this->decode($response)['subscription']['name']);
    }

    public function testEachRefusalIsSaidUnderTheLink(): void
    {
        $this->subscription($this->customer(['telegram_id' => 7272]), $this->plan, $this->server, 'reza_1');
        FakeProvider::put($this->server, new ClientInfo(name: 'reza_1', enabled: true, subscriptionUrl: 'https://fake.test/sub/rezatoken0001'));

        $this->assertRefused(422, ClaimRefusal::NotALink, $this->claim('سلام، سرویس من کار نمی‌کند'));
        $this->assertRefused(422, ClaimRefusal::NotFound, $this->claim('https://fake.test/sub/zzzz9999yyyy8888'));
        $this->assertRefused(409, ClaimRefusal::Taken, $this->claim('https://fake.test/sub/rezatoken0001'));
        self::assertSame(1, Subscription::query()->count(), 'nothing added');

        self::assertSame(201, $this->claim(self::LINK)->getStatusCode());
        $this->assertRefused(422, ClaimRefusal::Yours, $this->claim(self::LINK));
        self::assertSame(2, Subscription::query()->count());
    }

    public function testAPanelThatDidNotAnswerIsA502(): void
    {
        $this->server->forceFill(['last_error' => 'timeout', 'last_checked_at' => now()])->save();

        $this->assertRefused(502, ClaimRefusal::Unavailable, $this->claim(self::LINK));
    }

    public function testTheShopSaysWhetherItTakesServicesByTheirLinkAndRefusesWhileItDoesNot(): void
    {
        self::assertTrue($this->decode($this->get($this->storeApi($this->website)))['shop']['service_claims']);

        $this->claimsOn(false);

        self::assertFalse($this->decode($this->get($this->storeApi($this->website)))['shop']['service_claims']);
        $this->assertRefused(422, ClaimRefusal::Off, $this->claim(self::LINK));
        self::assertSame(0, Subscription::query()->count());
    }

    public function testAnAgentsShopTakesNone(): void
    {
        [$website, $token] = CurrentBot::run($this->agentBot(), function (): array {
            // An agent's shop has no such switch; a value stored for it all the same changes nothing.
            $this->service(Settings::class)->set('claims.enabled', true);

            return [$this->website(), $this->customerSession($this->customer(['telegram_id' => 8181]))];
        });
        $this->bearer($token);

        self::assertFalse($this->decode($this->get($this->storeApi($website)))['shop']['service_claims'], 'stored on in their shop, still none: the main shop alone');
        $response = $this->send('POST', $this->storeApi($website, '/subscriptions/claim'), ['link' => self::LINK]);
        $this->assertRefused(422, ClaimRefusal::Off, $response);
        self::assertSame(0, Subscription::acrossShops()->count());
    }

    public function testACustomerSendsTenLinksInTenMinutesAtMost(): void
    {
        for ($i = 0; $i < ServiceClaims::TRIES; ++$i) {
            $this->assertRefused(422, ClaimRefusal::NotFound, $this->claim("https://fake.test/sub/unknown-token-{$i}"));
        }

        $response = $this->claim(self::LINK);

        self::assertSame(429, $response->getStatusCode(), (string) $response->getBody());
        self::assertNotSame('', $response->getHeaderLine('Retry-After'));
        self::assertSame(0, Subscription::query()->count(), 'each link asks every panel: not one more');
    }

    public function testATextTooLongForALinkIsRefused(): void
    {
        $response = $this->unchecked()->claim(self::LINK . ' ' . str_repeat('خ', CustomerSubscriptions::LINK_MAX));

        $this->assertRefusedUnder('link', CustomerSubscriptions::LINK_TOO_LONG, $response);
        self::assertSame(0, Subscription::query()->count());
    }

    private function claim(string $link): ResponseInterface
    {
        return $this->send('POST', $this->storeApi($this->website, '/subscriptions/claim'), ['link' => $link]);
    }

    /** The claim refused for `$reason` — its words under `link` —, with that status. */
    private function assertRefused(int $status, ClaimRefusal $reason, ResponseInterface $response): void
    {
        $this->assertRefusedUnder('link', $reason->message(), $response, $status);
    }
}
