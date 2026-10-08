<?php

declare(strict_types=1);

namespace Tests\Unit\Providers\ThreeXui;

use App\Modules\Providers\Contracts\ProviderInterface;
use App\Modules\Providers\Drivers\ThreeXui\DTO\ClientPayload;
use App\Modules\Providers\Drivers\ThreeXui\DTO\ClientRecord;
use App\Modules\Providers\Drivers\ThreeXui\DTO\PanelSettings;
use App\Modules\Providers\Drivers\ThreeXui\Support\ExpiryTime;
use App\Modules\Providers\Drivers\ThreeXui\ThreeXuiDriver;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\DTO\ClientSpec;
use App\Modules\Providers\DTO\Expiry;
use App\Modules\Providers\Enums\CoreState;
use App\Modules\Providers\Exceptions\NotFoundException;
use App\Modules\Providers\Exceptions\PanelApiException;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Support\PanelHttp;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;
use Tests\Support\FakePanel;
use Tests\TestCase;

/**
 * The generic ProviderInterface on top of the 3x-ui v3 clients API, and the mapping it relies on.
 */
final class ThreeXuiProviderTest extends TestCase
{
    /** The subscription server as a healthy panel describes it: TLS on 2096 under /sub/. */
    private const SUB_SETTINGS = ['subEnable' => true, 'subDomain' => '', 'subPort' => 2096, 'subPath' => '/sub/', 'subCertFile' => '/c.pem', 'subKeyFile' => '/k.pem'];

    private FakePanel $panel;

    public function testCreateClientAttachesToTheInboundsAndReadsTheRowBack(): void
    {
        $expires = new \DateTimeImmutable('2030-01-01 00:00:00', new \DateTimeZone('UTC'));
        $provider = $this->provider([
            self::ok(null), // clients/add
            self::ok(self::wrapped(self::record(['email' => 'amir_1']), inboundIds: [3, 5])), // clients/get: the row inside its envelope
            self::ok(['up' => 1024, 'down' => 4096, 'enable' => true]), // clients/traffic, since the envelope carries none
            self::ok(self::SUB_SETTINGS), // setting/all, for the link
        ]);

        $info = $provider->createClient(['3', '5', '3'], new ClientSpec(
            name: 'amir_1',
            totalBytes: 50 * 1024 ** 3,
            expiry: Expiry::at($expires),
            ipLimit: 2,
            telegramId: 4242,
            comment: 'Ali',
        ));

        $add = $this->panel->params(0);
        self::assertSame('/panel/api/clients/add', $this->panel->request(0)->getUri()->getPath());
        self::assertSame([3, 5], $add['inboundIds'], 'the keys are the panel\'s numeric ids, each once');
        self::assertSame('amir_1', $add['client']['email']);
        self::assertSame(50 * 1024 ** 3, $add['client']['totalGB']);
        self::assertSame($expires->getTimestamp() * 1000, $add['client']['expiryTime']);
        self::assertSame(2, $add['client']['limitIp']);
        self::assertSame(4242, $add['client']['tgId']);
        self::assertSame('Ali', $add['client']['comment']);
        self::assertSame('', $add['client']['subId'], 'the panel mints the subscription id');
        self::assertArrayNotHasKey('id', $add['client'], 'and the uuid');

        self::assertSame('/panel/api/clients/get/amir_1', $this->panel->request(1)->getUri()->getPath());
        self::assertCount(4, $this->panel->calls(), 'a read-back does not ask who is online');
        self::assertSame('amir_1', $info->name);
        self::assertSame([1024, 4096], [$info->uploadBytes, $info->downloadBytes]);
        self::assertSame('https://panel.test:2096/sub/sub-1', $info->subscriptionUrl, 'the link as the panel builds it, from its settings and the minted id');
        self::assertEquals($expires, $info->expiry->deadline());
        self::assertNull($info->online);
    }

    public function testATermFromTheFirstConnectionIsANegativeExpiryTime(): void
    {
        $provider = $this->provider([
            self::ok(null),
            self::ok(self::wrapped(self::record(['expiryTime' => -30 * 86400 * 1000]), inboundIds: [3])),
            self::ok(['up' => 0, 'down' => 0, 'enable' => true]),
            self::ok(self::SUB_SETTINGS),
        ]);

        $info = $provider->createClient(['3'], new ClientSpec(name: 'amir_1', expiry: Expiry::afterFirstUse(30 * 86400)));

        self::assertSame(-30 * 86400 * 1000, $this->panel->params(0)['client']['expiryTime'], 'the panel starts counting at the first traffic');
        self::assertNull($info->expiry->deadline(), 'no deadline yet');
        self::assertSame(30 * 86400, $info->expiry->pendingSeconds());
    }

    public function testAClientThePanelTookButCannotShowComesBackWithoutALink(): void
    {
        $provider = $this->provider([
            self::ok(null),
            new Response(200, [], '{"success":false,"msg":"client not found"}'),
        ]);

        $info = $provider->createClient(['3'], new ClientSpec(name: 'x', totalBytes: 7));

        self::assertSame('x', $info->name);
        self::assertSame(7, $info->totalBytes);
        self::assertNull($info->subscriptionUrl, 'only the panel knows the link it minted: no link, no sale');
        self::assertCount(2, $this->panel->calls(), 'nothing more is asked about a client the panel cannot show');
    }

    public function testCreatingAClientWithoutAnInboundIsTheCallersMistake(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->provider([])->createClient([], new ClientSpec(name: 'x'));
    }

    public function testFindClientAsksWhoIsOnlineOnlyWhenPresenceIsWanted(): void
    {
        $provider = $this->provider([
            self::ok(self::wrapped(self::record(), inboundIds: [3])),
            self::ok(['bob', 'carol']), // onlines
            self::ok(['up' => 1, 'down' => 2, 'enable' => true, 'lastOnline' => 1735680000000]),
            self::ok(self::SUB_SETTINGS),
        ]);

        $info = $provider->findClient('alice', presence: true);

        self::assertSame('/panel/api/clients/onlines', $this->panel->request(1)->getUri()->getPath());
        self::assertNotNull($info);
        self::assertFalse($info->online);
        self::assertSame(1735680000, $info->lastOnlineAt?->getTimestamp());
        self::assertSame([1, 2], [$info->uploadBytes, $info->downloadBytes]);

        $provider = $this->provider([self::ok(self::wrapped(self::record(), inboundIds: [3])), self::ok(['up' => 0, 'down' => 0]), self::ok(self::SUB_SETTINGS)]);
        self::assertNull($provider->findClient('alice')?->online, 'not asked: nobody can tell');
        self::assertNotContains('POST /panel/api/clients/onlines', $this->panel->calls());
    }

    public function testTheLinkIsMissingWhenThePanelServesNoSubscriptions(): void
    {
        $provider = $this->provider([
            self::ok(self::wrapped(self::record(), inboundIds: [3])),
            self::ok(['alice']), // onlines
            self::ok(['up' => 0, 'down' => 0, 'enable' => true]),
            self::ok(['subEnable' => false]),
        ]);

        $info = $provider->findClient('alice', presence: true);

        self::assertNotNull($info);
        self::assertNull($info->subscriptionUrl);
        self::assertTrue($info->online);
    }

    public function testFindClientIsNullWhenThePanelHasNone(): void
    {
        $provider = $this->provider([new Response(200, [], '{"success":false,"msg":"Client not found"}')]);

        self::assertNull($provider->findClient('ghost'));
    }

    public function testARefusedReadIsNoAnswerRatherThanAClientThatIsGone(): void
    {
        // Read as "gone", it would mark the customer's service deleted.
        $provider = $this->provider([new Response(200, [], '{"success":false,"msg":"database is locked"}')]);

        try {
            $provider->findClient('alice');
            self::fail('a refusal must surface');
        } catch (PanelApiException $e) {
            self::assertSame('database is locked', $e->panelMessage);
        }
    }

    public function testARowWhoseCountersThePanelKeepsNoneOfReadsAsUnused(): void
    {
        $provider = $this->provider([
            self::ok(self::wrapped(self::record(), inboundIds: [3])),
            new Response(200, [], '{"success":false,"msg":"client traffic not found"}'),
            self::ok(self::SUB_SETTINGS),
        ]);

        $info = $provider->findClient('alice');

        self::assertSame([0, 0], [$info?->uploadBytes, $info?->downloadBytes]);
        self::assertSame('https://panel.test:2096/sub/sub-1', $info?->subscriptionUrl);
    }

    public function testACounterReadThePanelRefusesSurfaces(): void
    {
        $provider = $this->provider([
            self::ok(self::wrapped(self::record(), inboundIds: [3])),
            new Response(200, [], '{"success":false,"msg":"database is locked"}'),
        ]);

        $this->expectException(PanelApiException::class);
        $provider->findClient('alice');
    }

    public function testARowWhoseNumbersComeAsTextReadsTheSame(): void
    {
        $provider = $this->provider([
            self::ok([self::record(['totalGB' => '53687091200', 'expiryTime' => '1893456000000', 'enable' => 'true', 'limitIp' => '2', 'traffic' => ['up' => '1024', 'down' => '4096', 'enable' => '1']])]),
            self::ok(self::SUB_SETTINGS),
        ]);

        [$client] = $provider->listClients();

        self::assertSame([53687091200, 1893456000, true, 1024, 4096], [$client->totalBytes, $client->expiry->deadline()?->getTimestamp(), $client->enabled, $client->uploadBytes, $client->downloadBytes]);
    }

    public function testUpdateClientKeepsWhatThePanelHasAndReplacesTheLimits(): void
    {
        $provider = $this->provider([
            self::ok(self::wrapped(self::record(['flow' => 'xtls-rprx-vision', 'comment' => 'old', 'limitHwid' => 3, 'enable' => false]), inboundIds: [3])), // get
            self::ok(null),                                                                                                          // update
            self::ok(self::record(['totalGB' => 99])),                                                                               // read back (a flat row maps too)
            self::ok(self::SUB_SETTINGS),
        ]);

        $info = $provider->updateClient(new ClientSpec(name: 'alice', totalBytes: 99, ipLimit: 3));

        $sent = $this->panel->params(1);
        self::assertSame('/panel/api/clients/update/alice', $this->panel->request(1)->getUri()->getPath());
        self::assertSame('e18c9a96-71bf-48d4-933f-8b9a46d4290c', $sent['id'], 'the uuid survives the update');
        self::assertSame('sub-1', $sent['subId'], 'so does the subscription id');
        self::assertSame(99, $sent['totalGB']);
        self::assertSame(3, $sent['limitIp']);
        self::assertSame(3, $sent['limitHwid'], 'what the spec does not cover stays as the panel had it');
        self::assertTrue($sent['enable'], 'an update switches the client on (one the panel ended, renewed)');
        self::assertSame('xtls-rprx-vision', $sent['flow']);
        self::assertSame('old', $sent['comment']);
        self::assertSame(99, $info->totalBytes);
        self::assertSame('https://panel.test:2096/sub/sub-1', $info->subscriptionUrl);
    }

    public function testUpdatingAClientThatIsGoneIsNotFound(): void
    {
        $provider = $this->provider([new Response(200, [], '{"success":false,"msg":"Client not found"}')]);

        $this->expectException(NotFoundException::class);
        $provider->updateClient(new ClientSpec(name: 'ghost'));
    }

    public function testRotatingCredentialsSendsNewSecretsOnACopyOfTheRow(): void
    {
        $provider = $this->provider([
            self::ok(self::wrapped(self::record(['flow' => 'xtls-rprx-vision']), inboundIds: [3, 5])), // get
            self::ok(null),                                                                            // update
            self::ok(self::record(['uuid' => '0b7c2b2e-4b1a-4f6c-9d2a-1c3e5f7a9b1d', 'subId' => '5d1f0c3a-7e2b-4a9c-8f6d-2b4e6a8c0e1f'])), // read back
            self::ok(self::SUB_SETTINGS),
        ]);

        $info = $provider->rotateClientCredentials('alice');

        $uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
        $sent = $this->panel->params(1);
        self::assertSame('/panel/api/clients/update/alice', $this->panel->request(1)->getUri()->getPath());
        self::assertNotSame('e18c9a96-71bf-48d4-933f-8b9a46d4290c', $sent['id'], 'a new uuid');
        self::assertMatchesRegularExpression($uuid, $sent['id']);
        self::assertNotSame('pw', $sent['password'], 'a new password, since the row had one');
        self::assertSame(32, strlen((string) base64_decode($sent['password'], true)), 'valid as a 2022 key');
        self::assertMatchesRegularExpression($uuid, $sent['subId'], 'a new subscription id, a uuid as the panel mints them');
        self::assertNotSame('sub-1', $sent['subId']);
        self::assertArrayNotHasKey('auth', $sent, 'no hysteria auth to rotate on this row');
        // Everything else rides along untouched.
        self::assertSame(53687091200, $sent['totalGB']);
        self::assertSame(1893456000000, $sent['expiryTime']);
        self::assertSame([2, 4242, 'Ali', 'xtls-rprx-vision', true], [$sent['limitIp'], $sent['tgId'], $sent['comment'], $sent['flow'], $sent['enable']]);

        self::assertSame('https://panel.test:2096/sub/5d1f0c3a-7e2b-4a9c-8f6d-2b4e6a8c0e1f', $info->subscriptionUrl, 'the link carries the id the panel stored');
    }

    public function testRotatingAHysteriaClientGivesItANewAuthAndKeepsWhatThePanelDerives(): void
    {
        $provider = $this->provider([
            self::ok(self::wrapped(self::record(['uuid' => '', 'password' => '', 'auth' => 'old-auth', 'secret' => 'ee1234']), inboundIds: [3])),
            self::ok(null),
            self::ok(self::record(['uuid' => '', 'password' => '', 'auth' => 'new-auth', 'subId' => 'sub-2'])),
            self::ok(self::SUB_SETTINGS),
        ]);

        $provider->rotateClientCredentials('alice');

        $sent = $this->panel->params(1);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $sent['auth'], 'a new hysteria auth');
        self::assertArrayNotHasKey('id', $sent, 'no uuid on this row: none is made up');
        self::assertArrayNotHasKey('password', $sent);
        self::assertSame('ee1234', $sent['secret'], 'an mtproto secret is the panel\'s to derive: it stays');
    }

    public function testSwitchesAndDeleteUseTheClientEndpointsAndTheirRefusalsSurface(): void
    {
        $provider = $this->provider([
            self::ok(['changed' => 1, 'skipped' => []]),
            self::ok(['changed' => 0, 'skipped' => [['email' => 'alice', 'reason' => 'client not found']]]),
            self::ok(['changed' => 0, 'skipped' => [], 'errors' => ['inbound 3 is on an offline node']]),
            self::ok(null),
            new Response(200, [], '{"success":false,"msg":"client not found"}'),
        ]);

        $provider->setClientEnabled('alice', false);
        self::assertSame('/panel/api/clients/bulkDisable', $this->panel->request(0)->getUri()->getPath());
        self::assertSame(['emails' => ['alice']], $this->panel->params(0));

        try {
            $provider->setClientEnabled('alice', true);
            self::fail('a skipped client must surface');
        } catch (NotFoundException $e) {
            self::assertStringContainsString('alice', $e->getMessage());
        }

        try {
            $provider->setClientEnabled('alice', true);
            self::fail('an error must surface');
        } catch (PanelApiException $e) {
            self::assertSame('inbound 3 is on an offline node', $e->panelMessage);
        }

        $provider->deleteClient('alice');
        self::assertSame('/panel/api/clients/del/alice', $this->panel->request(3)->getUri()->getPath());

        $this->expectException(NotFoundException::class);
        $provider->deleteClient('alice'); // the panel no longer has it: the contract's "gone already"
    }

    public function testResettingTheCountersUsesTheClientsOwnEndpoint(): void
    {
        $provider = $this->provider([self::ok(null), new Response(200, [], '{"success":false,"msg":"Client not found"}')]);

        $provider->resetClientTraffic('alice');
        self::assertSame(['POST /panel/api/clients/resetTraffic/alice'], $this->panel->calls());

        $this->expectException(NotFoundException::class);
        $provider->resetClientTraffic('alice');
    }

    public function testListClientsReadsEveryRowWithItsCountersAtOnce(): void
    {
        $provider = $this->provider([
            self::ok([self::record(), self::record(['email' => 'bob', 'subId' => 'sub-2', 'traffic' => null])]), // clients/list
            self::ok(self::SUB_SETTINGS),                                                                      // setting/all, once for every link
            self::ok(['up' => 10, 'down' => 20, 'enable' => true]),                                            // clients/traffic: bob's row came without counters
        ]);

        $clients = $provider->listClients();

        self::assertSame('/panel/api/clients/list', $this->panel->request(0)->getUri()->getPath());
        self::assertCount(3, $this->panel->calls(), 'one read for the list, one for the links, one for the row without counters');
        self::assertSame(['alice', 'bob'], array_map(static fn(ClientInfo $client): string => $client->name, $clients));
        self::assertSame([5120, 30], array_map(static fn(ClientInfo $client): int => $client->uploadBytes + $client->downloadBytes, $clients));
        self::assertNull($clients[0]->online, 'presence is not asked for a list');
        self::assertSame('https://panel.test:2096/sub/sub-2', $clients[1]->subscriptionUrl);
    }

    public function testInboundsAreListedSlimWithTheirTransportAndClientCount(): void
    {
        $provider = $this->provider([self::ok([[
            'id' => 1,
            'tag' => 'in-443',
            'protocol' => 'vless',
            'port' => 443,
            'remark' => 'DE',
            'enable' => true,
            'settings' => ['clients' => [['email' => 'alice', 'enable' => true], ['email' => 'bob', 'enable' => false]]],
            'streamSettings' => '{"network":"tcp","security":"reality"}',
            'sniffing' => ['enabled' => true],
            'clientStats' => [],
        ]])]);

        $inbounds = $provider->listInbounds();

        self::assertSame('/panel/api/inbounds/list/slim', $this->panel->request(0)->getUri()->getPath(), 'no client secrets over the wire');
        self::assertCount(1, $inbounds);
        $inbound = $inbounds[0];
        self::assertSame('1', $inbound->key, 'the panel\'s id, as an opaque key');
        self::assertSame('reality', $inbound->security, 'JSON strings are decoded');
        self::assertSame('tcp', $inbound->network);
        self::assertSame(['vless', 443, 'DE', true, 2], [$inbound->protocol, $inbound->port, $inbound->remark, $inbound->enabled, $inbound->clientCount]);
    }

    public function testStatusMapsToThePanelAgnosticShape(): void
    {
        $provider = $this->provider([self::ok([
            'cpu' => 12.5,
            'mem' => ['current' => 2, 'total' => 8],
            'disk' => ['current' => 50, 'total' => 200],
            'xray' => ['state' => 'running', 'version' => 'v25.10.31'],
            'tcpCount' => 42,
            'udpCount' => 3,
            'uptime' => 86400,
        ])]);

        $status = $provider->status();

        self::assertSame(12.5, $status->cpuPercent);
        self::assertSame([2, 8, 50, 200], [$status->memoryUsedBytes, $status->memoryTotalBytes, $status->diskUsedBytes, $status->diskTotalBytes]);
        self::assertSame(CoreState::Running, $status->coreState);
        self::assertSame('Xray', $status->coreName);
        self::assertSame('v25.10.31', $status->coreVersion);
        self::assertSame(45, $status->connections);
        self::assertSame(86400, $status->uptimeSeconds);
    }

    public function testTheConnectionCheckNeedsAnAdminsRights(): void
    {
        $provider = $this->provider([self::ok([])]);

        $provider->testConnection();

        self::assertSame(['GET /panel/api/inbounds/options'], $this->panel->calls(), 'a monitor-only token fails here, not at the first sale');
    }

    public function testServesSubscriptionsFollowsThePanelsSettingsAndTheLinkPrefixOverride(): void
    {
        self::assertTrue($this->provider([self::ok(self::SUB_SETTINGS)])->servesSubscriptions());
        self::assertFalse($this->provider([self::ok(['subEnable' => false])])->servesSubscriptions());

        // Behind a reverse proxy the panel's settings do not describe: the owner's prefix wins.
        $provider = $this->provider([
            self::ok(self::wrapped(self::record(), inboundIds: [3])),
            self::ok(['up' => 0, 'down' => 0]),
            self::ok(['subEnable' => true]),
        ], meta: ['subscription_url' => 'https://sub.example.com/s']);

        self::assertSame('https://sub.example.com/s/sub-1', $provider->findClient('alice')?->subscriptionUrl);
    }

    public function testTheSubscriptionBaseIsBuiltAsThePanelBuildsIt(): void
    {
        self::assertSame('https://x.example.com/sub/', (new PanelSettings(['subURI' => 'https://x.example.com/sub', 'subPort' => 2096]))->subscriptionBase('ignored'));
        self::assertSame('http://[2001:db8::1]:2096/sub/', (new PanelSettings(['subPort' => 2096, 'subPath' => '/sub/']))->subscriptionBase('2001:db8::1'));
        self::assertSame('https://sub.example.com:443/feed/', (new PanelSettings(['subDomain' => 'sub.example.com', 'subPort' => 443, 'subPath' => 'feed', 'subCertFile' => 'c', 'subKeyFile' => 'k']))->subscriptionBase('panel.test'));
    }

    public function testAClientPayloadLeavesOutWhatItHasNotAndRoundTripsARow(): void
    {
        $payload = (new ClientPayload(email: 'a', totalBytes: 5))->toArray();
        self::assertSame(['email', 'enable', 'totalGB', 'expiryTime', 'limitIp', 'limitHwid', 'tgId', 'subId', 'comment', 'reset', 'resetDay', 'resetMax'], array_keys($payload));

        $record = ClientRecord::fromArray(self::record(['password' => '', 'allowedIPs' => '10.0.0.2/32, 10.0.0.3/32', 'reverse' => ['tag' => 'r']]));
        $again = ClientPayload::fromRecord($record)->with(['totalBytes' => 7])->toArray();

        self::assertSame($record->uuid, $again['id']);
        self::assertSame(7, $again['totalGB']);
        self::assertSame(['10.0.0.2/32', '10.0.0.3/32'], $again['allowedIPs']);
        self::assertSame(['tag' => 'r'], $again['reverse']);
        self::assertArrayNotHasKey('password', $again);
    }

    public function testExpiryRoundTripsThroughThePanelsExpiryTime(): void
    {
        self::assertSame(0, ExpiryTime::of(Expiry::never()));
        self::assertSame(-86400000, ExpiryTime::of(Expiry::afterFirstUse(86400)));
        self::assertSame(1893456000000, ExpiryTime::of(Expiry::at(new \DateTimeImmutable('@1893456000'))));

        $never = ExpiryTime::toExpiry(0);
        self::assertSame([null, null], [$never->deadline(), $never->pendingSeconds()]);
        self::assertSame(86400, ExpiryTime::toExpiry(-86400000)->pendingSeconds());
        self::assertSame(1893456000, ExpiryTime::toExpiry(1893456000000)->deadline()?->getTimestamp());
    }

    /**
     * @param list<Response> $answers
     * @param array<string, mixed> $meta
     */
    private function provider(array $answers, array $meta = []): ProviderInterface
    {
        $this->app(); // the encrypter the Server's casts need

        $this->panel = new FakePanel();
        $this->panel->raw(...$answers);

        $server = new Server(['name' => 'DE-1', 'driver' => '3x-ui', 'base_url' => 'https://panel.test:2053', 'api_token' => 'tok', 'meta' => $meta]);

        return (new ThreeXuiDriver())->connect($server, new PanelHttp($this->panel->client(), new NullLogger()));
    }

    /**
     * What GET /clients/get/{email} really answers: the row under `client`, the attachments beside it.
     *
     * @param array<string, mixed> $row
     * @param list<int> $inboundIds
     * @return array<string, mixed>
     */
    private static function wrapped(array $row, array $inboundIds): array
    {
        unset($row['inboundIds'], $row['traffic']);

        return ['client' => $row, 'externalLinks' => [], 'inboundIds' => $inboundIds, 'tunnelAllowedIPs' => [], 'usedTraffic' => 0];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function record(array $overrides = []): array
    {
        return $overrides + [
            'id' => 14825,
            'email' => 'alice',
            'uuid' => 'e18c9a96-71bf-48d4-933f-8b9a46d4290c',
            'password' => 'pw',
            'subId' => 'sub-1',
            'enable' => true,
            'totalGB' => 53687091200,
            'expiryTime' => 1893456000000,
            'limitIp' => 2,
            'limitHwid' => 0,
            'tgId' => 4242,
            'comment' => 'Ali',
            'flow' => '',
            'reset' => 0,
            'inboundIds' => [3],
            'traffic' => ['up' => 1024, 'down' => 4096, 'enable' => true],
            'createdAt' => 1735000000000,
        ];
    }

    private static function ok(mixed $obj): Response
    {
        return FakePanel::success($obj);
    }
}
