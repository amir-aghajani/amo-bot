<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\PasarGuard;

use App\Modules\Providers\Contracts\ProviderInterface;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\DTO\ClientLink;
use App\Modules\Providers\DTO\ClientSpec;
use App\Modules\Providers\DTO\Expiry;
use App\Modules\Providers\DTO\InboundInfo;
use App\Modules\Providers\DTO\PanelStatus;
use App\Modules\Providers\Enums\AuthenticationFailure;
use App\Modules\Providers\Enums\CoreState;
use App\Modules\Providers\Exceptions\AuthenticationException;
use App\Modules\Providers\Exceptions\NotFoundException;
use App\Modules\Providers\Exceptions\PanelApiException;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Providers\Exceptions\UnsupportedOperationException;
use App\Modules\Providers\Support\PanelConnection;
use App\Modules\Providers\Support\Raw;
use App\Modules\Providers\Support\SubscriptionPath;

/**
 * ProviderInterface for PasarGuard panels (3.1 and later), on PasarGuardClient — the client of one server's panel,
 * built by PasarGuardDriver::connect().
 *
 * A client is a PasarGuard user, addressed by username (the contract's `name`) through /api/user/by-username (5.6
 * marks every route by username for removal in 6.0; /api/user/by-id is the one that stays). The shop's "inbounds" are
 * PasarGuard's groups: a user reaches inbounds only through its groups (`group_ids`), so a group's id is the inbound key
 * and a plan entry sells groups. A term from the first connection is PasarGuard's "on hold" (`on_hold_expire_duration`);
 * a deadline is its `expire`. PasarGuard has no per-client IP limit (its HWID device limit counts only the devices that
 * fetch the subscription link with a device id), so ClientSpec::$ipLimit is not sent. A username is 3 to 128 letters,
 * digits and `_ - . @`, never two of those side by side (PasarGuardDriver::capabilities()).
 *
 * An admin may see only the users it made: before 5.0 a non-sudo admin, refused the rest (HTTP 403, with what to do:
 * PasarGuardApiException::refused()); from 5.0 an admin whose role reads only its own users ("own" scope, the built-in
 * operator), told "User not found" for the rest — the panel hides them, so such a user reads as gone.
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

    private const USERS = '/api/user/by-username/';

    /**
     * What a subscription token looks like: base64url parts joined by dots (a signed payload, or a JWT), at least 16
     * characters — PasarGuard signs nothing shorter.
     */
    private const TOKEN = '/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+){0,2}$/';
    private const TOKEN_MIN = 16;

    /** The most parts of a link shown to the panel's subscription route at one try: each is a request. */
    private const TOKENS_MAX = 3;

    /** The proxy settings that are credentials: a protocol's id or password. */
    private const CREDENTIALS = ['id', 'password', 'auth'];

    /** How PasarGuard's roles begin the 403 for an action the admin may not take: "Permission denied: groups.read". */
    private const DENIED = 'Permission denied';

    /** The longest note PasarGuard keeps (it refuses a longer one). */
    private const NOTE_MAX = 500;

    /**
     * A user's auto-delete days that keep the panel from ever deleting it: its clean-up of ended users (the panel's
     * cleanup setting, USERS_AUTODELETE_DAYS before 5.6) skips a user whose own days are negative.
     */
    private const NEVER_AUTODELETE = -1;

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

    /**
     * Every group the admin may use, as an inbound: its name as the title, its inbound tags as the tag; no protocol or
     * port. An admin whose role may see only the groups' simple list — the built-in operator role (5.0 and later) has
     * "View simple list" of groups, not "View" — gets that list: each group's id and name, nothing about its inbounds,
     * users or switch, so each reads as on.
     */
    public function listInbounds(): array
    {
        try {
            $groups = Raw::objectsOf($this->client->get('/api/groups')['groups'] ?? null);
        } catch (PanelApiException|AuthenticationException $e) {
            if (!self::denied($e)) {
                throw $e;
            }

            return array_map(static fn(array $group): InboundInfo => new InboundInfo(
                key: (string) Raw::int($group, 'id'),
                tag: '',
                remark: Raw::string($group, 'name'),
                enabled: true,
            ), Raw::objectsOf($this->client->get('/api/groups/simple')['groups'] ?? null));
        }

        return array_map(static fn(array $group): InboundInfo => new InboundInfo(
            key: (string) Raw::int($group, 'id'),
            tag: implode(', ', Raw::stringsOf($group['inbound_tags'] ?? null)),
            remark: Raw::string($group, 'name'),
            enabled: !Raw::bool($group, 'is_disabled'),
            clientCount: Raw::int($group, 'total_users'),
        ), $groups);
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
            // Never removed by the panel's own clean-up of ended users: the shop removes an ended service itself, after
            // its renewal window — a user's days are set only as it is made (an update does not touch them).
            'auto_delete_in_days' => self::NEVER_AUTODELETE,
        ] + self::term($spec->expiry) + self::note($spec));
        if (!$spec->enabled) {
            $user = $this->switchedOff($spec->name);
        }

        return $this->clientOf($user, online: null);
    }

    /**
     * A user just made, switched off — PasarGuard makes a user active or on hold only —, its term kept as it was made (a
     * duration still waiting stays on it, for setClientEnabled() to put back on hold). One the panel will not switch off
     * is taken back: nothing was made. One it will not take back either is there switched on, a failure after the write
     * (ProviderException::afterWrite()).
     *
     * @return array<string, mixed> The user as the panel has it then
     * @throws ProviderException
     */
    private function switchedOff(string $name): array
    {
        $path = self::USERS . rawurlencode($name);
        try {
            return $this->client->put($path, ['status' => 'disabled']);
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
     * One PUT: the panel keeps the user's groups, credentials and link. The traffic is the term's alone — no reset on a
     * schedule the panel kept (an adopted user's): the quota is once a term (`no_reset` is allowed even to a role that
     * may not pick a reset strategy). A term not waiting for the first connection that the panel put on hold all the same
     * is put active (activated()).
     */
    public function updateClient(ClientSpec $spec): ClientInfo
    {
        $path = self::USERS . rawurlencode($spec->name);
        $user = $this->client->put($path, [
            'data_limit' => $spec->totalBytes,
            'data_limit_reset_strategy' => 'no_reset',
        ] + self::term($spec->expiry, $spec->enabled) + self::note($spec));
        if ($spec->enabled && $spec->expiry->pendingSeconds() === null) {
            $user = $this->activated($path, $user);
        }

        return $this->clientOf($user, online: null);
    }

    /**
     * The user a PUT of `active` answered, active. PasarGuard (5.1 and later) reads a switched-off user switched back on
     * as on hold whenever it keeps a duration — and its API cannot clear one (its validator reads 0 as "no change" and
     * refuses a negative one), so a duration stays on a user whose waiting term was replaced by a deadline or by none. Put
     * on hold so, the user would start that old term at its next connection, over its deadline or its unlimited time: one
     * the shop knows is not waiting is put active from there, which the panel takes as asked.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     * @throws ProviderException
     */
    private function activated(string $path, array $user): array
    {
        return Raw::string($user, 'status') === 'on_hold' ? $this->client->put($path, ['status' => 'active']) : $user;
    }

    /** revoke_sub: fresh proxy credentials and a new link; every link signed before stops working. */
    public function rotateClientCredentials(string $name): ClientInfo
    {
        return $this->clientOf($this->client->post(self::USERS . rawurlencode($name) . '/revoke_sub'), online: null);
    }

    /**
     * Switching off is one PUT. Switching on reads the user first: one whose term never started goes back on hold with
     * it, sent explicitly — a panel before 5.1 would otherwise make it active with no end at all —; any other goes back
     * active, even beside a leftover duration (activated()). From 5.1 the panel itself finds one switched back on
     * expired or limited when it is; before, its next review of users does (every 30 seconds by default).
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
        if ($pending !== null) {
            $this->client->put($path, ['status' => 'on_hold', 'on_hold_expire_duration' => $pending]);

            return;
        }

        $this->activated($path, $this->client->put($path, ['status' => 'active']));
    }

    /**
     * One DELETE. PasarGuard reads the user before it deletes it, and takes it off its nodes after, in the background: a
     * user no admin owns is deleted like any other, with no failure after the delete is done (Marzban 0.8.2–0.8.4's 500).
     */
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

        $seen = Raw::moment($user['online_at'] ?? null);

        return $this->clientOf($user, online: $presence ? $seen !== null && $seen->getTimestamp() >= now()->getTimestamp() - self::ONLINE_WINDOW_SECONDS : null);
    }

    /**
     * Every user (users()), a client each — every user the admin may see: one that sees only its own lists those, and the
     * sync asks for each service the list leaves out (another admin's then refused before 5.0, "not found" from 5.0).
     */
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
     * A subscription link's token is shown to the panel's own subscription route — `…/{token}/info`, under the path its
     * links use — which answers the user it was signed for: the shop cannot check PasarGuard's signature itself, and a
     * token's payload is no proof. At most TOKENS_MAX parts that can be a token are asked about, first: they are cheap.
     * A config's credentials are then matched against every user's proxy settings (users(), without their links: the
     * one that matches is read on its own).
     */
    public function findClientByLink(ClientLink $link): ?ClientInfo
    {
        $tokens = array_slice(array_values(array_filter($link->tokens, static fn(string $token): bool => strlen($token) >= self::TOKEN_MIN && preg_match(self::TOKEN, $token) === 1)), 0, self::TOKENS_MAX);
        $path = $tokens === [] ? null : $this->subscriptionPath();
        if ($path !== null) {
            foreach ($tokens as $token) {
                $name = $this->owner($path, $token);
                if ($name !== '') {
                    return $this->findClient($name);
                }
            }
        }

        if ($link->credentials !== []) {
            foreach ($this->users(links: false) as $user) {
                if ($link->hasCredential(...self::credentialsOf($user))) {
                    return $this->findClient(Raw::string($user, 'username'));
                }
            }
        }

        return null;
    }

    /**
     * One POST. Before 5.1 PasarGuard's reset makes every user but an expired or switched-off one active
     * (crud.reset_user_data_usage) — an on-hold one too, with no end —; the shop resets only to send the term right after
     * (ProvisioningService::applyTerms()), whose updateClient() puts a waiting term back on hold. It drops the user's
     * next plan, as PasarGuard means it to.
     */
    public function resetClientTraffic(string $name): void
    {
        $this->client->post(self::USERS . rawurlencode($name) . '/reset');
    }

    /** Whether a failure is the panel's "Permission denied": a key's (AuthenticationException) or an admin's refusal. */
    private static function denied(ProviderException $e): bool
    {
        return $e instanceof AuthenticationException
            ? $e->failure === AuthenticationFailure::Scope
            : $e instanceof PanelApiException && $e->httpStatus === 403 && str_starts_with($e->panelMessage, self::DENIED);
    }

    /**
     * Every user, page by page, ordered by the unique username so a page boundary never shifts on a stable panel, until a
     * short page or the total the answer reports (an answer without one pages on). A panel past MAX_PAGES — or one that
     * never stops answering full pages — is not listed at once: the sync then asks for each service on its own.
     *
     * `$links`: with each user's subscription link (which PasarGuard signs for each answer), or without.
     *
     * @return \Generator<int, array<string, mixed>>
     * @throws UnsupportedOperationException past MAX_PAGES
     */
    private function users(bool $links = true): \Generator
    {
        $names = [];
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $answer = $this->client->get('/api/users', ['offset' => $page * self::PAGE_SIZE, 'limit' => self::PAGE_SIZE, 'sort' => 'username', 'load_sub' => $links ? 'true' : 'false']);
            $users = Raw::objectsOf($answer['users'] ?? null);
            foreach ($users as $user) {
                $names[Raw::string($user, 'username')] = true;

                yield $user;
            }
            if (count($users) < self::PAGE_SIZE || count($names) >= Raw::int($answer, 'total', PHP_INT_MAX)) {
                return;
            }
        }

        throw new UnsupportedOperationException(sprintf('The PasarGuard panel lists more than %d users; its clients are read one by one.', self::MAX_PAGES * self::PAGE_SIZE));
    }

    /**
     * The path the panel serves subscription links under (its XRAY_SUBSCRIPTION_PATH or SUBSCRIPTION_PATH, "/sub" by
     * default; the panel's subscription URL prefix, an admin's subdomain or a proxy's path in front of the links is not),
     * read off one user's link (SubscriptionPath::find()). Null with no user to read, or none answering.
     */
    private function subscriptionPath(): ?string
    {
        $user = Raw::objectsOf($this->client->get('/api/users', ['offset' => 0, 'limit' => 1, 'load_sub' => 'true'])['users'] ?? null)[0] ?? [];
        $name = Raw::string($user, 'username');

        return SubscriptionPath::find(Raw::string($user, 'subscription_url'), fn(string $path, string $token): bool => $this->owner($path, $token) === $name);
    }

    /** The username the panel's subscription route under `$path` answers `$token` with; empty for none. */
    private function owner(string $path, string $token): string
    {
        return Raw::string($this->client->anonymous($path . '/' . $token . '/info') ?? [], 'username');
    }

    /**
     * The user's proxy credentials: each protocol's id or password.
     *
     * @param array<string, mixed> $user
     * @return list<string>
     */
    private static function credentialsOf(array $user): array
    {
        $credentials = [];
        foreach (Raw::nullableArray($user, 'proxy_settings') ?? [] as $settings) {
            foreach (is_array($settings) ? $settings : [] as $key => $value) {
                if (is_string($value) && in_array($key, self::CREDENTIALS, true)) {
                    $credentials[] = $value;
                }
            }
        }

        return $credentials;
    }

    /**
     * The term of a client, as PasarGuard words it: on hold with its duration, or active with a deadline — `expire: 0`
     * says "none" (null would mean "leave it"). One kept switched off is `disabled` with its term all the same: a term
     * still waiting keeps its duration, which setClientEnabled() puts back on hold.
     *
     * @return array<string, int|string>
     */
    private static function term(Expiry $expiry, bool $enabled = true): array
    {
        $pending = $expiry->pendingSeconds();

        return [
            'status' => match (true) {
                !$enabled => 'disabled',
                $pending !== null => 'on_hold',
                default => 'active',
            },
            'expire' => $expiry->deadline()?->getTimestamp() ?? 0,
        ] + ($pending !== null ? ['on_hold_expire_duration' => $pending] : []);
    }

    /**
     * The comment (which carries the Telegram id) as the user's note, cut to what PasarGuard keeps — its users have no
     * Telegram field; nothing to say leaves the panel's note alone.
     *
     * @return array{note?: string}
     */
    private static function note(ClientSpec $spec): array
    {
        $note = $spec->comment ?? ($spec->telegramId !== null ? (string) $spec->telegramId : null);

        return $note !== null ? ['note' => mb_substr($note, 0, self::NOTE_MAX)] : [];
    }

    /**
     * A user as the contract's client. PasarGuard counts one total, reported as download (the shop adds the two).
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
        );
    }

    /**
     * The term still waiting for the user's first connection, in seconds: on hold, or switched off before its clock
     * started (switched on, it goes back on hold); null otherwise. A duration on an active user, or beside a deadline, is
     * a leftover the panel cannot clear, not a term. On a switched-off user without a deadline it is read as waiting, as
     * PasarGuard reads it: the panel cannot tell it from a leftover — not by `online_at` either, since a term starts only
     * at traffic since the user's last edit, and a user that connected before is put back on hold when an ended service
     * is renewed.
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
     * The user's deadline: a moment as PasarGuard writes it (ISO 8601), or unix seconds as the panel takes it; 0, null or
     * nothing is none — `expire: 0`, what the shop sends for "none", reads back as none too.
     *
     * @param array<string, mixed> $user
     */
    private static function deadline(array $user): ?\DateTimeImmutable
    {
        $expire = $user['expire'] ?? null;

        return is_numeric($expire) ? Raw::seconds((int) $expire) : Raw::moment($expire);
    }
}
