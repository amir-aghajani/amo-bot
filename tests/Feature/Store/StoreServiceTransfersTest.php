<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Accounts\Http\RecentSignInMiddleware;
use App\Modules\Accounts\Services\CustomerSessions;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Providers\Models\Server;
use App\Modules\Store\Models\Website;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ServiceTransfers;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\Topic;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\HttpTestCase;

/**
 * «انتقال سرویس» on the website: whether the shop offers it (`GET /`), who an id names before it is done, and the service
 * handed over — the answer is who received it, the service is theirs (the giver's 404), the recipient told, the report
 * group's line. Every refusal under its field; another customer's service a 404; lookups limited; handing over asks a
 * recent sign-in.
 */
final class StoreServiceTransfersTest extends HttpTestCase
{
    private const SARA = 7070;

    private Website $website;
    private User $ali;
    private User $sara;
    private Server $server;
    private Plan $plan;
    private Subscription $service;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-13 12:00:00');
        $this->telegram();
        $this->reportGroup();
        $this->website = $this->website();
        $this->server = $this->sellingServer('Berlin');
        $this->plan = $this->plan([], $this->server);
        $this->ali = $this->customer(['username' => 'ali']);
        $this->sara = $this->customer(['telegram_id' => self::SARA, 'first_name' => 'Sara', 'username' => 'sara']);
        $this->service = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['auto_renew' => true]));
        $this->bearer($this->customerSession($this->ali));
    }

    public function testTheShopSaysWhetherItsCustomersMayTransfer(): void
    {
        self::assertFalse($this->decode($this->get($this->storeApi($this->website)))['shop']['service_transfers'], 'off by default');

        $this->transfersOn();
        self::assertTrue($this->decode($this->get($this->storeApi($this->website)))['shop']['service_transfers']);
    }

    public function testWhoAnIdNamesIsShownBeforeAnythingIsDone(): void
    {
        $this->transfersOn();

        $response = $this->get($this->storeApi($this->website, "/subscriptions/{$this->service->id}/transfer?telegram_id=" . urlencode('۷۰۷۰')));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['recipient' => ['name' => 'Sara', 'username' => 'sara']], $this->decode($response));
        self::assertSame($this->ali->id, $this->service->fresh()?->user_id, 'nothing handed over');
        self::assertSame(0, ReportMessage::query()->count());
    }

    public function testTheServiceIsHandedOverAndTheAnswerIsWhoReceivedIt(): void
    {
        $this->transfersOn();

        $response = $this->transfer(['telegram_id' => self::SARA]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['recipient' => ['name' => 'Sara', 'username' => 'sara']], $this->decode($response));
        $row = $this->service->fresh();
        self::assertSame([$this->sara->id, false], [$row?->user_id, $row?->auto_renew]);
        self::assertSame(404, $this->get($this->storeApi($this->website, "/subscriptions/{$this->service->id}"))->getStatusCode(), 'not theirs any more');
        self::assertCount(1, $this->telegram()->sentTo(self::SARA), 'the recipient told in the bot');
        self::assertSame([], $this->telegram()->sentTo(self::TELEGRAM_ID), 'the giver has the answer');
        self::assertStringContainsString('✋ به درخواست خود مشتری', (string) ReportMessage::query()->where('topic', Topic::Purchases->value)->value('text'));

        $this->bearer($this->customerSession($this->sara));
        $mine = $this->decode($this->get($this->storeApi($this->website, '/subscriptions')))['subscriptions'];
        self::assertSame(['ali_1'], array_column($mine, 'name'), 'in the recipient\'s list now');
    }

    public function testEachRefusalIsUnderItsField(): void
    {
        $this->assertRefusedUnder('status', ServiceTransfers::OFF, $this->transfer(['telegram_id' => self::SARA]));
        $this->assertRefusedUnder('status', ServiceTransfers::OFF, $this->get($this->storeApi($this->website, "/subscriptions/{$this->service->id}/transfer?telegram_id=7070")));

        $this->transfersOn();
        $this->assertRefusedUnder('telegram_id', ServiceTransfers::NOT_AN_ID, $this->transfer(['telegram_id' => '@sara']));
        $this->assertRefusedUnder('telegram_id', ServiceTransfers::UNKNOWN, $this->transfer(['telegram_id' => '9999']));
        $this->assertRefusedUnder('telegram_id', ServiceTransfers::SAME, $this->transfer(['telegram_id' => self::TELEGRAM_ID]));

        $this->service(OrderService::class)->createRenewal($this->ali, $this->service, $this->plan);
        $this->assertRefusedUnder('status', ServiceTransfers::OPEN_ORDER, $this->transfer(['telegram_id' => self::SARA]));
        self::assertSame($this->ali->id, $this->service->fresh()?->user_id);
    }

    /** A leaked token alone gives no service away: handing one over asks a recent sign-in, the look-up does not. */
    public function testHandingAServiceOverAsksARecentSignIn(): void
    {
        $this->transfersOn();
        $this->bearer($this->customerSession($this->ali, ['authenticated_at' => now()->subSeconds(CustomerSessions::RECENT_SECONDS + 1)]));

        self::assertSame(200, $this->get($this->storeApi($this->website, "/subscriptions/{$this->service->id}/transfer?telegram_id=7070"))->getStatusCode(), 'who an id names, still');
        $refused = $this->transfer(['telegram_id' => self::SARA]);

        self::assertSame([403, RecentSignInMiddleware::SIGN_IN_AGAIN], [$refused->getStatusCode(), $this->decode($refused)['message']]);
        self::assertSame(RecentSignInMiddleware::CHALLENGE, $refused->getHeaderLine('WWW-Authenticate'));
        self::assertSame($this->ali->id, $this->service->fresh()?->user_id, 'nothing handed over');
        self::assertSame([], $this->telegram()->sentTo(self::SARA));
    }

    public function testAnotherCustomersServiceIsNotFound(): void
    {
        $this->transfersOn();
        $theirs = $this->subscription($this->sara, $this->plan, $this->server, 'sara_1');

        $response = $this->send('POST', $this->storeApi($this->website, "/subscriptions/{$theirs->id}/transfer"), ['telegram_id' => (string) self::TELEGRAM_ID]);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame($this->sara->id, $theirs->fresh()?->user_id);
    }

    public function testACustomerLooksUpTenIdsInTenMinutesAtMost(): void
    {
        $this->transfersOn();
        for ($i = 0; $i < ServiceTransfers::LOOKUPS; $i++) {
            self::assertSame(200, $this->get($this->storeApi($this->website, "/subscriptions/{$this->service->id}/transfer?telegram_id=7070"))->getStatusCode());
        }

        $response = $this->transfer(['telegram_id' => self::SARA]);

        self::assertSame(429, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame((string) ServiceTransfers::LOOKUP_WINDOW, $response->getHeaderLine('Retry-After'));
        self::assertSame($this->ali->id, $this->service->fresh()?->user_id);
    }

    /** @param array<string, mixed> $body */
    private function transfer(array $body): ResponseInterface
    {
        return $this->send('POST', $this->storeApi($this->website, "/subscriptions/{$this->service->id}/transfer"), $body);
    }
}
