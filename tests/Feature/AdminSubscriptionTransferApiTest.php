<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Providers\Models\Server;
use App\Modules\Store\Enums\StaffGrant;
use App\Modules\Store\Models\Website;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ServiceTransfers;
use App\Modules\Subscriptions\Services\ServiceTransferSettings;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\Fakes\FakeProvider;
use Tests\HttpTestCase;

/**
 * «انتقال به مشتری دیگر» in a service's dialog (POST /subscriptions/{id}/transfer): support hands a service to the shop's
 * customer of a Telegram id whatever the customers' switch says, the fresh row answering with its new customer, both
 * customers told; the row says when it may go; refusals under their field. An agent works in their own shop; the
 * website's admins need the delete grant, and never move their own service or take one themselves. The switch itself is
 * the bot settings' `service_transfers` group.
 */
final class AdminSubscriptionTransferApiTest extends HttpTestCase
{
    private const SARA = 7070;

    private User $ali;
    private User $sara;
    private Server $server;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-13 12:00:00');
        $this->telegram();
        $this->withoutQr();
        $this->server = $this->sellingServer();
        $this->plan = $this->plan([], $this->server);
        $this->ali = $this->customer(['telegram_id' => 1001, 'username' => 'ali_r']);
        $this->sara = $this->customer(['telegram_id' => self::SARA, 'first_name' => 'Sara', 'username' => 'sara']);
    }

    public function testSupportHandsTheServiceOnAndTheRowAnswersWithItsNewCustomer(): void
    {
        $this->loginAsAdmin();
        $this->reportGroup();
        $service = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_r_1', ['auto_renew' => true]));
        self::assertTrue($this->row($service)['actions']['transfer'], 'it may go');

        $response = $this->postJson("/api/admin/subscriptions/{$service->id}/transfer", ['telegram_id' => '۷۰۷۰']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $row = $this->decode($response)['subscription'];
        self::assertSame(['id' => $this->sara->id, 'name' => 'Sara', 'username' => 'sara', 'telegram_id' => self::SARA, 'email' => null], $row['user']);
        self::assertFalse($row['auto_renew']);
        self::assertSame($row, $this->row($service), 'as the list shows it');
        self::assertSame([self::text(BotText::ServiceTransferredBySupport, ['client' => 'ali_r_1', 'cancelled' => ''])], $this->telegram()->sentTo(1001), 'the previous customer told');
        self::assertCount(1, $this->telegram()->sentTo(self::SARA), 'the new one told');
        self::assertStringContainsString('🎧 توسط پشتیبانی: root', (string) ReportMessage::query()->value('text'));
        self::assertSame(self::SARA, FakeProvider::lastUpdate('ali_r_1')->telegramId, 'its client on the panel follows');
        self::assertFalse($this->service(ServiceTransferSettings::class)->enabled(), 'whatever the customers\' switch says');
    }

    public function testEachRefusalIsUnderItsField(): void
    {
        $this->loginAsAdmin();
        $service = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_r_1'));
        $test = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'test_ali_r_1', ['is_test' => true, 'plan_id' => null]));
        self::assertFalse($this->row($test)['actions']['transfer'], 'a free test stays its customer\'s');

        $this->assertRefusedUnder('status', ServiceTransfers::TEST, $this->transfer($test, (string) self::SARA));
        $this->assertRefusedUnder('telegram_id', ServiceTransfers::NOT_AN_ID, $this->transfer($service, 'sara'));
        $this->assertRefusedUnder('telegram_id', ServiceTransfers::UNKNOWN, $this->transfer($service, '9999'));
        $this->assertRefusedUnder('telegram_id', ServiceTransfers::SAME, $this->transfer($service, 1001));
        self::assertSame(404, $this->postJson('/api/admin/subscriptions/999999/transfer', ['telegram_id' => self::SARA])->getStatusCode());
        self::assertSame($this->ali->id, $service->fresh()?->user_id);
    }

    public function testTheFormLooksTheRecipientUpBeforeAnythingIsSent(): void
    {
        $this->loginAsAdmin();
        $service = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_r_1'));
        $lookUp = fn(string $telegramId): ResponseInterface => $this->get("/api/admin/subscriptions/{$service->id}/transfer?telegram_id=" . rawurlencode($telegramId));

        $found = $lookUp('۷۰۷۰');
        self::assertSame(200, $found->getStatusCode(), (string) $found->getBody());
        self::assertSame(['id' => $this->sara->id, 'name' => 'Sara', 'username' => 'sara', 'telegram_id' => self::SARA, 'email' => null], $this->decode($found)['recipient']);
        self::assertSame($this->ali->id, $service->fresh()?->user_id, 'nothing changed');

        $this->assertRefusedUnder('telegram_id', ServiceTransfers::SAME, $lookUp('1001'));
        $this->assertRefusedUnder('telegram_id', ServiceTransfers::UNKNOWN, $lookUp('9999'));
        $this->sara->forceFill(['status' => UserStatus::Banned])->save();
        $this->assertRefusedUnder('telegram_id', ServiceTransfers::BANNED, $lookUp((string) self::SARA));
        self::assertSame([], $this->telegram()->sentTo(self::SARA), 'nobody told');
    }

    public function testAnAgentHandsOnAServiceOfTheirShopToOneOfItsCustomers(): void
    {
        $bot = $this->agentBot();
        [$theirs, $reza] = CurrentBot::run($bot, function (): array {
            $customer = $this->customer(['telegram_id' => 1001, 'username' => 'ali_r']);
            $reza = $this->customer(['telegram_id' => 8080, 'username' => 'reza']);

            return [$this->mirrored($this->subscription($customer, $this->plan, $this->server, 'ali_r_9')), $reza];
        });
        $this->loginAsAgent($bot);

        $this->assertRefusedUnder('telegram_id', ServiceTransfers::UNKNOWN, $this->postJson("/api/agent/subscriptions/{$theirs->id}/transfer", ['telegram_id' => (string) self::SARA]));

        $response = $this->postJson("/api/agent/subscriptions/{$theirs->id}/transfer", ['telegram_id' => '8080']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame($reza->id, $this->decode($response)['subscription']['user']['id']);
    }

    public function testTheWebsitesAdminsNeedTheDeleteGrantAndNeverActOnThemselves(): void
    {
        $website = $this->website();
        $boss = $this->customer(['telegram_id' => 6060, 'username' => 'boss', 'first_name' => 'Boss']);
        $service = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_r_1'));
        $own = $this->mirrored($this->subscription($boss, $this->plan, $this->server, 'boss_1'));
        $this->loginAsStaff($website, $boss);

        self::assertSame(403, $this->send('POST', $this->staffApi($website, "/subscriptions/{$service->id}/transfer"), ['telegram_id' => self::SARA])->getStatusCode(), 'no grant');

        $website->forceFill(['staff_grants' => [StaffGrant::Delete->value]])->save();
        $mine = $this->send('POST', $this->staffApi($website, "/subscriptions/{$own->id}/transfer"), ['telegram_id' => self::SARA]);
        self::assertSame([403, ActorRefusedException::OWN_SERVICE], [$mine->getStatusCode(), $this->decode($mine)['message']]);
        $this->assertRefusedUnder('telegram_id', ServiceTransfers::TO_THEMSELVES, $this->send('POST', $this->staffApi($website, "/subscriptions/{$service->id}/transfer"), ['telegram_id' => 6060]));

        $given = $this->send('POST', $this->staffApi($website, "/subscriptions/{$service->id}/transfer"), ['telegram_id' => self::SARA]);
        self::assertSame(200, $given->getStatusCode(), (string) $given->getBody());
        self::assertSame($this->sara->id, $service->fresh()?->user_id);
    }

    public function testTheCustomersSwitchIsTheBotSettingsGroup(): void
    {
        $this->loginAsAdmin();

        $on = $this->putJson('/api/admin/bot/settings/service_transfers', ['transfer_enabled' => true]);
        self::assertSame(200, $on->getStatusCode(), (string) $on->getBody());
        self::assertTrue($this->decode($on)['settings']['transfer_enabled']);
        self::assertTrue($this->service(ServiceTransfers::class)->offered());

        $bad = $this->unchecked()->putJson('/api/admin/bot/settings/service_transfers', ['transfer_enabled' => 'maybe']);
        self::assertSame(422, $bad->getStatusCode());
        self::assertArrayHasKey('transfer_enabled', $this->decode($bad)['errors']);
        self::assertTrue($this->service(ServiceTransfers::class)->offered(), 'as it was');
    }

    /** @return array<string, mixed> The service as the list shows it now. */
    private function row(Subscription $service): array
    {
        return $this->decode($this->get("/api/admin/subscriptions/{$service->id}"))['subscription'];
    }

    private function transfer(Subscription $service, int|string $telegramId): ResponseInterface
    {
        return $this->postJson("/api/admin/subscriptions/{$service->id}/transfer", ['telegram_id' => $telegramId]);
    }

    private function staffApi(Website $website, string $path): string
    {
        return $this->storeApi($website, '/admin' . $path);
    }
}
