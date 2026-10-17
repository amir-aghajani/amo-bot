<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Modules\Providers\Contracts\ProviderInterface;
use App\Modules\Providers\DTO\Capabilities;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\DTO\ClientLink;
use App\Modules\Providers\DTO\ClientSpec;
use App\Modules\Providers\DTO\Expiry;
use App\Modules\Providers\DTO\PanelStatus;
use App\Modules\Providers\Enums\ConnectionFailure;
use App\Modules\Providers\Enums\CoreState;
use App\Modules\Providers\Exceptions\ConnectionException;
use App\Modules\Providers\Exceptions\NotFoundException;
use App\Modules\Providers\Exceptions\PanelApiException;
use App\Modules\Providers\Exceptions\UnsupportedOperationException;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Models\Subscription;

/**
 * Panels that remember what was asked of them — one per Server with `driver => 'fake'`, each with its
 * own clients, so a service can move between two of them: the clients of FakePanelDriver, which
 * TestCase::fakePanel() registers. The static state is per test process, reset in TestCase::tearDown().
 */
final class FakeProvider implements ProviderInterface
{
    /** What the fake panels can do; a test narrows it to play panels without inbounds or rotation. */
    public static Capabilities $capabilities;

    /** Where the panels serve subscriptions; null plays panels with their subscription server off. */
    public static ?string $subscriptionBase = 'https://fake.test/sub/';

    /** Whether a new client gets a subscription id (3x-ui mints one); false plays a panel that does not. */
    public static bool $mintsSubId = true;

    /**
     * Whether the panels start a term at the client's first connection; false plays panels that cannot: a term a client
     * is given becomes a deadline that far from now, as such a panel sets it.
     */
    public static bool $defersClock = true;

    /** Plays panels that cannot be reached: every read or write throws. */
    public static bool $unreachable = false;

    /** Whether the panels list their clients at once; false plays a panel that is asked for each. */
    public static bool $lists = true;

    /** @var list<int> Servers whose panel alone cannot be reached */
    public static array $down = [];

    /** @var array<int, list<string>> Per server, the calls its panel refuses (with a PanelApiException) */
    public static array $refusing = [];

    /** @var array<int, array<string, ConnectionFailure>> Per server, the calls whose connection fails, and how */
    public static array $failing = [];

    /** @var array<int, list<string>> Per server, the calls its panel carries out though their answer never comes (a timeout once sent) */
    public static array $unanswered = [];

    /**
     * @var array<int, list<string>> Per server, the writes its panel carries out whose read of the client after them is
     *                               refused: a failure after the write, as a connector marks one (ProviderException::afterWrite())
     */
    public static array $unread = [];

    /** @var array<int, array<string, ClientInfo>> The clients on each panel: server id → name → client */
    public static array $clients = [];

    /** @var array<int, array<string, list<string>>> A client's proxy credentials (a config's id, password), per server and name */
    public static array $credentials = [];

    /** @var list<string> Clients the panels report connected right now */
    public static array $online = [];

    /** Runs while a panel lists its clients, after it read them: a change elsewhere meanwhile (a grant, a move). */
    public static ?\Closure $whileListing = null;

    /**
     * Runs as a call reaches a panel, before it is answered, given the server's id and the call (`deleteClient`…): a change
     * made elsewhere between two calls of the shop — a client removed on the panel by hand, its link moved.
     *
     * @var (\Closure(int, string): void)|null
     */
    public static ?\Closure $onCall = null;

    /** @var list<array{server: int, inbounds: list<string>, spec: ClientSpec}> */
    public static array $created = [];

    /** @var list<ClientSpec> */
    public static array $updated = [];

    /** @var list<string> Clients whose credentials were rotated, in order */
    public static array $rotated = [];

    /** @var list<array{name: string, enabled: bool}> */
    public static array $enabled = [];

    /** @var list<array{server: int, name: string}> */
    public static array $deleted = [];

    /** @var list<string> */
    public static array $trafficReset = [];

    /** @var array<int, array<string, string>> The subscription id minted per server and client name */
    private static array $subIds = [];

    public function __construct(public readonly Server $server) {}

    /** The connector a server names to be on a fake panel (Server::$driver). */
    public static function driver(): string
    {
        return FakePanelDriver::KEY;
    }

    public static function reset(): void
    {
        self::$capabilities = new Capabilities(inbounds: true, linkRotation: true, ipLimit: true);
        self::$subscriptionBase = 'https://fake.test/sub/';
        self::$mintsSubId = true;
        self::$defersClock = true;
        self::$unreachable = false;
        self::$lists = true;
        self::$down = [];
        self::$refusing = [];
        self::$failing = [];
        self::$unanswered = [];
        self::$unread = [];
        self::$clients = [];
        self::$credentials = [];
        self::$online = [];
        self::$whileListing = null;
        self::$onCall = null;
        self::$created = [];
        self::$updated = [];
        self::$rotated = [];
        self::$enabled = [];
        self::$deleted = [];
        self::$trafficReset = [];
        self::$subIds = [];
    }

    /** Put a client on a server's panel as a test wants it (counters, last connection, flags, link). */
    public static function put(Server $server, ClientInfo $client): void
    {
        self::$clients[$server->id][$client->name] = $client;
    }

    /**
     * The service's client on its server's panel, as the shop's row has it: its quota, counters, term, link and — where
     * the panel keeps one (Capabilities::$ipLimit) — device limit (a counter a fixture left out is the column's 0).
     */
    public static function mirror(Subscription $subscription, bool $enabled = true): void
    {
        self::put($subscription->server, new ClientInfo(
            name: $subscription->remote_name,
            enabled: $enabled,
            uploadBytes: (int) $subscription->upload_bytes,
            downloadBytes: (int) $subscription->download_bytes,
            totalBytes: $subscription->traffic_limit_bytes,
            expiry: match (true) {
                $subscription->expires_at !== null => Expiry::at($subscription->expires_at->toDateTimeImmutable()),
                $subscription->duration_hours > 0 => Expiry::afterFirstUse($subscription->duration_hours * 3600),
                default => Expiry::never(),
            },
            subscriptionUrl: $subscription->subscription_url,
            ipLimit: self::$capabilities->ipLimit ? (int) $subscription->ip_limit : null,
        ));
    }

    /** @return list<string> The clients the panels were told about (updateClient()), in order. */
    public static function updatedNames(): array
    {
        return array_map(static fn(ClientSpec $spec): string => $spec->name, self::$updated);
    }

    /** What the panels were last told about a client. */
    public static function lastUpdate(string $name): ClientSpec
    {
        $specs = array_filter(self::$updated, static fn(ClientSpec $spec): bool => $spec->name === $name);

        return $specs === [] ? throw new \OutOfRangeException("No panel was told about {$name}.") : $specs[array_key_last($specs)];
    }

    public function testConnection(): void
    {
        $this->answer(__FUNCTION__);
    }

    public function status(): PanelStatus
    {
        $this->answer(__FUNCTION__);

        return new PanelStatus(1.0, 1, 2, 1, 2, CoreState::Running, 'Fake');
    }

    public function listInbounds(): array
    {
        $this->answer(__FUNCTION__);

        return [];
    }

    public function servesSubscriptions(): bool
    {
        $this->answer(__FUNCTION__);

        return self::$subscriptionBase !== null;
    }

    /**
     * Like a real panel: a name its rule (Capabilities::$names) does not allow is refused, and so is one a client there
     * has already (3x-ui's "Duplicate email", PasarGuard's 409); made switched on or off as the spec says, the IP limit
     * kept where it holds one.
     */
    public function createClient(array $inboundKeys, ClientSpec $spec): ClientInfo
    {
        $this->answer(__FUNCTION__);
        if (!self::$capabilities->names->allows($spec->name)) {
            throw new PanelApiException("The fake panel refused the name {$spec->name}.", 200, 'invalid username');
        }
        if (isset(self::$clients[$this->server->id][$spec->name])) {
            throw new PanelApiException("The fake panel has a client named {$spec->name} already.", 200, 'duplicate name');
        }
        self::$created[] = ['server' => $this->server->id, 'inbounds' => array_values($inboundKeys), 'spec' => $spec];

        // Like 3x-ui: the subscription id is minted by the panel, and the link follows its settings.
        if (self::$mintsSubId) {
            self::$subIds[$this->server->id][$spec->name] = 'sub-' . count(self::$created);
        }

        $made = self::$clients[$this->server->id][$spec->name] = new ClientInfo(
            name: $spec->name,
            enabled: $spec->enabled,
            totalBytes: $spec->totalBytes,
            expiry: self::kept($spec->expiry),
            subscriptionUrl: $this->linkOf($spec->name),
            ipLimit: self::ipLimitOf($spec),
        );
        $this->unread(__FUNCTION__);

        return $made;
    }

    public function updateClient(ClientSpec $spec): ClientInfo
    {
        $this->answer(__FUNCTION__);
        $client = $this->existing($spec->name);
        self::$updated[] = $spec;
        $updated = self::$clients[$this->server->id][$spec->name] = self::changed($client, ['enabled' => $spec->enabled, 'totalBytes' => $spec->totalBytes, 'expiry' => self::kept($spec->expiry), 'ipLimit' => self::ipLimitOf($spec)]);
        $this->unanswered(__FUNCTION__);
        $this->unread(__FUNCTION__);

        return $updated;
    }

    public function rotateClientCredentials(string $name): ClientInfo
    {
        $this->answer(__FUNCTION__);
        if (!self::$capabilities->linkRotation) {
            throw new UnsupportedOperationException('The fake panel cannot rotate credentials.');
        }

        $client = $this->existing($name);
        self::$rotated[] = $name;
        self::$subIds[$this->server->id][$name] = 'sub-rotated-' . count(self::$rotated);

        return self::$clients[$this->server->id][$name] = self::changed($client, ['subscriptionUrl' => $this->linkOf($name)]);
    }

    public function setClientEnabled(string $name, bool $enabled): void
    {
        $this->answer(__FUNCTION__);
        $client = $this->existing($name);
        self::$enabled[] = ['name' => $name, 'enabled' => $enabled];

        self::$clients[$this->server->id][$name] = self::changed($client, ['enabled' => $enabled]);
    }

    public function deleteClient(string $name): void
    {
        $this->answer(__FUNCTION__);
        $this->existing($name);
        self::$deleted[] = ['server' => $this->server->id, 'name' => $name];
        unset(self::$clients[$this->server->id][$name], self::$subIds[$this->server->id][$name]);
    }

    public function findClient(string $name, bool $presence = false): ?ClientInfo
    {
        $this->answer(__FUNCTION__);
        $client = self::$clients[$this->server->id][$name] ?? null;

        return $client === null ? null : self::changed($client, ['online' => $presence ? in_array($name, self::$online, true) : null]);
    }

    public function listClients(): array
    {
        $this->answer(__FUNCTION__);
        if (!self::$lists) {
            throw new UnsupportedOperationException('The fake panel cannot list its clients.');
        }

        $clients = array_values(self::$clients[$this->server->id] ?? []);
        if (self::$whileListing !== null) {
            (self::$whileListing)();
        }

        return $clients;
    }

    /** Like 3x-ui: the client whose link ends in the link's token, or one of whose `$credentials` the config carries. */
    public function findClientByLink(ClientLink $link): ?ClientInfo
    {
        $this->answer(__FUNCTION__);
        foreach (self::$clients[$this->server->id] ?? [] as $name => $client) {
            $token = basename((string) parse_url((string) $client->subscriptionUrl, PHP_URL_PATH));
            if ($link->hasToken($token) || $link->hasCredential(...self::$credentials[$this->server->id][$name] ?? [])) {
                return self::changed($client, []);
            }
        }

        return null;
    }

    public function resetClientTraffic(string $name): void
    {
        $this->answer(__FUNCTION__);
        $client = $this->existing($name);
        self::$trafficReset[] = $name;

        self::$clients[$this->server->id][$name] = self::changed($client, ['uploadBytes' => 0, 'downloadBytes' => 0]);
    }

    /**
     * The client as the panel has it, with `$changes` — its presence is findClient()'s to say (`$online`), never kept.
     *
     * @param array<string, mixed> $changes ClientInfo's arguments by name
     */
    private static function changed(ClientInfo $client, array $changes): ClientInfo
    {
        return new ClientInfo(...array_replace(get_object_vars($client), ['online' => null], $changes));
    }

    private function existing(string $name): ClientInfo
    {
        return self::$clients[$this->server->id][$name] ?? throw new NotFoundException("The fake panel has no client \"{$name}\".");
    }

    /** The spec's IP limit as the panel keeps it: like 3x-ui where it holds one (Capabilities::$ipLimit), else none. */
    private static function ipLimitOf(ClientSpec $spec): ?int
    {
        return self::$capabilities->ipLimit ? $spec->ipLimit : null;
    }

    /** The term as the panel keeps it: one that cannot defer the clock (`$defersClock` false) starts it now. */
    private static function kept(Expiry $expiry): Expiry
    {
        $pending = $expiry->pendingSeconds();

        return self::$defersClock || $pending === null ? $expiry : Expiry::at(now()->addSeconds($pending)->toDateTimeImmutable());
    }

    /** The link this panel would serve: its subscription base plus the id it minted, none without either. */
    private function linkOf(string $name): ?string
    {
        $subId = self::$subIds[$this->server->id][$name] ?? null;

        return self::$subscriptionBase === null || $subId === null ? null : self::$subscriptionBase . $subId;
    }

    /** A call the panel carried out whose answer never comes (`$unanswered`): the connection timed out once it was sent. */
    private function unanswered(string $call): void
    {
        if (in_array($call, self::$unanswered[$this->server->id] ?? [], true)) {
            throw new ConnectionException(ConnectionFailure::Timeout, 'Operation timed out after the request was sent');
        }
    }

    /** A write the panel carried out whose read of the client after it is refused (`$unread`): a failure after the write. */
    private function unread(string $call): void
    {
        if (in_array($call, self::$unread[$this->server->id] ?? [], true)) {
            throw (new PanelApiException("The fake panel refused the read after {$call}.", 200, 'refused by the test'))->afterWrite();
        }
    }

    /** What every call meets first: a panel that cannot be reached, a call whose connection fails, or one the panel refuses. */
    private function answer(string $call): void
    {
        if (self::$onCall !== null) {
            (self::$onCall)($this->server->id, $call);
        }
        if (self::$unreachable || in_array($this->server->id, self::$down, true)) {
            throw new ConnectionException(ConnectionFailure::Timeout, 'Connection timed out');
        }
        $failing = self::$failing[$this->server->id][$call] ?? null;
        if ($failing !== null) {
            throw new ConnectionException($failing, 'Connection failed');
        }
        if (in_array($call, self::$refusing[$this->server->id] ?? [], true)) {
            throw new PanelApiException("The fake panel refused {$call}.", 200, 'refused by the test');
        }
    }
}
