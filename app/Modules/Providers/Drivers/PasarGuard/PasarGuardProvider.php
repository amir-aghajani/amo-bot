<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\PasarGuard;

use App\Modules\Providers\Contracts\ProviderInterface;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\DTO\ClientSpec;
use App\Modules\Providers\DTO\Expiry;
use App\Modules\Providers\DTO\InboundInfo;
use App\Modules\Providers\DTO\PanelStatus;
use App\Modules\Providers\Enums\CoreState;
use App\Modules\Providers\Exceptions\NotFoundException;
use App\Modules\Providers\Exceptions\UnsupportedOperationException;
use App\Modules\Providers\Support\PanelConnection;
use App\Modules\Providers\Support\Raw;
use App\Support\Text;

/**
 * ProviderInterface for PasarGuard panels (3.1 and later), on PasarGuardClient — the client of one server's panel,
 * built by PasarGuardDriver::connect().
 *
 * A client is a PasarGuard user, addressed by username (the contract's `name`) through /api/user/by-username (the bare
 * /api/user/{x} turns id-based in v6). The shop's "inbounds" are PasarGuard's groups: a user reaches inbounds only
 * through its groups (`group_ids`), so a group's id is the inbound key and a plan entry sells groups. A term from the
 * first connection is PasarGuard's "on hold" (`on_hold_expire_duration`); a deadline is its `expire`. PasarGuard has no
 * per-client IP limit (its HWID device limit is another rule), so ClientSpec::$ipLimit is not sent.
 *
 * The subscription link is the user's `subscription_url`, made absolute against the panel's address when it is a path.
 * PasarGuard signs a fresh token into every answer, each valid until the link is revoked, so the link a sync brings is
 * a new one that works alongside the old.
 */
final class PasarGuardProvider implements ProviderInterface
{
    /** How recently PasarGuard must have seen a user's traffic to count it online — its dashboard's window. */
    private const ONLINE_WINDOW_SECONDS = 120;

    /** Users read per request when the whole panel is listed. */
    private const PAGE_SIZE = 500;

    /** The most pages one listing reads (50,000 users), so a misbehaving panel cannot keep a sync paging forever. */
    private const MAX_PAGES = 100;

    /** The longest inbound tag the shop keeps (server_inbounds.tag). */
    private const TAG_MAX = 128;

    private const USERS = '/api/user/by-username/';

    public function __construct(
        private readonly PasarGuardClient $client,
        private readonly PanelConnection $connection,
    ) {}

    /** One user read: proves the credentials and the users permission every sale needs. */
    public function testConnection(): void
    {
        $this->client->get('/api/users', ['limit' => 1]);
    }

    /**
     * The panel host's vitals. The proxy cores run on PasarGuard's nodes, which are not read (their rows carry each
     * node's own key), so the core's state is unknown.
     */
    public function status(): PanelStatus
    {
        $system = $this->client->get('/api/system');

        return new PanelStatus(
            cpuPercent: Raw::float($system, 'cpu_usage'),
            memoryUsedBytes: Raw::int($system, 'mem_used'),
            memoryTotalBytes: Raw::int($system, 'mem_total'),
            diskUsedBytes: Raw::int($system, 'disk_used'),
            diskTotalBytes: Raw::int($system, 'disk_total'),
            coreState: CoreState::Unknown,
            uptimeSeconds: Raw::int($system, 'uptime_seconds'),
        );
    }

    /** Every group the admin may use, as an inbound: its name as the title, its inbound tags as the tag; no protocol or port. */
    public function listInbounds(): array
    {
        return array_map(static fn(array $group): InboundInfo => new InboundInfo(
            key: (string) Raw::int($group, 'id'),
            tag: Text::fit(implode(', ', Raw::stringsOf($group['inbound_tags'] ?? null)), self::TAG_MAX),
            remark: Raw::string($group, 'name'),
            enabled: ($group['is_disabled'] ?? false) !== true,
            clientCount: Raw::int($group, 'total_users'),
        ), Raw::objectsOf($this->client->get('/api/groups')['groups'] ?? null));
    }

    /** PasarGuard has no switch for its subscription server: every user it answers about carries a link. */
    public function servesSubscriptions(): bool
    {
        return true;
    }

    public function createClient(array $inboundKeys, ClientSpec $spec): ClientInfo
    {
        if ($inboundKeys === []) {
            throw new \InvalidArgumentException('A PasarGuard user reaches inbounds through groups: pass at least one group key.');
        }

        $user = $this->client->post('/api/user', [
            'username' => $spec->name,
            'group_ids' => array_values(array_unique(array_map(intval(...), $inboundKeys))),
            'data_limit' => $spec->totalBytes,
            'data_limit_reset_strategy' => 'no_reset',
        ] + self::term($spec->expiry) + self::note($spec));

        return $this->clientOf($user, online: null);
    }

    /** One PUT: the panel keeps the user's groups, credentials and link, and its own reset strategy. */
    public function updateClient(ClientSpec $spec): ClientInfo
    {
        $user = $this->client->put(self::USERS . rawurlencode($spec->name), [
            'data_limit' => $spec->totalBytes,
        ] + self::term($spec->expiry) + self::note($spec));

        return $this->clientOf($user, online: null);
    }

    /** revoke_sub: fresh proxy credentials and a new link; every link signed before stops working. */
    public function rotateClientCredentials(string $name): ClientInfo
    {
        return $this->clientOf($this->client->post(self::USERS . rawurlencode($name) . '/revoke_sub'), online: null);
    }

    /**
     * Switching off is one PUT. Switching on reads the user first: one whose term never started goes back on hold with
     * it, sent explicitly — a panel before 5.0 would otherwise make it active with no end at all.
     */
    public function setClientEnabled(string $name, bool $enabled): void
    {
        $path = self::USERS . rawurlencode($name);
        if (!$enabled) {
            $this->client->put($path, ['status' => 'disabled']);

            return;
        }

        $user = $this->client->get($path);
        if (Raw::string($user, 'status') !== 'disabled') {
            return;
        }

        $pending = self::waitingTerm($user);
        $this->client->put($path, $pending !== null ? ['status' => 'on_hold', 'on_hold_expire_duration' => $pending] : ['status' => 'active']);
    }

    public function deleteClient(string $name): void
    {
        $this->client->delete(self::USERS . rawurlencode($name));
    }

    /** Presence comes with the user: seen within PasarGuard's own window. */
    public function findClient(string $name, bool $presence = false): ?ClientInfo
    {
        try {
            $user = $this->client->get(self::USERS . rawurlencode($name));
        } catch (NotFoundException) {
            return null;
        }

        $seen = self::moment($user['online_at'] ?? null);

        return $this->clientOf($user, online: $presence ? $seen !== null && $seen->getTimestamp() >= now()->getTimestamp() - self::ONLINE_WINDOW_SECONDS : null);
    }

    /**
     * Page by page, ordered by the unique username so a page boundary never shifts on a stable panel, until a short page
     * or the reported total. A panel past MAX_PAGES — or one that never stops answering full pages — is not listed at
     * once: the sync then asks for each service on its own.
     */
    public function listClients(): array
    {
        $clients = [];
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $answer = $this->client->get('/api/users', ['offset' => $page * self::PAGE_SIZE, 'limit' => self::PAGE_SIZE, 'sort' => 'username', 'load_sub' => 'true']);
            $users = Raw::objectsOf($answer['users'] ?? null);
            foreach ($users as $user) {
                $client = $this->clientOf($user, online: null);
                $clients[$client->name] = $client;
            }
            if (count($users) < self::PAGE_SIZE || count($clients) >= Raw::int($answer, 'total')) {
                return array_values($clients);
            }
        }

        throw new UnsupportedOperationException(sprintf('The PasarGuard panel lists more than %d users; its clients are read one by one.', self::MAX_PAGES * self::PAGE_SIZE));
    }

    public function resetClientTraffic(string $name): void
    {
        $this->client->post(self::USERS . rawurlencode($name) . '/reset');
    }

    /**
     * The term of a client switched on, as PasarGuard words it: on hold with its duration, or active with a deadline —
     * `expire: 0` says "none" (null would mean "leave it").
     *
     * @return array<string, int|string>
     */
    private static function term(Expiry $expiry): array
    {
        $pending = $expiry->pendingSeconds();

        return [
            'status' => $pending !== null ? 'on_hold' : 'active',
            'expire' => $expiry->deadline()?->getTimestamp() ?? 0,
        ] + ($pending !== null ? ['on_hold_expire_duration' => $pending] : []);
    }

    /**
     * The comment (which carries the Telegram id) as the user's note — PasarGuard users have no Telegram field; nothing
     * to say leaves the panel's note alone.
     *
     * @return array{note?: string}
     */
    private static function note(ClientSpec $spec): array
    {
        $note = $spec->comment ?? ($spec->telegramId !== null ? (string) $spec->telegramId : null);

        return $note !== null ? ['note' => $note] : [];
    }

    /**
     * A user as the contract's client. PasarGuard counts one total, reported as download (the shop adds the two).
     *
     * @param array<string, mixed> $user
     */
    private function clientOf(array $user, ?bool $online): ClientInfo
    {
        $pending = self::waitingTerm($user);
        $deadline = self::moment($user['expire'] ?? null);

        return new ClientInfo(
            name: Raw::string($user, 'username'),
            enabled: Raw::string($user, 'status') !== 'disabled',
            downloadBytes: Raw::int($user, 'used_traffic'),
            totalBytes: Raw::int($user, 'data_limit'),
            expiry: match (true) {
                $pending !== null => Expiry::afterFirstUse($pending),
                $deadline !== null => Expiry::at($deadline),
                default => Expiry::never(),
            },
            subscriptionUrl: $this->linkOf(Raw::string($user, 'subscription_url')),
            online: $online,
            lastOnlineAt: self::moment($user['online_at'] ?? null),
        );
    }

    /**
     * The term still waiting for the user's first connection, in seconds: on hold, or switched off before its clock
     * started (switched on, it goes back on hold); null otherwise. A duration on an active user is a leftover the panel
     * cannot clear, not a term.
     *
     * @param array<string, mixed> $user
     */
    private static function waitingTerm(array $user): ?int
    {
        $seconds = Raw::int($user, 'on_hold_expire_duration');
        $waits = $seconds > 0 && ($user['expire'] ?? null) === null && in_array(Raw::string($user, 'status'), ['on_hold', 'disabled'], true);

        return $waits ? $seconds : null;
    }

    /**
     * The link the customer gets: the panel's, made absolute against the panel's address when it is a path, or the
     * server's own prefix in front of its token when one is set.
     */
    private function linkOf(string $url): ?string
    {
        if ($url === '') {
            return null;
        }
        if ($this->connection->subscriptionUrl !== null) {
            return $this->connection->subscriptionUrl . '/' . basename((string) parse_url($url, PHP_URL_PATH));
        }

        return str_starts_with($url, '/') ? $this->connection->baseUrl . $url : $url;
    }

    /** A moment PasarGuard wrote (ISO 8601, UTC when it names no zone), or null. */
    private static function moment(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
