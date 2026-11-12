<?php

declare(strict_types=1);

namespace Tests\Unit\Providers\HmPanel;

use App\Modules\Providers\Contracts\ProviderInterface;
use App\Modules\Providers\Drivers\HmPanel\HmPanelApiException;
use App\Modules\Providers\Drivers\HmPanel\HmPanelDriver;
use App\Modules\Providers\DTO\ClientLink;
use App\Modules\Providers\DTO\ClientSpec;
use App\Modules\Providers\DTO\Expiry;
use App\Modules\Providers\Enums\AuthenticationFailure;
use App\Modules\Providers\Exceptions\AuthenticationException;
use App\Modules\Providers\Exceptions\NotFoundException;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Providers\Exceptions\UnexpectedResponseException;
use App\Modules\Providers\Exceptions\UnsupportedOperationException;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Support\PanelHttp;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;
use Tests\Support\FakePanel;
use Tests\TestCase;

/**
 * The generic ProviderInterface on HMPanel: its clients by name through lookup, then by the id it keeps; the inbounds of
 * its 3x-ui panels, one panel a client; 3x-ui's term passed on; a link's tokens matched by the panel and held to the
 * client's secrets; and its key's scopes and calls per minute.
 */
final class HmPanelProviderTest extends TestCase
{
    private const KEY = 'hmp_q8Xv2kL0bN7rT4yZ1aS6dF3gH9jK5mP2';
    private const MONTH = 30 * 86400;
    private const UUID = 'b1c0e3f4-1234-4abc-8def-0123456789ab';

    private FakePanel $panel;

    public function testTheConnectionTestReadsTheKeyAndHoldsItsScopesToWhatTheShopCalls(): void
    {
        foreach (['every right' => ['*'], 'the four the shop calls' => ['clients.read', 'clients.write', 'clients.renew', 'inbounds.read', 'stats.read'], 'a panel that does not say' => null] as $case => $scopes) {
            $provider = $this->provider([self::json(200, ['id' => 'k1', 'name' => 'AmoBot', 'rateLimitPerMin' => 60, 'webhookUrl' => null] + ($scopes === null ? [] : ['scopes' => $scopes]))]);

            $provider->testConnection();

            self::assertSame(['GET /api/v1/me'], $this->panel->calls(), $case);
        }

        $provider = $this->provider([self::json(200, ['id' => 'k1', 'name' => 'AmoBot', 'scopes' => ['clients.read', 'account.read'], 'rateLimitPerMin' => 60])]);
        try {
            $provider->testConnection();
            self::fail('the key cannot make a client');
        } catch (AuthenticationException $e) {
            self::assertSame(AuthenticationFailure::Scope, $e->failure);
            self::assertSame('Missing scope: clients.write, clients.renew, inbounds.read', $e->panelMessage);
            self::assertStringStartsWith('کلید دسترسی clients.write، clients.renew، inbounds.read را ندارد؛', $e->hint);
        }
    }

    public function testARefusedKeyFailsTheTestAsTheAuthenticationItIs(): void
    {
        $provider = $this->provider([self::json(401, ['statusCode' => 401, 'message' => 'Invalid API key'])]);

        $this->expectException(AuthenticationException::class);
        $provider->testConnection();
    }

    public function testThereIsNoHostStatusToAskFor(): void
    {
        $provider = $this->provider([]);

        try {
            $provider->status();
            self::fail('HMPanel reports no host statistics');
        } catch (UnsupportedOperationException) {
            self::assertSame([], $this->panel->calls(), 'not a call spent on it');
        }
    }

    public function testTheInboundsAreThoseOfItsThreeXuiPanelsEachNamedByItsPanel(): void
    {
        $provider = $this->provider([self::json(200, ['data' => [
            ['id' => 'in1', 'tag' => 'inbound-443', 'remark' => 'DE', 'protocol' => 'vless', 'port' => 443, 'panel' => ['id' => 'p1', 'name' => 'Germany', 'panelType' => '3x-ui']],
            ['id' => 'in2', 'tag' => 'inbound-8443', 'remark' => '', 'protocol' => 'trojan', 'port' => 8443, 'panel' => ['id' => 'p1', 'name' => 'Germany', 'panelType' => '3x-ui']],
            ['id' => 'in3', 'tag' => 'pg-group', 'remark' => 'PG', 'protocol' => 'vless', 'port' => 0, 'panel' => ['id' => 'p2', 'name' => 'Pasar', 'panelType' => 'pasarguard']],
            ['id' => 'in4', 'tag' => 'inbound-2096', 'remark' => 'NL', 'protocol' => 'vmess', 'port' => 2096, 'panel' => ['id' => 'p3', 'name' => 'Netherlands', 'panelType' => '3x-ui']],
        ]])]);

        $inbounds = $provider->listInbounds();

        self::assertSame(['GET /api/v1/inbounds'], $this->panel->calls());
        self::assertSame(['p1:in1', 'p1:in2', 'p3:in4'], array_map(static fn($inbound): string => $inbound->key, $inbounds), 'an external panel\'s inbounds go another way inside HMPanel');
        [$de, $trojan, $nl] = $inbounds;
        self::assertSame(['Germany · DE', 'inbound-443', 'vless', 443, true], [$de->remark, $de->tag, $de->protocol, $de->port, $de->enabled]);
        self::assertSame('Germany · inbound-8443', $trojan->remark, 'no remark: its tag');
        self::assertSame('Netherlands · NL', $nl->remark);
        self::assertSame([null, null, 0], [$de->network, $de->security, $de->clientCount], 'what HMPanel does not say is not made up');
    }

    public function testThePanelAlwaysServesSubscriptionsWithoutBeingAsked(): void
    {
        self::assertTrue($this->provider([])->servesSubscriptions());
        self::assertSame([], $this->panel->calls());
    }

    public function testANewClientIsPostedOnItsPanelsInboundsThenReadByItsId(): void
    {
        $provider = $this->provider([self::json(201, self::row()), self::json(200, self::client())]);

        $client = $provider->createClient(['p1:in1', 'p1:in2', 'p1:in1'], new ClientSpec(
            name: 'amir_1',
            totalBytes: 30 * 1024 ** 3,
            expiry: Expiry::afterFirstUse(self::MONTH),
            ipLimit: 2,
            telegramId: 42,
            comment: '42 | amir',
        ));

        self::assertSame(['POST /api/v1/clients', 'GET /api/v1/clients/c1'], $this->panel->calls(), 'HMPanel answers its stored row, not the client');
        self::assertSame([
            'email' => 'amir_1',
            'inboundIds' => ['in1', 'in2'],
            'total' => 30 * 1024 ** 3,
            'expiryTime' => -self::MONTH * 1000,
            'limitIp' => 2,
            'remark' => '42 | amir',
        ], $this->panel->params(0), '3x-ui\'s term, as HMPanel passes it on; no credentials (the panels mint them)');

        self::assertSame('amir_1', $client->name);
        self::assertTrue($client->enabled);
        self::assertSame([30 * 1024 ** 3, 0, 0, 2], [$client->totalBytes, $client->uploadBytes, $client->downloadBytes, $client->ipLimit]);
        self::assertSame(self::MONTH, $client->expiry->pendingSeconds());
        self::assertSame('https://hm.example/s/9f8e7d6c5b', $client->subscriptionUrl);
        self::assertNull($client->online);
    }

    public function testADeadlineIsUnixMillisecondsAndNoTermIsZero(): void
    {
        $deadline = new \DateTimeImmutable('2030-01-01 00:00:00', new \DateTimeZone('UTC'));
        $provider = $this->provider([
            self::json(201, self::row()),
            self::json(200, self::client(['expiryTime' => $deadline->getTimestamp() * 1000])),
            self::json(201, self::row(['id' => 'c2'])),
            self::json(200, self::client(['id' => 'c2', 'email' => 'amir_2', 'expiryTime' => 0])),
        ]);

        $fixed = $provider->createClient(['p1:in1'], new ClientSpec('amir_1', expiry: Expiry::at($deadline)));
        $never = $provider->createClient(['p1:in1'], new ClientSpec('amir_2'));

        self::assertSame($deadline->getTimestamp() * 1000, $this->panel->params(0)['expiryTime']);
        self::assertEquals($deadline, $fixed->expiry->deadline());
        self::assertSame(0, $this->panel->params(2)['expiryTime']);
        self::assertArrayNotHasKey('remark', $this->panel->params(2), 'nothing to say leaves the remark alone');
        self::assertSame([null, null], [$never->expiry->deadline(), $never->expiry->pendingSeconds()]);
    }

    public function testInboundsOfTwoPanelsAreRefusedBeforeAnythingIsSent(): void
    {
        $provider = $this->provider([]);

        try {
            $provider->createClient(['p1:in1', 'p3:in4'], new ClientSpec('amir_1'));
            self::fail('a client lives on one panel');
        } catch (HmPanelApiException $e) {
            self::assertSame([], $this->panel->calls());
            self::assertSame(400, $e->httpStatus);
            self::assertStringContainsString('same panel', $e->panelMessage, 'HMPanel\'s own words');
            self::assertStringContainsString('یک پنل', $e->hint);
            self::assertFalse($e->mayHaveBeenDone());
        }
    }

    public function testCreateClientWithoutAnInboundIsACallerMistake(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->provider([])->createClient([], new ClientSpec('amir_1'));
    }

    public function testAClientWantedSwitchedOffIsSwitchedOffAsItIsMade(): void
    {
        $provider = $this->provider([self::json(201, self::row()), self::json(200, self::row(['enable' => false])), self::json(200, self::client(['enable' => false, 'status' => 'disabled']))]);

        $client = $provider->createClient(['p1:in1'], new ClientSpec('amir_1', 10, Expiry::afterFirstUse(self::MONTH), enabled: false));

        self::assertSame(['POST /api/v1/clients', 'PATCH /api/v1/clients/c1', 'GET /api/v1/clients/c1'], $this->panel->calls(), 'HMPanel makes a client switched on');
        self::assertSame(['enable' => false], $this->panel->params(1));
        self::assertFalse($client->enabled);
    }

    public function testAClientThePanelWillNotSwitchOffIsTakenBackOrElseFailsAsMade(): void
    {
        $refusal = static fn(): Response => self::json(400, ['statusCode' => 400, 'message' => 'Client update is already in progress']);
        $provider = $this->provider([self::json(201, self::row()), $refusal(), self::json(200, ['ok' => true])]);
        try {
            $provider->createClient(['p1:in1'], new ClientSpec('amir_1', enabled: false));
            self::fail('the switch was refused');
        } catch (ProviderException $e) {
            self::assertSame(['POST /api/v1/clients', 'PATCH /api/v1/clients/c1', 'DELETE /api/v1/clients/c1'], $this->panel->calls());
            self::assertFalse($e->mayHaveBeenDone(), 'taken back: nothing was made');
        }

        $provider = $this->provider([self::json(201, self::row()), $refusal(), $refusal()]);
        try {
            $provider->createClient(['p1:in1'], new ClientSpec('amir_1', enabled: false));
            self::fail('the switch was refused');
        } catch (ProviderException $e) {
            self::assertTrue($e->mayHaveBeenDone(), 'still there, switched on: whoever asked looks at it');
        }
    }

    public function testAReadThatFailsAfterThePanelTookTheClientIsAFailureAfterTheWrite(): void
    {
        $provider = $this->provider([self::json(201, self::row()), new Response(502, [], '')]);

        try {
            $provider->createClient(['p1:in1'], new ClientSpec('amir_1'));
            self::fail('the read failed');
        } catch (ProviderException $e) {
            self::assertTrue($e->mayHaveBeenDone());
        }
    }

    public function testAMadeClientWithoutItsIdInTheAnswerIsLookedUpByName(): void
    {
        $provider = $this->provider([self::json(201, ['ok' => true]), self::json(200, self::client()), self::json(200, self::client())]);

        self::assertSame('amir_1', $provider->createClient(['p1:in1'], new ClientSpec('amir_1'))->name);

        self::assertSame(['POST /api/v1/clients', 'GET /api/v1/clients/lookup', 'GET /api/v1/clients/c1'], $this->panel->calls());
        self::assertSame('key=amir_1', $this->panel->request(1)->getUri()->getQuery());
    }

    public function testAnUpdatePatchesTheLimitsTermAndSwitchByTheIdItLookedUpThenKept(): void
    {
        $provider = $this->provider([
            self::json(200, self::client()),
            self::json(200, self::row()),
            self::json(200, self::client(['totalBytes' => 50 * 1024 ** 3])),
            self::json(200, self::row()),
            self::json(200, self::client()),
        ]);

        $client = $provider->updateClient(new ClientSpec('amir_1', 50 * 1024 ** 3, Expiry::afterFirstUse(self::MONTH), ipLimit: 3, telegramId: 42));

        self::assertSame(['GET /api/v1/clients/lookup', 'PATCH /api/v1/clients/c1', 'GET /api/v1/clients/c1'], $this->panel->calls());
        self::assertSame(['enable' => true, 'total' => 50 * 1024 ** 3, 'expiryTime' => -self::MONTH * 1000, 'limitIp' => 3, 'remark' => '42'], $this->panel->params(1), 'the switch always: HMPanel switches on one given more');
        self::assertSame(50 * 1024 ** 3, $client->totalBytes);

        $this->panel->reset();
        $provider->updateClient(new ClientSpec('amir_1', enabled: false));
        self::assertSame(['PATCH /api/v1/clients/c1', 'GET /api/v1/clients/c1'], $this->panel->calls(), 'the id kept: no lookup');
        self::assertFalse($this->panel->params(0)['enable']);
    }

    public function testAKeptIdThePanelNoLongerKnowsIsLookedUpAfreshOnce(): void
    {
        $gone = static fn(): Response => self::json(404, ['statusCode' => 404, 'message' => 'Client not found']);
        $provider = $this->provider([self::json(200, self::client()), self::json(200, ['ok' => true])]);
        $provider->setClientEnabled('amir_1', false);

        // Deleted and made again by hand: another id under the same name.
        $this->panel->raw($gone(), self::json(200, self::client(['id' => 'c7'])), self::json(200, ['ok' => true]));
        $this->panel->reset();
        $provider->setClientEnabled('amir_1', true);
        self::assertSame(['PATCH /api/v1/clients/c1', 'GET /api/v1/clients/lookup', 'PATCH /api/v1/clients/c7'], $this->panel->calls());

        // Gone altogether.
        $this->panel->raw($gone(), $gone());
        $this->panel->reset();
        try {
            $provider->deleteClient('amir_1');
            self::fail('no such client');
        } catch (NotFoundException) {
            self::assertSame(['DELETE /api/v1/clients/c7', 'GET /api/v1/clients/lookup'], $this->panel->calls());
        }
    }

    public function testAnUpdateWhoseReadFailsIsAFailureAfterTheWriteAndNeverMadeTwice(): void
    {
        $provider = $this->provider([self::json(200, self::client()), self::json(200, self::row()), self::json(404, ['statusCode' => 404, 'message' => 'Client not found'])]);

        try {
            $provider->updateClient(new ClientSpec('amir_1'));
            self::fail('the read failed');
        } catch (NotFoundException $e) {
            self::assertTrue($e->mayHaveBeenDone());
            self::assertSame(['GET /api/v1/clients/lookup', 'PATCH /api/v1/clients/c1', 'GET /api/v1/clients/c1'], $this->panel->calls(), 'not patched again');
        }
    }

    public function testAnUpdateOfAClientThePanelDoesNotHaveIsNotFound(): void
    {
        $provider = $this->provider([self::json(404, ['statusCode' => 404, 'message' => 'Client not found'])]);

        $this->expectException(NotFoundException::class);
        $provider->updateClient(new ClientSpec('amir_1'));
    }

    public function testRotatingIsNothingItsApiCanDo(): void
    {
        $provider = $this->provider([]);

        $this->expectException(UnsupportedOperationException::class);
        $provider->rotateClientCredentials('amir_1');
    }

    public function testSwitchingDeletingAndResettingAddressTheClientByItsId(): void
    {
        $provider = $this->provider([self::json(200, self::client()), self::json(200, ['ok' => true]), self::json(200, self::client()), self::json(200, ['ok' => true])]);

        $provider->setClientEnabled('amir_1', false);
        $provider->resetClientTraffic('amir_1');
        $provider->deleteClient('amir_1');

        self::assertSame(['GET /api/v1/clients/lookup', 'PATCH /api/v1/clients/c1', 'POST /api/v1/clients/c1/reset-usage', 'DELETE /api/v1/clients/c1'], $this->panel->calls());
        self::assertSame(['enable' => false], $this->panel->params(1));

        // Deleted: its id is forgotten, so the next call by its name asks again.
        $this->panel->raw(self::json(404, ['statusCode' => 404, 'message' => 'Client not found']));
        $this->panel->reset();
        try {
            $provider->deleteClient('amir_1');
            self::fail('gone already');
        } catch (NotFoundException) {
            self::assertSame(['GET /api/v1/clients/lookup'], $this->panel->calls());
        }
    }

    public function testFindClientLooksTheNameUpAndReadsTheCountersTermAndDeviceLimit(): void
    {
        $provider = $this->provider([self::json(200, self::client(['upBytes' => 1024, 'downBytes' => 4096, 'expiryTime' => 1767225600000, 'limitIp' => 0, 'enable' => false, 'status' => 'disabled']))]);

        $client = $provider->findClient('amir_1', presence: true);

        self::assertNotNull($client);
        self::assertSame('key=amir_1', $this->panel->request(0)->getUri()->getQuery());
        self::assertSame([1024, 4096, false, 0], [$client->uploadBytes, $client->downloadBytes, $client->enabled, $client->ipLimit]);
        self::assertSame(1767225600, $client->expiry->deadline()?->getTimestamp());
        self::assertNull($client->online, 'HMPanel cannot tell, asked or not');
    }

    public function testFindClientIsNullForAMissingClientButAWrongAddressIsNoAnswer(): void
    {
        self::assertNull($this->provider([self::json(404, ['statusCode' => 404, 'message' => 'Client not found'])])->findClient('amir_1'));

        $this->expectException(UnexpectedResponseException::class);
        $this->provider([self::json(404, ['statusCode' => 404, 'message' => 'Cannot GET /api/v1/clients/lookup'])])->findClient('amir_1');
    }

    public function testAClientLookupAnswersByAnotherOfItsValuesIsNotTheNamesSoTheNameIsSearchedFor(): void
    {
        // amir_1 happens to be another client's subscription id.
        $other = self::client(['id' => 'c5', 'email' => 'sara_9', 'subId' => 'amir_1']);
        $provider = $this->provider([
            self::json(200, $other),
            self::json(200, ['data' => [$other, self::client(['id' => 'c6', 'email' => 'amir_10']), self::client()], 'page' => 1, 'limit' => 200, 'total' => 3]),
        ]);

        self::assertSame('amir_1', $provider->findClient('amir_1')?->name);
        self::assertSame(['GET /api/v1/clients/lookup', 'GET /api/v1/clients/search'], $this->panel->calls());
        self::assertSame('search=amir_1&limit=200', $this->panel->request(1)->getUri()->getQuery());

        $provider = $this->provider([self::json(200, $other), self::json(200, ['data' => [$other], 'page' => 1, 'limit' => 200, 'total' => 1])]);
        self::assertNull($provider->findClient('amir_1'));
    }

    public function testListClientsPagesThroughEveryClient200AtATime(): void
    {
        $page = static fn(int $from, int $count, int $total): Response => self::json(200, [
            'data' => array_map(static fn(int $i): array => self::client(['id' => "c{$i}", 'email' => "user_{$i}"]), range($from, $from + $count - 1)),
            'page' => 1, 'limit' => 200, 'total' => $total,
        ]);
        $provider = $this->provider([$page(1, 200, 401), $page(200, 200, 401), $page(400, 2, 401)]);

        $clients = $provider->listClients();

        self::assertSame(['GET /api/v1/clients/search', 'GET /api/v1/clients/search', 'GET /api/v1/clients/search'], $this->panel->calls());
        self::assertSame(['page=1&limit=200', 'page=2&limit=200', 'page=3&limit=200'], array_map(fn(int $i): string => $this->panel->request($i)->getUri()->getQuery(), [0, 1, 2]));
        self::assertCount(401, $clients, 'one seen twice (a client made meanwhile shifted the pages) is kept once');
        self::assertSame('https://hm.example/s/9f8e7d6c5b', $clients[0]->subscriptionUrl);

        // Every name now has its id: a call by name needs no lookup.
        $this->panel->raw(self::json(200, ['ok' => true]));
        $this->panel->reset();
        $provider->setClientEnabled('user_7', false);
        self::assertSame(['PATCH /api/v1/clients/c7'], $this->panel->calls());
    }

    public function testAShortPageEndsAListingThatReportsNoTotal(): void
    {
        $provider = $this->provider([self::json(200, ['data' => [self::client()]])]);

        self::assertCount(1, $provider->listClients());
        self::assertCount(1, $this->panel->calls());
    }

    public function testAPanelThatNeverStopsAnsweringFullPagesIsNotListedAtOnce(): void
    {
        $full = self::json(200, ['data' => array_map(static fn(int $i): array => self::client(['id' => "c{$i}", 'email' => "user_{$i}"]), range(1, 200)), 'page' => 1, 'limit' => 200]);
        $provider = $this->provider(array_fill(0, 50, $full));

        $this->expectException(UnsupportedOperationException::class);
        $provider->listClients();
    }

    public function testAnHmPanelLinksTokenIsLookedUpAndHeldToTheClientsOwnToken(): void
    {
        $provider = $this->provider([self::json(200, self::client())]);

        $client = $provider->findClientByLink(self::link('سرویسم https://hm.example/s/9f8e7d6c5b'));

        self::assertSame('amir_1', $client?->name);
        self::assertSame(['GET /api/v1/clients/lookup'], $this->panel->calls(), '"s" is too short to be a token');
        self::assertSame('key=9f8e7d6c5b', $this->panel->request(0)->getUri()->getQuery());

        // Found by its link: a call by its name needs no lookup.
        $this->panel->raw(self::json(200, ['ok' => true]));
        $this->panel->reset();
        $provider->resetClientTraffic('amir_1');
        self::assertSame(['POST /api/v1/clients/c1/reset-usage'], $this->panel->calls());
    }

    public function testAThreeXuiSubscriptionLinksIdAndAConfigsUuidFindTheirClient(): void
    {
        $provider = $this->provider([self::json(200, self::client())]);
        self::assertSame('amir_1', $provider->findClientByLink(self::link('https://de1.example.com:2096/sub/a1b2c3d4e5f60718'))?->name);
        self::assertSame('key=a1b2c3d4e5f60718', $this->panel->request(0)->getUri()->getQuery());

        $provider = $this->provider([self::json(200, self::client())]);
        self::assertSame('amir_1', $provider->findClientByLink(self::link('vless://' . strtoupper(self::UUID) . '@de1.example.com:443?security=reality#amir'))?->name);
        self::assertSame('key=' . self::UUID, $this->panel->request(0)->getUri()->getQuery());
    }

    public function testANameIsNoSecretSoALinkCarryingOneClaimsNothing(): void
    {
        // A made-up link whose last part is a client's (guessable) name: lookup answers that client by its name.
        $provider = $this->provider([self::json(200, self::client(['email' => 'amir_1234']))]);

        self::assertNull($provider->findClientByLink(self::link('https://anything.example/s/amir_1234')));

        // Nor a UUID-shaped name, nor a value that matched an external panel's client.
        $provider = $this->provider([self::json(200, self::client(['email' => self::UUID])), self::json(200, self::client(['panel' => ['id' => 'p2', 'name' => 'Pasar', 'type' => 'pasarguard']]))]);
        self::assertNull($provider->findClientByLink(self::link('vless://' . self::UUID . '@a.example:443 vless://c2d1f4a5-1234-4abc-8def-0123456789ab@a.example:443')));
        self::assertCount(2, $this->panel->calls());
    }

    public function testALinkNoClientHasIsNoneAndOnlyAFewOfItsValuesAreAsked(): void
    {
        $gone = static fn(): Response => self::json(404, ['statusCode' => 404, 'message' => 'Client not found']);
        $provider = $this->provider([$gone(), $gone(), $gone(), $gone()]);

        $text = 'https://a.example/s/aaaaaaaaaa https://b.example/s/bbbbbbbbbb https://c.example/s/cccccccccc https://d.example/s/dddddddddd https://e.example/s/eeeeeeeeee';

        self::assertNull($provider->findClientByLink(self::link($text)));
        self::assertCount(4, $this->panel->calls(), 'each value is a call against the key\'s calls per minute');

        $provider = $this->provider([$gone()]);
        self::assertNull($provider->findClientByLink(self::link('trojan://Tr0jan-Pass-1@a.example:443 https://a.example/s/aaaaaaaaaa')));
        self::assertSame(['key=aaaaaaaaaa'], [$this->panel->request(0)->getUri()->getQuery()]);
        self::assertCount(1, $this->panel->calls(), 'a password is no value HMPanel looks clients up by');
    }

    public function testTheServersPrefixGoesBeforeTheClientsToken(): void
    {
        $provider = $this->provider([self::json(200, self::client()), self::json(200, self::client(['subToken' => '']))], ['subscription_url' => 'https://sub.example.com/s']);

        $withToken = $provider->findClient('amir_1');
        $withoutToken = $provider->findClient('amir_1');

        self::assertSame('https://sub.example.com/s/9f8e7d6c5b', $withToken?->subscriptionUrl);
        self::assertSame('https://sub.example.com/s/a1b2c3d4e5f60718', $withoutToken?->subscriptionUrl, 'no HMPanel token: its subscription id');
    }

    public function testALinkThePanelWritesAsAPathIsMadeAbsolute(): void
    {
        $provider = $this->provider([self::json(200, self::client(['subscriptionUrl' => '/s/9f8e7d6c5b'])), self::json(200, self::client(['subscriptionUrl' => null]))]);

        $path = $provider->findClient('amir_1');
        $none = $provider->findClient('amir_1');

        self::assertSame('https://hm.example/s/9f8e7d6c5b', $path?->subscriptionUrl);
        self::assertNull($none?->subscriptionUrl);
    }

    public function testAKeyPastItsCallsPerMinuteIsTheRateLimitNotAMissingClient(): void
    {
        $provider = $this->provider([self::json(429, ['statusCode' => 429, 'message' => 'Rate limit exceeded'])]);

        try {
            $provider->findClient('amir_1');
            self::fail('the panel refused the call');
        } catch (HmPanelApiException $e) {
            self::assertSame(429, $e->httpStatus);
            self::assertTrue($e->unavailable());
        }
    }

    /**
     * @param list<Response> $responses
     * @param array<string, mixed> $meta
     */
    private function provider(array $responses, array $meta = []): ProviderInterface
    {
        $this->app(); // registers the encrypter the Server casts need

        $this->panel = new FakePanel();
        $this->panel->raw(...$responses);

        $server = new Server([
            'name' => 'HM',
            'driver' => 'hmpanel',
            'base_url' => 'https://hm.example',
            'api_token' => self::KEY,
            'meta' => $meta,
        ]);

        return (new HmPanelDriver())->connect($server, new PanelHttp($this->panel->client(), new NullLogger()));
    }

    /**
     * A client as HMPanel answers one: on a 3x-ui panel, its term waiting a month for its first connection.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function client(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'c1',
            'email' => 'amir_1',
            'remark' => '42 | amir',
            'subId' => 'a1b2c3d4e5f60718',
            'subToken' => '9f8e7d6c5b',
            'subscriptionUrl' => 'https://hm.example/s/9f8e7d6c5b',
            'enable' => true,
            'disableReason' => null,
            'status' => 'active',
            'upBytes' => 0,
            'downBytes' => 0,
            'usedBytes' => 0,
            'totalBytes' => 30 * 1024 ** 3,
            'remainingBytes' => 30 * 1024 ** 3,
            'expiryTime' => -self::MONTH * 1000,
            'limitIp' => 2,
            'admin' => ['id' => 'a1', 'username' => 'ali'],
            'panel' => ['id' => 'p1', 'name' => 'Germany', 'type' => '3x-ui'],
            'lastSyncedAt' => '2026-10-16T09:00:00.000Z',
            'createdAt' => '2026-10-05T10:00:00.000Z',
            'updatedAt' => '2026-10-16T09:00:00.000Z',
        ];
    }

    /**
     * The stored row HMPanel answers a POST with: its counters as strings (BigInt), no link.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function row(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'c1',
            'panelId' => 'p1',
            'email' => 'amir_1',
            'uuid' => self::UUID,
            'subId' => 'a1b2c3d4e5f60718',
            'subToken' => '9f8e7d6c5b',
            'total' => '32212254720',
            'expiryTime' => (string) (-self::MONTH * 1000),
            'up' => '0',
            'down' => '0',
            'enable' => true,
        ];
    }

    private static function json(int $status, mixed $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }

    private static function link(string $text): ClientLink
    {
        return ClientLink::find($text) ?? self::fail("No link in {$text}.");
    }
}
