<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\Remnawave;

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
use App\Modules\Providers\Exceptions\UnexpectedResponseException;
use App\Modules\Providers\Exceptions\UnsupportedOperationException;
use App\Modules\Providers\Support\PanelConnection;
use App\Modules\Providers\Support\Raw;

/**
 * ProviderInterface for Remnawave panels (2.1 and later, 3.x included), on RemnawaveClient — the client of one server's
 * panel, built by RemnawaveDriver::connect().
 *
 * A client is a Remnawave user, found by its username (the contract's `name`, matched as it is written) through
 * /api/users/by-username; its own routes then take what the read gave: its uuid on 2.x, its numeric id on 3.x (which
 * gave users ids and took their uuids away). The shop's inbounds are Remnawave's internal squads: a user reaches the
 * inbounds of the squads it is in, several at once, and an update leaves them as they are. Remnawave keeps a deadline
 * (`expireAt`, required) and no term waiting for the first connection: such a term starts as the client is made, and
 * the shop mirrors the deadline the panel then has. Its "never" is a deadline in 2099. A user's traffic is the term's
 * alone — no reset on a schedule. The plan's devices are the user's HWID device limit (`hwidDeviceLimit`, 0 for
 * none): Remnawave counts the devices that fetch the link with a device id, while its HWID limit is on in the panel's
 * subscription settings; null — the panel's own fallback — reads as unsaid.
 *
 * The panel switches a user to EXPIRED or LIMITED itself (its jobs, every 30 and 45 seconds) and back to ACTIVE when a
 * write gives it time or traffic again; a PATCH switches off only an active user, so one out of time or traffic is
 * switched off by its own action (setClientEnabled(), updateClient()).
 *
 * The subscription link is the user's `subscriptionUrl` (the panel's subscription domain and the user's short id), or
 * the server's own prefix before the short id. Revoking it gives the user a new short id, uuid and passwords: every
 * link and config handed out before stops working.
 */
final class RemnawaveProvider implements ProviderInterface
{
    /** The most users GET /api/users answers at once (its `size`). */
    private const PAGE_SIZE = 1000;

    /** The most pages one listing reads (50,000 users), so a misbehaving panel cannot keep a sync paging forever. */
    private const MAX_PAGES = 50;

    private const USERS = '/api/users';

    /** Remnawave's statuses: on, off by an admin, out of traffic, out of time — the last two its own to set. */
    private const ACTIVE = 'ACTIVE';
    private const DISABLED = 'DISABLED';
    private const LIMITED = 'LIMITED';
    private const EXPIRED = 'EXPIRED';

    /** How recently Remnawave must have seen a user's traffic to count it online — its dashboard's window. */
    private const ONLINE_WINDOW_SECONDS = 30;

    /**
     * Remnawave's "never": a deadline in 2099, which its subscription answers report as no end. A deadline from that year
     * on is read as none.
     */
    private const NEVER = '2099-12-31T00:00:00.000Z';
    private const NEVER_YEAR = 2099;

    /** Seconds from now to the soonest deadline an update sends: Remnawave refuses one that is not in the future. */
    private const SOONEST = 60;

    /** What a subscription link's short id looks like: Remnawave's letters (a nanoid, a uuid, or its own pattern). */
    private const SHORT_ID = '/^[A-Za-z0-9_-]{8,64}$/';

    /** The most parts of a link looked up as a short id at one try: each is a request. */
    private const SHORT_IDS_MAX = 4;

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private const HINT_VERSION = 'نسخه پنل باید 2.1 یا بالاتر باشد.';

    public function __construct(
        private readonly RemnawaveClient $client,
        private readonly PanelConnection $connection,
    ) {}

    /** One user read: proves the token and its scope over users. */
    public function testConnection(): void
    {
        $this->client->get(self::USERS, ['start' => 0, 'size' => 1]);
    }

    /**
     * The panel host's memory and uptime, as its dashboard's stats give them. Remnawave reports no CPU load nor disk, and
     * its Xray cores run on its nodes: their state is unknown here.
     */
    public function status(): PanelStatus
    {
        $stats = $this->client->get('/api/system/stats');
        $memory = Raw::array($stats, 'memory');

        return new PanelStatus(
            cpuPercent: null,
            memoryUsedBytes: Raw::int($memory, 'used'),
            memoryTotalBytes: Raw::int($memory, 'total'),
            diskUsedBytes: 0,
            diskTotalBytes: 0,
            coreState: CoreState::Unknown,
            uptimeSeconds: Raw::int($stats, 'uptime'),
        );
    }

    /**
     * Every internal squad, keyed by its uuid and titled by its name, its inbounds' tags as the tag, with its members. One
     * without an inbound gives a user nothing: switched off.
     */
    public function listInbounds(): array
    {
        return array_map(static function (array $squad): InboundInfo {
            $inbounds = Raw::objectsOf($squad['inbounds'] ?? null);
            $info = Raw::array($squad, 'info');

            return new InboundInfo(
                key: Raw::string($squad, 'uuid'),
                tag: implode(', ', Raw::stringsOf(array_column($inbounds, 'tag'))),
                remark: Raw::string($squad, 'name'),
                enabled: Raw::int($info, 'inboundsCount', count($inbounds)) > 0,
                clientCount: Raw::int($info, 'membersCount'),
            );
        }, Raw::objectsOf($this->client->get('/api/internal-squads')['internalSquads'] ?? null));
    }

    /** Remnawave has no switch for its subscriptions: every user it answers about carries a link. */
    public function servesSubscriptions(): bool
    {
        return true;
    }

    /**
     * One POST: the user in every squad asked for, switched on or off as it is made (Remnawave takes either), its term's
     * deadline — a term waiting for the first connection starts now — and the comment as its description.
     */
    public function createClient(array $inboundKeys, ClientSpec $spec): ClientInfo
    {
        $squads = array_values(array_unique($inboundKeys));
        if ($squads === []) {
            throw new \InvalidArgumentException('A Remnawave user reaches inbounds through internal squads: pass at least one squad\'s uuid.');
        }

        $user = $this->client->post(self::USERS, [
            'username' => $spec->name,
            'status' => $spec->enabled ? self::ACTIVE : self::DISABLED,
            'trafficLimitBytes' => max(0, $spec->totalBytes),
            'trafficLimitStrategy' => 'NO_RESET',
            'expireAt' => self::iso(self::endOf($spec->expiry)),
            'activeInternalSquads' => $squads,
            'hwidDeviceLimit' => max(0, $spec->ipLimit),
        ] + self::contact($spec));

        return $this->clientOf($user, online: null);
    }

    /**
     * The user read, then one PATCH with the limit (no reset on a schedule: the quota is once a term), the device limit,
     * the deadline (endFor()) and the comment; its squads and link stay. Kept off, it is sent switched off — one out of
     * time or traffic is switched off by its own action first: a PATCH switches off only an active user, and a higher
     * limit would put a limited one back on. Kept on, a switched-off one is sent back on, and one out of time or traffic
     * too when the new term gives it some (runs()) — otherwise the panel keeps it as it is, rather than turning it on for
     * its next review to turn off again.
     */
    public function updateClient(ClientSpec $spec): ClientInfo
    {
        $user = $this->user($spec->name);
        $status = Raw::string($user, 'status');
        if (!$spec->enabled && in_array($status, [self::LIMITED, self::EXPIRED], true)) {
            $this->switched($user, enabled: false);
            $status = self::DISABLED;
        }

        $end = self::endFor($spec->expiry, self::expireAt($user));
        $switch = match (true) {
            !$spec->enabled => ['status' => self::DISABLED],
            $status === self::DISABLED, $status !== self::ACTIVE && self::runs($spec, $user) => ['status' => self::ACTIVE],
            default => [],
        };

        return $this->clientOf($this->client->patch(self::USERS, self::identity($user) + [
            'trafficLimitBytes' => max(0, $spec->totalBytes),
            'trafficLimitStrategy' => 'NO_RESET',
            'hwidDeviceLimit' => max(0, $spec->ipLimit),
        ] + ($end !== null ? ['expireAt' => self::iso($end)] : []) + $switch + self::contact($spec)), online: null);
    }

    /** Its subscription revoked: a new short id — a new link —, uuid and passwords; every one handed out before stops working. */
    public function rotateClientCredentials(string $name): ClientInfo
    {
        return $this->clientOf($this->client->post($this->path($this->user($name)) . '/actions/revoke'), online: null);
    }

    /**
     * The user read, then switched by its own action — once it is not as asked already. Switched on, it is active, and
     * the panel turns it out of time or traffic again at its next review if its term says so; switched off, it is off
     * whatever it was. "On" is any status but DISABLED.
     */
    public function setClientEnabled(string $name, bool $enabled): void
    {
        $user = $this->user($name);
        if ((Raw::string($user, 'status') === self::DISABLED) === !$enabled) {
            return;
        }

        $this->switched($user, $enabled);
    }

    /** Remnawave deletes a user for good: its name is free again. */
    public function deleteClient(string $name): void
    {
        $this->client->delete($this->path($this->user($name)));
    }

    /** Presence comes with the user: traffic seen within Remnawave's own window. */
    public function findClient(string $name, bool $presence = false): ?ClientInfo
    {
        try {
            $user = $this->user($name);
        } catch (NotFoundException) {
            return null;
        }

        $seen = self::onlineAt($user);

        return $this->clientOf($user, online: $presence ? $seen !== null && $seen->getTimestamp() >= now()->getTimestamp() - self::ONLINE_WINDOW_SECONDS : null);
    }

    /** Every user (users()), a client each: the listing carries all a user's own read does. */
    public function listClients(): array
    {
        $clients = [];
        foreach ($this->users() as $user) {
            $client = $this->clientOf($user, online: null);
            $clients[$client->name] = $client;
        }

        return array_values($clients);
    }

    /**
     * A subscription link's parts are looked up as the user's short id (GET by-short-uuid, at most SHORT_IDS_MAX of
     * them). A config's credentials are matched against every user's: its VLESS uuid — whose third group a host's route
     * id may have replaced —, its Trojan password, its Shadowsocks password (a 2022 config carries it in base64). The
     * match is then read on its own.
     */
    public function findClientByLink(ClientLink $link): ?ClientInfo
    {
        $ids = array_slice(array_values(array_filter($link->tokens, static fn(string $token): bool => preg_match(self::SHORT_ID, $token) === 1)), 0, self::SHORT_IDS_MAX);
        foreach ($ids as $id) {
            try {
                $user = $this->client->get(self::USERS . '/by-short-uuid/' . rawurlencode($id));
            } catch (NotFoundException) {
                continue; // No user's short id: the link's next part.
            }
            if (Raw::string($user, 'username') !== '') {
                return $this->clientOf($user, online: null);
            }
        }

        $uuids = array_map(self::vlessKey(...), array_values(array_filter($link->credentials, static fn(string $credential): bool => preg_match(self::UUID, $credential) === 1)));
        foreach ($link->credentials === [] ? [] : $this->users() as $user) {
            if (in_array(self::vlessKey(Raw::string($user, 'vlessUuid')), $uuids, true) || $link->hasCredential(...self::passwordsOf($user))) {
                return $this->findClient(Raw::string($user, 'username'));
            }
        }

        return null;
    }

    /** Usage set to zero; Remnawave puts a user it had limited for its traffic back to active, and leaves any other as it was. */
    public function resetClientTraffic(string $name): void
    {
        $this->client->post($this->path($this->user($name)) . '/actions/reset-traffic');
    }

    /**
     * The user `$name`, read by its username.
     *
     * @return array<mixed>
     * @throws NotFoundException when the panel has none
     * @throws ProviderException
     */
    private function user(string $name): array
    {
        $user = $this->client->get(self::USERS . '/by-username/' . rawurlencode($name));

        // Something in front of the panel answering with nothing is no user: read as one, it would be a client with no
        // name, limit or end, which the sync would write onto the service.
        return Raw::string($user, 'username') === $name ? $user : throw new UnexpectedResponseException(200, 'an answer without the user asked for');
    }

    /**
     * The user switched on or off by its own action, which takes any status. One someone switched so in between counts
     * as done.
     *
     * @param array<string, mixed> $user
     * @throws ProviderException
     */
    private function switched(array $user, bool $enabled): void
    {
        try {
            $this->client->post($this->path($user) . '/actions/' . ($enabled ? 'enable' : 'disable'));
        } catch (RemnawaveApiException $e) {
            if (!$e->alreadySo()) {
                throw $e;
            }
        }
    }

    /**
     * How the panel knows a user it was read as: its uuid on 2.x — what its paths and a PATCH take there —, its numeric
     * id on 3.x, whose users have no uuid.
     *
     * @param array<string, mixed> $user
     * @return array{uuid: string}|array{id: int}
     * @throws UnexpectedResponseException for a user with neither
     */
    private static function identity(array $user): array
    {
        $uuid = Raw::string($user, 'uuid');
        $id = Raw::int($user, 'id');

        return match (true) {
            $uuid !== '' => ['uuid' => $uuid],
            $id > 0 => ['id' => $id],
            default => throw new UnexpectedResponseException(200, 'a user without an id', hint: self::HINT_VERSION),
        };
    }

    /**
     * The path of a user's own routes (/api/users/{uuid or id}).
     *
     * @param array<string, mixed> $user
     * @throws UnexpectedResponseException
     */
    private function path(array $user): string
    {
        $identity = self::identity($user);

        return self::USERS . '/' . rawurlencode((string) ($identity['uuid'] ?? $identity['id'] ?? ''));
    }

    /**
     * Every user, page by page, until a short page or the total the answer reports. Remnawave lists its newest first, so
     * a user made meanwhile may come twice (each is one client) and none is skipped; one deleted meanwhile may shift
     * another past a page — the sync asks for a client the list did not bring on its own. A panel past MAX_PAGES — or
     * one that never stops answering full pages — is not listed at once: the sync then asks for each service.
     *
     * @return \Generator<int, array<string, mixed>>
     * @throws UnsupportedOperationException past MAX_PAGES
     */
    private function users(): \Generator
    {
        $names = [];
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $answer = $this->client->get(self::USERS, ['start' => $page * self::PAGE_SIZE, 'size' => self::PAGE_SIZE]);
            $users = Raw::objectsOf($answer['users'] ?? null);
            foreach ($users as $user) {
                $names[Raw::string($user, 'username')] = true;

                yield $user;
            }
            if (count($users) < self::PAGE_SIZE || count($names) >= Raw::int($answer, 'total', PHP_INT_MAX)) {
                return;
            }
        }

        throw new UnsupportedOperationException(sprintf('The Remnawave panel lists more than %d users; its clients are read one by one.', self::MAX_PAGES * self::PAGE_SIZE));
    }

    /**
     * The deadline a client is made with: Remnawave's "never" for none, a term waiting for the first connection started
     * now, a deadline as it is.
     */
    private static function endOf(Expiry $expiry): \DateTimeImmutable
    {
        $pending = $expiry->pendingSeconds();
        $deadline = $expiry->deadline();

        return match (true) {
            $pending !== null => self::second(now()->getTimestamp() + $pending),
            $deadline !== null => $deadline,
            default => new \DateTimeImmutable(self::NEVER),
        };
    }

    /**
     * The deadline an update sends (endOf()), null for none sent: Remnawave refuses one not in the future. A term cut
     * short or a service ended (a deadline not SOONEST seconds away) cuts the user's own deadline `$current` to the
     * soonest the panel takes, when it ends later; when it has passed already, or ends sooner, it is left as it is.
     */
    private static function endFor(Expiry $expiry, ?\DateTimeImmutable $current): ?\DateTimeImmutable
    {
        $end = self::endOf($expiry);
        $soonest = now()->getTimestamp() + self::SOONEST;
        if ($end->getTimestamp() > $soonest) {
            return $end;
        }

        return $current !== null && $current->getTimestamp() > $soonest ? self::second($soonest) : null;
    }

    /**
     * Whether the term of `$spec` lets the user run: traffic left of its limit, and time — a deadline at least SOONEST
     * seconds away, or none, or one still to start.
     *
     * @param array<string, mixed> $user
     */
    private static function runs(ClientSpec $spec, array $user): bool
    {
        $deadline = $spec->expiry->deadline();

        return ($spec->totalBytes <= 0 || self::used($user) < $spec->totalBytes)
            && ($deadline === null || $deadline->getTimestamp() > now()->getTimestamp() + self::SOONEST);
    }

    /**
     * The comment (which carries the Telegram id) as the user's description — no comment leaves the panel's alone —, and
     * the Telegram id in the field Remnawave keeps for it, null for a customer without one: a service handed to another
     * customer keeps no trace of the one before (Remnawave keeps the field while it is left out).
     *
     * @return array{description?: string, telegramId: int|null}
     */
    private static function contact(ClientSpec $spec): array
    {
        return ($spec->comment !== null ? ['description' => trim($spec->comment)] : []) + ['telegramId' => $spec->telegramId];
    }

    /**
     * A user as the contract's client. Remnawave counts one total, reported as download (the shop adds the two); 2.1 and
     * 2.2 keep it on the user, later ones under `userTraffic`.
     *
     * @param array<string, mixed> $user
     */
    private function clientOf(array $user, ?bool $online): ClientInfo
    {
        $deadline = self::deadline($user);

        return new ClientInfo(
            name: Raw::string($user, 'username'),
            enabled: Raw::string($user, 'status') !== self::DISABLED,
            downloadBytes: self::used($user),
            totalBytes: max(0, Raw::int($user, 'trafficLimitBytes')),
            expiry: $deadline !== null ? Expiry::at($deadline) : Expiry::never(),
            subscriptionUrl: $this->linkOf($user),
            online: $online,
            lastOnlineAt: self::onlineAt($user),
            ipLimit: is_numeric($user['hwidDeviceLimit'] ?? null) ? max(0, (int) $user['hwidDeviceLimit']) : null,
        );
    }

    /**
     * The traffic the user used this term, in bytes.
     *
     * @param array<string, mixed> $user
     */
    private static function used(array $user): int
    {
        return Raw::int(Raw::array($user, 'userTraffic'), 'usedTrafficBytes', Raw::int($user, 'usedTrafficBytes'));
    }

    /**
     * When the panel last saw the user's traffic, or null.
     *
     * @param array<string, mixed> $user
     */
    private static function onlineAt(array $user): ?\DateTimeImmutable
    {
        return Raw::moment(Raw::array($user, 'userTraffic')['onlineAt'] ?? $user['onlineAt'] ?? null);
    }

    /**
     * The user's `expireAt` as Remnawave keeps it, its "never" included; null for none.
     *
     * @param array<string, mixed> $user
     */
    private static function expireAt(array $user): ?\DateTimeImmutable
    {
        return Raw::moment($user['expireAt'] ?? null);
    }

    /**
     * The user's deadline; null for none — Remnawave's "never" (2099 and later).
     *
     * @param array<string, mixed> $user
     */
    private static function deadline(array $user): ?\DateTimeImmutable
    {
        $at = self::expireAt($user);

        return $at !== null && (int) $at->format('Y') < self::NEVER_YEAR ? $at : null;
    }

    /**
     * The link the customer gets: the server's own prefix before the user's short id when one is set, or the panel's
     * (absolute: its subscription domain and the short id).
     *
     * @param array<string, mixed> $user
     */
    private function linkOf(array $user): ?string
    {
        $url = Raw::string($user, 'subscriptionUrl');
        $short = Raw::string($user, 'shortUuid');
        if ($this->connection->subscriptionUrl !== null && $short !== '') {
            return $this->connection->subscriptionUrl . '/' . $short;
        }
        if ($url === '') {
            return null;
        }

        return str_starts_with($url, '/') ? $this->connection->baseUrl . $url : $url;
    }

    /**
     * What a config's VLESS uuid is matched by: the uuid without its third group, which a host's route id replaces.
     */
    private static function vlessKey(string $uuid): string
    {
        $uuid = strtolower($uuid);

        return strlen($uuid) === 36 ? substr($uuid, 0, 14) . substr($uuid, 18) : '';
    }

    /**
     * The user's proxy passwords as configs carry them: Trojan's, Shadowsocks' — and Shadowsocks 2022's user key, its
     * password in base64.
     *
     * @param array<string, mixed> $user
     * @return list<string>
     */
    private static function passwordsOf(array $user): array
    {
        $shadowsocks = Raw::string($user, 'ssPassword');

        return [Raw::string($user, 'trojanPassword'), $shadowsocks, $shadowsocks !== '' ? base64_encode($shadowsocks) : ''];
    }

    /** The moment `$timestamp` (unix seconds), in UTC. */
    private static function second(int $timestamp): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . $timestamp);
    }

    /** A moment as Remnawave takes it: ISO 8601 in UTC, to the millisecond. */
    private static function iso(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }
}
