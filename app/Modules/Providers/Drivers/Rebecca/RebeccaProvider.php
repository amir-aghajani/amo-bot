<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\Rebecca;

use App\Modules\Providers\Contracts\ProviderInterface;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\DTO\ClientLink;
use App\Modules\Providers\DTO\ClientSpec;
use App\Modules\Providers\DTO\Expiry;
use App\Modules\Providers\DTO\InboundInfo;
use App\Modules\Providers\DTO\PanelStatus;
use App\Modules\Providers\Enums\CoreState;
use App\Modules\Providers\Exceptions\NotFoundException;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Providers\Exceptions\UnsupportedOperationException;
use App\Modules\Providers\Support\PanelConnection;
use App\Modules\Providers\Support\Raw;
use App\Modules\Providers\Support\SubscriptionPath;

/**
 * ProviderInterface for Rebecca panels (1.0 and later, the Go panel with API keys), on RebeccaClient — the client of one
 * server's panel, built by RebeccaDriver::connect().
 *
 * A client is a Rebecca user, addressed by username (the contract's `name`; Rebecca matches it whatever its case)
 * through /api/user/{username}. Rebecca puts each user in one service — its hosts are what the user's link carries — and
 * takes no inbounds of a user's own: the shop's inbounds are its services, keyed by id, and a client is made in the one
 * its plan picks. A term from the first connection is Rebecca's "on hold" (`on_hold_expire_duration`); a deadline is its
 * `expire` in unix seconds, 0 for none; the device limit is its `ip_limit`, which its nodes enforce. An update changes
 * only the fields it sends, so a user's next plans and the rest stay as the panel has them.
 *
 * A standard or reseller admin sees only the users it made: its panel is not listed at once (listClients()), and another
 * admin's user is refused with what to do about it (RebeccaApiException::refused()).
 *
 * The subscription link is the user's `subscription_url` — of the form the panel's settings prefer —, made absolute
 * against the panel's address when it is a path. Revoking it gives the user a new credential key: every link and config
 * handed out before stops working.
 */
final class RebeccaProvider implements ProviderInterface
{
    /** Users read per request when the whole panel is listed. */
    private const PAGE_SIZE = 500;

    /** The most pages one listing reads (50,000 users), so a misbehaving panel cannot keep a sync paging forever. */
    private const MAX_PAGES = 100;

    private const USER = '/api/user/';

    /** The roles that see every user of the panel; a standard or reseller admin sees its own. */
    private const GLOBAL_ROLES = ['sudo', 'full_access'];

    /**
     * What a subscription link's identifier looks like: its signed token (base64url, its signature after it), or the
     * user's 32-hex credential key — at least 15 characters: the panel reads nothing shorter.
     */
    private const TOKEN = '/^[A-Za-z0-9_-]+$/';
    private const TOKEN_MIN = 15;

    /** A VLESS or VMess config's id: the user's credential key written as a UUID (dashes optional). */
    private const UUID = '/^[0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12}$/';

    /** The most parts of a link shown to the panel's subscription route at one try: each is a request. */
    private const TOKENS_MAX = 4;

    /** The longest note Rebecca keeps, in bytes. */
    private const NOTE_MAX = 500;

    /** Whether the admin of the key sees every user of the panel, once asked (global()). */
    private ?bool $global = null;

    public function __construct(
        private readonly RebeccaClient $client,
        private readonly PanelConnection $connection,
    ) {}

    /** One user read: proves the key (any admin of the panel may read users of its own). */
    public function testConnection(): void
    {
        $this->client->get('/api/users', ['limit' => 1]);
    }

    /** The panel host's vitals and its Xray core, as its dashboard shows them. */
    public function status(): PanelStatus
    {
        $system = $this->client->get('/api/system');
        $memory = Raw::array($system, 'memory');
        $disk = Raw::array($system, 'disk');

        return new PanelStatus(
            cpuPercent: Raw::float($system, 'cpu_usage'),
            memoryUsedBytes: Raw::int($memory, 'current'),
            memoryTotalBytes: Raw::int($memory, 'total'),
            diskUsedBytes: Raw::int($disk, 'current'),
            diskTotalBytes: Raw::int($disk, 'total'),
            coreState: Raw::bool($system, 'xray_running') ? CoreState::Running : CoreState::Stopped,
            coreName: 'Xray',
            coreVersion: Raw::string($system, 'xray_version'),
            coreError: Raw::string($system, 'last_xray_error'),
            uptimeSeconds: Raw::int($system, 'uptime_seconds'),
        );
    }

    /**
     * Every service the admin of the key may put users in, keyed by its id and titled by its name, with its users. One
     * without an active host takes no user: switched off.
     */
    public function listInbounds(): array
    {
        return array_map(static fn(array $service): InboundInfo => new InboundInfo(
            key: (string) Raw::int($service, 'id'),
            tag: Raw::string($service, 'name'),
            remark: Raw::string($service, 'name'),
            enabled: Raw::int($service, 'host_count') > 0 && !Raw::bool($service, 'broken'),
            clientCount: Raw::int($service, 'user_count'),
        ), Raw::objectsOf($this->client->get('/api/v2/services', ['limit' => 'null'])['services'] ?? null));
    }

    /** Rebecca has no switch for its subscription route: every user it answers about carries a link. */
    public function servesSubscriptions(): bool
    {
        return true;
    }

    public function createClient(array $inboundKeys, ClientSpec $spec): ClientInfo
    {
        $services = array_values(array_unique($inboundKeys));
        if ($services === []) {
            throw new \InvalidArgumentException('A Rebecca user is made in a service: pass its id.');
        }
        if (count($services) > 1) {
            throw RebeccaApiException::severalServices();
        }

        $user = $this->answered($this->client->post('/api/user', [
            'username' => $spec->name,
            'service_id' => (int) $services[0],
            'data_limit' => $spec->totalBytes,
            'data_limit_reset_strategy' => 'no_reset',
            'ip_limit' => $spec->ipLimit,
        ] + self::term($spec->expiry) + self::note($spec)), $spec->name);
        if (!$spec->enabled) {
            $user = $this->switchedOff($spec->name);
        }

        return $this->clientOf($user, online: null);
    }

    /**
     * A user just made, switched off — Rebecca makes a user active or on hold only —, its term kept as it was made (a
     * duration still waiting stays on it, for setClientEnabled() to put back on hold). One the panel will not switch off
     * is taken back: nothing was made. One it will not take back either is there switched on, a failure after the write
     * (ProviderException::afterWrite()).
     *
     * @return array<string, mixed> The user as the panel has it then
     * @throws ProviderException
     */
    private function switchedOff(string $name): array
    {
        $path = self::USER . rawurlencode($name);
        try {
            return $this->answered($this->client->put($path, ['status' => 'disabled']), $name);
        } catch (ProviderException $e) {
            try {
                $this->client->delete($path);
            } catch (ProviderException) {
                throw $e->afterWrite();
            }

            throw $e;
        }
    }

    /**
     * The user read, then one PUT with the limits, term, switch and note: the panel keeps the user's service, link and next
     * plans. The read says whether a deadline is to be taken off (term()). The traffic is the term's alone — no reset on a
     * schedule the panel kept (an adopted user's): the quota is once a term.
     */
    public function updateClient(ClientSpec $spec): ClientInfo
    {
        $path = self::USER . rawurlencode($spec->name);
        $user = $this->client->put($path, [
            'data_limit' => $spec->totalBytes,
            'data_limit_reset_strategy' => 'no_reset',
            'ip_limit' => $spec->ipLimit,
        ] + self::term($spec->expiry, $spec->enabled, self::deadline($this->client->get($path)) !== null) + self::note($spec));

        return $this->clientOf($this->answered($user, $spec->name), online: null);
    }

    /** revoke_sub: a new credential key, so new configs and a new link; every one handed out before stops working. */
    public function rotateClientCredentials(string $name): ClientInfo
    {
        return $this->clientOf($this->answered($this->client->post(self::USER . rawurlencode($name) . '/revoke_sub'), $name), online: null);
    }

    /**
     * The user read, then switched — once it is not as asked already. Switched on, one whose term never started goes back
     * on hold with it (waitingTerm()), sent explicitly: plain "active" would leave it with no end at all. Any other goes
     * active with its own limit and deadline sent back (those it has: a 0 asks the admin's leave for an unlimited one), so
     * the panel finds it limited or expired at once, not at its next review.
     */
    public function setClientEnabled(string $name, bool $enabled): void
    {
        $path = self::USER . rawurlencode($name);
        $user = $this->client->get($path);
        $off = Raw::string($user, 'status') === 'disabled';
        if ($off === !$enabled) {
            return;
        }

        $pending = self::waitingTerm($user);
        $limit = Raw::int($user, 'data_limit');
        $deadline = self::deadline($user);
        $this->client->put($path, match (true) {
            !$enabled => ['status' => 'disabled'],
            $pending !== null => ['status' => 'on_hold', 'on_hold_expire_duration' => $pending],
            default => ['status' => 'active'] + ($limit > 0 ? ['data_limit' => $limit] : []) + ($deadline !== null ? ['expire' => $deadline->getTimestamp()] : []),
        });
    }

    /** Rebecca keeps a deleted user apart (its status «deleted»): gone for the API, its name free again. */
    public function deleteClient(string $name): void
    {
        $this->client->delete(self::USER . rawurlencode($name));
    }

    /** Presence is the panel's own: the users it counts online (seen within its last 20 seconds), asked about this one. */
    public function findClient(string $name, bool $presence = false): ?ClientInfo
    {
        try {
            $user = $this->client->get(self::USER . rawurlencode($name));
        } catch (NotFoundException) {
            return null;
        }

        return $this->clientOf($user, online: $presence ? $this->online(Raw::string($user, 'username', $name)) : null);
    }

    /**
     * Every user (users()), a client each — for an admin who sees them all: any other sees only the users it made, so its
     * panel is not listed at once and the caller asks for each client (another admin's then refused, in words that say
     * what to do). The listing leaves out a user's device limit (ClientInfo::$ipLimit null) and a term still waiting, so
     * a user whose term may wait is read on its own.
     */
    public function listClients(): array
    {
        if (!$this->global()) {
            throw new UnsupportedOperationException('The Rebecca admin of the key is no sudo or full-access admin and lists only the users it made; its clients are read one by one.');
        }

        $clients = [];
        foreach ($this->users() as $user) {
            $name = Raw::string($user, 'username');
            $client = self::mayWait($user) ? $this->findClient($name) : $this->clientOf($user, online: null);
            if ($client !== null) {
                $clients[strtolower($client->name)] = $client;
            }
        }

        return array_values($clients);
    }

    /**
     * A link's identifiers (identifiers()) are shown to the panel's own subscription route, `…/{identifier}/info`, under
     * the path its links use, which answers the user they are of. A Trojan or Shadowsocks password is a hash of the key,
     * which nothing on the panel looks up: such a config is matched by no one.
     */
    public function findClientByLink(ClientLink $link): ?ClientInfo
    {
        $identifiers = self::identifiers($link);
        $path = $identifiers === [] ? null : $this->subscriptionPath();
        if ($path !== null) {
            foreach ($identifiers as $identifier) {
                $name = $this->owner($path, $identifier);
                if ($name !== '') {
                    return $this->findClient($name);
                }
            }
        }

        return null;
    }

    /** Usage set to zero; Rebecca puts a user it had limited for its traffic back to active, and leaves any other as it was. */
    public function resetClientTraffic(string $name): void
    {
        $this->client->post(self::USER . rawurlencode($name) . '/reset');
    }

    /** Whether the admin of the key sees every user (GET /api/admin: its role), asked once for the client's life. */
    private function global(): bool
    {
        return $this->global ??= in_array(Raw::string($this->client->get('/api/admin'), 'role'), self::GLOBAL_ROLES, true);
    }

    /**
     * Whether the panel counts `$name` online right now (GET /api/users/onlines: the online users among those named).
     *
     * @throws ProviderException
     */
    private function online(string $name): bool
    {
        $online = Raw::stringsOf($this->client->get('/api/users/onlines', ['usernames' => $name]));

        return in_array(strtolower($name), array_map(strtolower(...), $online), true);
    }

    /**
     * A user as a mutation answers it. Rebecca answers the user's detail; when it could not build that after a write that
     * went through, it says so (`detail`) and the user is read again — a failure then is one after the write.
     *
     * @param array<mixed> $answer
     * @return array<string, mixed>
     * @throws ProviderException
     */
    private function answered(array $answer, string $name): array
    {
        if (!isset($answer['detail'])) {
            return $answer;
        }

        try {
            return $this->client->get(self::USER . rawurlencode($name));
        } catch (ProviderException $e) {
            throw $e->afterWrite();
        }
    }

    /**
     * Every user, page by page, ordered by the unique username so a page boundary never shifts on a stable panel, until a
     * short page or the total the answer reports. A panel past MAX_PAGES — or one that never stops answering full pages —
     * is not listed at once: the sync then asks for each service on its own.
     *
     * @return \Generator<int, array<string, mixed>>
     * @throws UnsupportedOperationException past MAX_PAGES
     */
    private function users(): \Generator
    {
        $names = [];
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $answer = $this->client->get('/api/users', ['offset' => $page * self::PAGE_SIZE, 'limit' => self::PAGE_SIZE, 'sort' => 'username']);
            $users = Raw::objectsOf($answer['users'] ?? null);
            foreach ($users as $user) {
                $names[strtolower(Raw::string($user, 'username'))] = true;

                yield $user;
            }
            if (count($users) < self::PAGE_SIZE || count($names) >= Raw::int($answer, 'total', PHP_INT_MAX)) {
                return;
            }
        }

        throw new UnsupportedOperationException(sprintf('The Rebecca panel lists more than %d users; its clients are read one by one.', self::MAX_PAGES * self::PAGE_SIZE));
    }

    /**
     * The path the panel serves subscription links under (its «مسیر اشتراک», "sub" by default; a prefix the owner put in
     * front of the links is not), read off one user's token link (SubscriptionPath::find()). Null with no user to read,
     * or none answering.
     */
    private function subscriptionPath(): ?string
    {
        $user = Raw::objectsOf($this->client->get('/api/users', ['offset' => 0, 'limit' => 1])['users'] ?? null)[0] ?? [];
        $links = Raw::array($user, 'subscription_urls');
        $link = is_string($links['token'] ?? null) ? $links['token'] : Raw::string($user, 'subscription_url');
        $name = Raw::string($user, 'username');

        return SubscriptionPath::find($link, fn(string $path, string $token): bool => strcasecmp($this->owner($path, $token), $name) === 0);
    }

    /** The username the panel's subscription route under `$path` answers `$token` with (`user.username`); empty for none. */
    private function owner(string $path, string $token): string
    {
        return Raw::string(Raw::array($this->client->anonymous($path . '/' . $token . '/info') ?? [], 'user'), 'username');
    }

    /**
     * What of a link the panel's subscription route may know, TOKENS_MAX at most: a subscription link's token or
     * credential key, and a VLESS or VMess config's id — the user's credential key, written as a UUID.
     *
     * @return list<string>
     */
    private static function identifiers(ClientLink $link): array
    {
        $keys = array_map(static fn(string $id): string => str_replace('-', '', $id), array_filter($link->credentials, static fn(string $id): bool => preg_match(self::UUID, $id) === 1));
        $identifiers = array_filter([...$link->tokens, ...$keys], static fn(string $part): bool => strlen($part) >= self::TOKEN_MIN && preg_match(self::TOKEN, $part) === 1);

        return array_slice(array_values(array_unique($identifiers)), 0, self::TOKENS_MAX);
    }

    /**
     * The term of a client, as Rebecca words it: on hold with its duration, or active with a deadline. One kept switched
     * off is `disabled` with its term all the same: a term still waiting keeps its duration, which setClientEnabled() puts
     * back on hold. A user being changed (`$hadDeadline` said) has a duration a replaced term left cleared (0); its
     * deadline is sent to set one, or `expire: 0` to take off the one it `$hadDeadline` — never otherwise: Rebecca reads
     * `expire: 0` as unlimited validity, which an admin may not be allowed to give, a term on hold or not. A user made
     * without a deadline (and not on hold) is of unlimited validity: `expire: 0`, as Rebecca reads its absence anyway.
     *
     * @return array<string, int|string>
     */
    private static function term(Expiry $expiry, bool $enabled = true, ?bool $hadDeadline = null): array
    {
        $pending = $expiry->pendingSeconds();
        $deadline = $expiry->deadline()?->getTimestamp();
        $term = ['status' => match (true) {
            !$enabled => 'disabled',
            $pending !== null => 'on_hold',
            default => 'active',
        }];
        if ($pending !== null) {
            $term['on_hold_expire_duration'] = $pending;
        } elseif ($hadDeadline !== null) {
            $term['on_hold_expire_duration'] = 0;
        }
        if ($deadline !== null) {
            $term['expire'] = $deadline;
        } elseif ($hadDeadline === true || ($hadDeadline === null && $pending === null)) {
            $term['expire'] = 0;
        }

        return $term;
    }

    /**
     * The comment (which carries the Telegram id) as the user's note, cut to what Rebecca keeps — 500 bytes, trimmed, as
     * Go counts a string (a Persian letter is two), on a whole character —; nothing to say leaves the panel's note alone.
     *
     * @return array{note?: string}
     */
    private static function note(ClientSpec $spec): array
    {
        $note = $spec->comment ?? ($spec->telegramId !== null ? (string) $spec->telegramId : null);

        return $note !== null ? ['note' => mb_strcut(trim($note), 0, self::NOTE_MAX, 'UTF-8')] : [];
    }

    /**
     * A user as the contract's client. Rebecca counts one total, reported as download (the shop adds the two). Its device
     * limit is said by a user's own read, never by the list (null then).
     *
     * @param array<string, mixed> $user
     */
    private function clientOf(array $user, ?bool $online): ClientInfo
    {
        $pending = self::waitingTerm($user);
        $deadline = self::deadline($user);

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
            subscriptionUrl: $this->connection->subscriptionLink(Raw::string($user, 'subscription_url')),
            online: $online,
            lastOnlineAt: Raw::moment($user['online_at'] ?? null),
            ipLimit: array_key_exists('ip_limit', $user) ? max(0, Raw::int($user, 'ip_limit')) : null,
        );
    }

    /**
     * The term still waiting for the user's first connection, in seconds: on hold, or switched off before its clock
     * started (switched on, it goes back on hold); null otherwise. Rebecca clears the duration when the term starts, and
     * an update of the shop clears it with a deadline, so one left on a switched-off user without a deadline still waits.
     *
     * @param array<string, mixed> $user
     */
    private static function waitingTerm(array $user): ?int
    {
        $seconds = Raw::int($user, 'on_hold_expire_duration');
        $waits = $seconds > 0 && self::deadline($user) === null && in_array(Raw::string($user, 'status'), ['on_hold', 'disabled'], true);

        return $waits ? $seconds : null;
    }

    /**
     * Whether a listed user's term may be waiting — on hold, or switched off without a deadline —, which only its own read
     * says (the list leaves the duration out).
     *
     * @param array<string, mixed> $user
     */
    private static function mayWait(array $user): bool
    {
        $status = Raw::string($user, 'status');

        return $status === 'on_hold' || ($status === 'disabled' && self::deadline($user) === null);
    }

    /**
     * The user's deadline: unix seconds as Rebecca keeps it; 0, null or nothing is none.
     *
     * @param array<string, mixed> $user
     */
    private static function deadline(array $user): ?\DateTimeImmutable
    {
        return Raw::seconds(Raw::int($user, 'expire'));
    }
}
