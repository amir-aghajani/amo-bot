<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\Marzban;

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
use App\Modules\Providers\Support\SubscriptionPath;

/**
 * ProviderInterface for Marzban panels (0.8 and later), on MarzbanClient — the client of one server's panel, built by
 * MarzbanDriver::connect().
 *
 * A client is a Marzban user, addressed by username (the contract's `name`) through /api/user/{username}. The shop's
 * inbounds are the inbounds of the panel's Xray config, keyed by their tag (unique across it): a user is made with a
 * proxy of each protocol its inbounds speak (`proxies`, settings left for the panel to mint) and those inbounds' tags by
 * protocol (`inbounds`) — which Marzban keeps as the inbounds of those protocols the user is left out of, so an inbound
 * added to the panel later, of a protocol the user has, reaches it too. A term from the first connection is Marzban's
 * "on hold" (`on_hold_expire_duration`); a deadline is its `expire` in unix seconds, 0 for none. Marzban has no
 * per-client IP limit, so ClientSpec::$ipLimit is not sent. A username is 3 to 32 letters, digits and `_`
 * (MarzbanDriver::capabilities()). An update that names no next plan drops the one a user had (Marzban's update_user),
 * so every update is sent the one the user has (nextPlan()).
 *
 * A non-sudo admin sees only the users it made: its panel is not listed at once (listClients()), and another admin's
 * user is refused with what to do about it (MarzbanApiException::refused()).
 *
 * The subscription link is the user's `subscription_url`, made absolute against the panel's address when it is a path
 * (the panel's XRAY_SUBSCRIPTION_URL_PREFIX unset). Marzban signs a fresh token into every answer, each valid until
 * the link is revoked, so the link a sync brings is a new one that works alongside the old.
 */
final class MarzbanProvider implements ProviderInterface
{
    /** How recently Marzban must have seen a user's traffic to count it online — its dashboard's window. */
    private const ONLINE_WINDOW_SECONDS = 60;

    /** Users read per request when the whole panel is listed. */
    private const PAGE_SIZE = 500;

    /** The most pages one listing reads (50,000 users), so a misbehaving panel cannot keep a sync paging forever. */
    private const MAX_PAGES = 100;

    private const USER = '/api/user/';

    /**
     * What a subscription token looks like: base64url — a payload and its signature, or an older panel's JWT of three
     * dotted parts —, at least 15 characters: Marzban reads nothing shorter.
     */
    private const TOKEN = '/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+){0,2}$/';
    private const TOKEN_MIN = 15;

    /** The most parts of a link shown to the panel's subscription route at one try: each is a request. */
    private const TOKENS_MAX = 3;

    /** What a next plan is, as Marzban takes it back (NextPlanModel). */
    private const NEXT_PLAN = ['data_limit', 'expire', 'add_remaining_traffic', 'fire_on_either'];

    /** The proxy settings that are credentials: a protocol's id or password. */
    private const CREDENTIALS = ['id', 'password'];

    /** The longest note Marzban keeps. */
    private const NOTE_MAX = 500;

    /** A user's auto-delete days that keep the panel from ever deleting it (crud.autodelete_expired_users skips negatives). */
    private const NEVER_AUTODELETE = -1;

    /**
     * An on-hold duration Marzban reads as none: its API cannot clear one (0 means "no change"), and its share and review
     * code skip a negative duration — so a duration a replaced term left is overwritten with it.
     */
    private const NO_DURATION = -1;

    /** Whether the admin signed in with is a sudo admin, once asked (sudo()). */
    private ?bool $sudo = null;

    public function __construct(
        private readonly MarzbanClient $client,
        private readonly PanelConnection $connection,
    ) {}

    /** One user read: proves the credentials (any admin of the panel may manage users of its own). */
    public function testConnection(): void
    {
        $this->client->get('/api/users', ['limit' => 1]);
    }

    /**
     * The panel host's vitals and its Xray core — run by the panel itself. Marzban reports no disk or uptime.
     */
    public function status(): PanelStatus
    {
        $system = $this->client->get('/api/system');
        $core = $this->client->get('/api/core');

        return new PanelStatus(
            cpuPercent: Raw::float($system, 'cpu_usage'),
            memoryUsedBytes: Raw::int($system, 'mem_used'),
            memoryTotalBytes: Raw::int($system, 'mem_total'),
            diskUsedBytes: 0,
            diskTotalBytes: 0,
            coreState: Raw::bool($core, 'started') ? CoreState::Running : CoreState::Stopped,
            coreName: 'Xray',
            coreVersion: Raw::string($core, 'version'),
        );
    }

    /**
     * Every inbound of the panel's Xray config, keyed and titled by its tag, with its protocol, port, transport and
     * security. Marzban counts no users per inbound, and lists only the inbounds it serves (none is switched off).
     */
    public function listInbounds(): array
    {
        $inbounds = [];
        foreach ($this->protocols() as $tag => [$protocol, $inbound]) {
            $port = $inbound['port'] ?? null;
            $inbounds[] = new InboundInfo(
                key: $tag,
                tag: $tag,
                remark: $tag,
                enabled: true,
                protocol: $protocol,
                port: is_int($port) || (is_string($port) && ctype_digit($port)) ? (int) $port : null,
                network: Raw::string($inbound, 'network') !== '' ? Raw::string($inbound, 'network') : null,
                security: Raw::string($inbound, 'tls') !== '' ? Raw::string($inbound, 'tls') : null,
            );
        }

        return $inbounds;
    }

    /** Marzban has no switch for its subscription route: every user it answers about carries a link. */
    public function servesSubscriptions(): bool
    {
        return true;
    }

    public function createClient(array $inboundKeys, ClientSpec $spec): ClientInfo
    {
        if ($inboundKeys === []) {
            throw new \InvalidArgumentException('A Marzban user is made on inbounds: pass at least one inbound tag.');
        }

        $user = $this->client->post('/api/user', [
            'username' => $spec->name,
            ...$this->access($inboundKeys),
            'data_limit' => $spec->totalBytes,
            'data_limit_reset_strategy' => 'no_reset',
            // Never removed by the panel's own clean-up of ended users (USERS_AUTODELETE_DAYS): the shop removes an ended
            // service itself, after its renewal window — a user's days are set only as it is made.
            'auto_delete_in_days' => self::NEVER_AUTODELETE,
        ] + self::term($spec->expiry) + self::note($spec));
        if (!$spec->enabled) {
            $user = $this->switchedOff($spec->name);
        }

        return $this->clientOf($user, online: null);
    }

    /**
     * A user just made, switched off — Marzban makes a user active or on hold only —, its term kept as it was made (a
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
     * The user read, then one PUT with its next plan sent back (nextPlan()): the panel keeps the user's proxies, inbounds
     * and link. The traffic is the term's alone — no reset on a schedule the panel kept (an adopted user's): the quota is
     * once a term —, and a term not waiting leaves no duration behind (NO_DURATION).
     */
    public function updateClient(ClientSpec $spec): ClientInfo
    {
        $path = self::USER . rawurlencode($spec->name);
        $before = $this->client->get($path);
        $leftover = $spec->expiry->pendingSeconds() === null && Raw::int($before, 'on_hold_expire_duration') > 0;
        $user = $this->client->put($path, [
            'data_limit' => $spec->totalBytes,
            'data_limit_reset_strategy' => 'no_reset',
        ] + self::term($spec->expiry, $spec->enabled) + ($leftover ? ['on_hold_expire_duration' => self::NO_DURATION] : []) + self::note($spec) + self::nextPlan($before));

        return $this->clientOf($user, online: null);
    }

    /** revoke_sub: fresh proxy credentials and a new link; every link signed before stops working. */
    public function rotateClientCredentials(string $name): ClientInfo
    {
        return $this->clientOf($this->client->post(self::USER . rawurlencode($name) . '/revoke_sub'), online: null);
    }

    /**
     * The user read, then switched — once it is not as asked already —, its next plan sent back. Switched on, one whose
     * term never started goes back on hold with it (waitingTerm()), sent explicitly: plain "active" would leave it with no
     * end at all. Any other goes active with its own limit and deadline sent back, so the panel finds it limited or expired
     * at once (update_user judges them only when sent) — not ten seconds later, at its next review, connected meanwhile.
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
        $this->client->put($path, match (true) {
            !$enabled => ['status' => 'disabled'],
            $pending !== null => ['status' => 'on_hold', 'on_hold_expire_duration' => $pending],
            default => ['status' => 'active', 'data_limit' => Raw::int($user, 'data_limit'), 'expire' => self::deadline($user)?->getTimestamp() ?? 0],
        } + self::nextPlan($user));
    }

    /**
     * The user read (a missing one is the contract's NotFoundException), then deleted. Marzban 0.8.2–0.8.4 deletes a user
     * no admin owns — one made by the `.env` sudo admin, or whose admin was removed — from its database and then fails
     * the request (HTTP 500, its issue #1634) before taking it off Xray, where it would go on working until the core
     * restarts: such a user is switched off first, which takes it off Xray, and a 5xx after which the user is gone is the
     * delete done.
     */
    public function deleteClient(string $name): void
    {
        $path = self::USER . rawurlencode($name);
        $user = $this->client->get($path);
        if (($user['admin'] ?? null) === null && Raw::string($user, 'status') !== 'disabled') {
            $this->client->put($path, ['status' => 'disabled'] + self::nextPlan($user));
        }

        try {
            $this->client->delete($path);
        } catch (UnexpectedResponseException $e) {
            if ($e->httpStatus < 500) {
                throw $e;
            }
            // The delete's own failure is what goes on — it may have been done —, whatever the look after it says.
            try {
                $this->client->get($path);
            } catch (NotFoundException) {
                return;
            } catch (ProviderException) {
            }

            throw $e;
        }
    }

    /** Presence comes with the user: seen within Marzban's own window. */
    public function findClient(string $name, bool $presence = false): ?ClientInfo
    {
        try {
            $user = $this->client->get(self::USER . rawurlencode($name));
        } catch (NotFoundException) {
            return null;
        }

        $seen = Raw::moment($user['online_at'] ?? null);

        return $this->clientOf($user, online: $presence ? $seen !== null && $seen->getTimestamp() >= now()->getTimestamp() - self::ONLINE_WINDOW_SECONDS : null);
    }

    /**
     * Every user (users()), a client each — for a sudo admin: any other sees only the users it made, so its panel is not
     * listed at once and the caller asks for each client (another admin's then refused, in words that say what to do).
     */
    public function listClients(): array
    {
        if (!$this->sudo()) {
            throw new UnsupportedOperationException('The Marzban admin signed in with is no sudo admin and lists only the users it made; its clients are read one by one.');
        }

        $clients = [];
        foreach ($this->users() as $user) {
            $client = $this->clientOf($user, online: null);
            $clients[$client->name] = $client;
        }

        return array_values($clients);
    }

    /**
     * A subscription link's token is shown to the panel's own subscription route — `…/{token}/info`, under the path its
     * links use — which answers the user it was signed for: the shop cannot check Marzban's signature itself (it is made
     * with the panel's own secret), and a token's payload is no proof. At most TOKENS_MAX parts that can be a token are
     * asked about, first: they are cheap. A config's credentials are then matched against every user's proxy settings
     * (users(): the one that matches is read on its own).
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
            foreach ($this->users() as $user) {
                if ($link->hasCredential(...self::credentialsOf($user))) {
                    return $this->findClient(Raw::string($user, 'username'));
                }
            }
        }

        return null;
    }

    /**
     * The user read, its usage reset, then its status put back when the reset changed it. Marzban's reset means to make a
     * limited user active again; in 0.8.x its test of the status misreads (crud.reset_user_data_usage: `not in (expired or
     * disabled)` is a test on the word "expired"), so it makes a switched-off user active, and in every version one on hold
     * becomes active with no end at all: the first goes back off, the second back on hold with its term. The reset drops
     * the user's next plan, as Marzban means it to.
     */
    public function resetClientTraffic(string $name): void
    {
        $path = self::USER . rawurlencode($name);
        $before = $this->client->get($path);
        $after = $this->client->post($path . '/reset');

        $pending = self::waitingTerm($before);
        $back = match (Raw::string($before, 'status')) {
            'disabled' => ['status' => 'disabled'],
            'on_hold' => $pending !== null ? ['status' => 'on_hold', 'on_hold_expire_duration' => $pending] : null,
            default => null,
        };
        if ($back !== null && Raw::string($after, 'status') !== $back['status']) {
            $this->client->put($path, $back + self::nextPlan($after));
        }
    }

    /** Whether the admin signed in with is a sudo admin (GET /api/admin), asked once for the client's life. */
    private function sudo(): bool
    {
        return $this->sudo ??= Raw::bool($this->client->get('/api/admin'), 'is_sudo');
    }

    /**
     * The user's next plan as an update sends it back — Marzban drops a next plan an update does not name —; nothing
     * when it has none.
     *
     * @param array<string, mixed> $user
     * @return array{next_plan?: array<string, mixed>}
     */
    private static function nextPlan(array $user): array
    {
        $plan = Raw::nullableArray($user, 'next_plan');

        return $plan !== null ? ['next_plan' => array_intersect_key($plan, array_flip(self::NEXT_PLAN))] : [];
    }

    /**
     * The panel's inbounds by tag, each with the protocol it is listed under and its row — GET /api/inbounds groups them
     * by protocol.
     *
     * @return array<string, array{string, array<string, mixed>}>
     * @throws ProviderException
     */
    private function protocols(): array
    {
        $inbounds = [];
        foreach ($this->client->get('/api/inbounds') as $protocol => $list) {
            foreach (Raw::objectsOf($list) as $inbound) {
                $tag = Raw::string($inbound, 'tag');
                if ($tag !== '') {
                    $inbounds[$tag] = [Raw::string($inbound, 'protocol', (string) $protocol), $inbound];
                }
            }
        }

        return $inbounds;
    }

    /**
     * What a new user reaches: a proxy of each protocol its inbounds speak — empty settings, the panel mints the id or
     * password — and the inbounds' tags by protocol. An inbound the panel no longer has is refused before anything is
     * sent.
     *
     * @param list<string> $tags
     * @return array{proxies: array<string, object>, inbounds: array<string, list<string>>}
     * @throws ProviderException
     */
    private function access(array $tags): array
    {
        $protocols = $this->protocols();
        $inbounds = [];
        foreach (array_values(array_unique($tags)) as $tag) {
            $protocol = $protocols[$tag][0] ?? throw MarzbanApiException::missingInbound($tag);
            $inbounds[$protocol][] = $tag;
        }

        return ['proxies' => array_map(static fn(): object => new \stdClass(), $inbounds), 'inbounds' => $inbounds];
    }

    /**
     * Every user, page by page, ordered by the unique username so a page boundary never shifts on a stable panel, until a
     * short page or the total the answer reports (an answer without one pages on). A panel past MAX_PAGES — or one that
     * never stops answering full pages — is not listed at once: the sync then asks for each service on its own.
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
                $names[Raw::string($user, 'username')] = true;

                yield $user;
            }
            if (count($users) < self::PAGE_SIZE || count($names) >= Raw::int($answer, 'total', PHP_INT_MAX)) {
                return;
            }
        }

        throw new UnsupportedOperationException(sprintf('The Marzban panel lists more than %d users; its clients are read one by one.', self::MAX_PAGES * self::PAGE_SIZE));
    }

    /**
     * The path the panel serves subscription links under (its XRAY_SUBSCRIPTION_PATH, "/sub" by default; the panel's
     * XRAY_SUBSCRIPTION_URL_PREFIX or a proxy's path in front of the links is not), read off one user's link
     * (SubscriptionPath::find()). Null with no user to read, or none answering.
     */
    private function subscriptionPath(): ?string
    {
        $user = Raw::objectsOf($this->client->get('/api/users', ['offset' => 0, 'limit' => 1])['users'] ?? null)[0] ?? [];
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
        foreach (Raw::nullableArray($user, 'proxies') ?? [] as $settings) {
            foreach (is_array($settings) ? $settings : [] as $key => $value) {
                if (is_string($value) && in_array($key, self::CREDENTIALS, true)) {
                    $credentials[] = $value;
                }
            }
        }

        return $credentials;
    }

    /**
     * The term of a client, as Marzban words it: on hold with its duration, or active with a deadline — `expire: 0`
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
     * The comment (which carries the Telegram id) as the user's note, cut to what Marzban keeps — its users have no
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
     * A user as the contract's client. Marzban counts one total, reported as download (the shop adds the two).
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
     * started (switched on, it goes back on hold); null otherwise.
     *
     * Marzban's API cannot clear a duration (its validator reads 0 as "no change"); only the panel's own start of a term
     * does (crud.start_user_expire), so one may stay on a user whose term was replaced. On an active, limited or expired
     * user, or beside a deadline, it is never a term. On a switched-off one without a deadline it is read as waiting, as
     * the panel reads it: the panel cannot tell it from a leftover — not by `online_at` either, since a term starts only
     * at traffic since the user's last edit (review_users), and a user that connected before is put back on hold when an
     * ended service is renewed.
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
     * The user's deadline: unix seconds as Marzban keeps it; 0, null or nothing is none.
     *
     * @param array<string, mixed> $user
     */
    private static function deadline(array $user): ?\DateTimeImmutable
    {
        return Raw::seconds(Raw::int($user, 'expire'));
    }
}
