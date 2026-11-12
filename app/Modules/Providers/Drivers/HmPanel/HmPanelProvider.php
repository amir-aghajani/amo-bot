<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\HmPanel;

use App\Modules\Providers\Contracts\ProviderInterface;
use App\Modules\Providers\Drivers\ThreeXui\Support\ExpiryTime;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\DTO\ClientLink;
use App\Modules\Providers\DTO\ClientSpec;
use App\Modules\Providers\DTO\InboundInfo;
use App\Modules\Providers\DTO\PanelStatus;
use App\Modules\Providers\Exceptions\AuthenticationException;
use App\Modules\Providers\Exceptions\NotFoundException;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Providers\Exceptions\UnsupportedOperationException;
use App\Modules\Providers\Support\PanelConnection;
use App\Modules\Providers\Support\Raw;

/**
 * ProviderInterface for HMPanel (2.5 and later), on HmPanelClient — the client of one server's panel, built by
 * HmPanelDriver::connect().
 *
 * HMPanel manages several 3x-ui panels beneath it. A client is an HMPanel client, addressed by its name (`email`, the
 * contract's `name`); its calls take HMPanel's own id, which /v1/clients/lookup finds by name and this client keeps
 * while it lives (ProviderRegistry), so a call by name is one request. HMPanel keeps a name once per panel beneath it,
 * so the shop takes it as one across the panel: lookup answers the one changed last. Inbounds are those of HMPanel's
 * 3x-ui panels (an external panel's go another way inside HMPanel), keyed `<panel id>:<inbound id>`: a client lives on
 * one panel, so a sale on inbounds of two is refused before it is sent. Everything else is 3x-ui's as HMPanel passes it
 * on: `expiryTime` in milliseconds, negative for a term from the first connection (ThreeXui\Support\ExpiryTime), and
 * the device limit as `limitIp`. The comment (which carries the Telegram id) is the client's `remark`, 3x-ui's comment.
 *
 * The subscription link is HMPanel's own (`/s/<token>`), or the server's prefix in front of the client's token.
 * HMPanel answers each key a limited number of calls a minute, so nothing here asks more than it must: no status (its
 * key API has no host statistics), the clients listed 200 at a time, a link's values looked up a few at most.
 */
final class HmPanelProvider implements ProviderInterface
{
    /** Clients read per request when the whole panel is listed: the most HMPanel answers at once. */
    private const PAGE_SIZE = 200;

    /** The most pages one listing reads (10,000 clients): each is a call against the key's calls per minute. */
    private const MAX_PAGES = 50;

    /** The panels beneath HMPanel whose inbounds the shop sells on. */
    private const THREE_XUI = '3x-ui';

    /** Between the panel's id and the inbound's in an inbound key. */
    private const KEY_SEPARATOR = ':';

    /** A part of a subscription link that may be a client's HMPanel token or 3x-ui subscription id. */
    private const TOKEN = '/^[A-Za-z0-9_-]{8,64}$/';

    /** A config's credential lookup may match: a client's UUID (a password is no value HMPanel looks clients up by). */
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** The most values of a link looked up at one try: each is a call. */
    private const LOOKUPS_MAX = 4;

    private const CLIENTS = '/v1/clients';

    /** @var array<string, string> HMPanel's id of each client read or made, by name. */
    private array $ids = [];

    public function __construct(
        private readonly HmPanelClient $client,
        private readonly PanelConnection $connection,
    ) {}

    /**
     * The key and its admin read (/v1/me, which any live key may call), and its scopes held to what the shop's calls
     * need: one missing is named now rather than at the first sale.
     */
    public function testConnection(): void
    {
        $me = $this->client->get('/v1/me');
        if (!is_array($me['scopes'] ?? null)) {
            return;
        }

        $scopes = Raw::stringsOf($me['scopes']);
        $missing = in_array(HmPanelClient::EVERY_SCOPE, $scopes, true) ? [] : array_values(array_diff(HmPanelClient::SCOPES, $scopes));
        if ($missing !== []) {
            throw AuthenticationException::insufficientScope('Missing scope: ' . implode(', ', $missing), HmPanelClient::scopeHint($missing));
        }
    }

    /** HMPanel's key API counts clients, but says nothing of a host: no CPU, memory or disk to show. */
    public function status(): PanelStatus
    {
        throw new UnsupportedOperationException('HMPanel\'s key API reports no host statistics.');
    }

    /**
     * The inbounds of HMPanel's 3x-ui panels the key's admin may use, each titled with its panel's name. HMPanel says
     * nothing of whether one is switched on, nor of its clients.
     */
    public function listInbounds(): array
    {
        $inbounds = [];
        foreach (Raw::objectsOf($this->client->get('/v1/inbounds')['data'] ?? null) as $inbound) {
            $panel = Raw::array($inbound, 'panel');
            if (strtolower(Raw::string($panel, 'panelType')) !== self::THREE_XUI) {
                continue;
            }

            $title = Raw::string($inbound, 'remark') !== '' ? Raw::string($inbound, 'remark') : Raw::string($inbound, 'tag');
            $port = Raw::int($inbound, 'port');
            $inbounds[] = new InboundInfo(
                key: Raw::string($panel, 'id') . self::KEY_SEPARATOR . Raw::string($inbound, 'id'),
                tag: Raw::string($inbound, 'tag'),
                remark: Raw::string($panel, 'name') !== '' ? Raw::string($panel, 'name') . ' · ' . $title : $title,
                enabled: true,
                protocol: Raw::string($inbound, 'protocol') !== '' ? Raw::string($inbound, 'protocol') : null,
                port: $port > 0 ? $port : null,
            );
        }

        return $inbounds;
    }

    /** HMPanel serves every client's link itself (`/s/<token>`): nothing to switch on. */
    public function servesSubscriptions(): bool
    {
        return true;
    }

    /**
     * One POST of the client on its inbounds, all of one panel — HMPanel answers its stored row, so the client is read
     * after it. HMPanel makes a client switched on: one wanted off is switched off at once (switchedOff()).
     */
    public function createClient(array $inboundKeys, ClientSpec $spec): ClientInfo
    {
        if ($inboundKeys === []) {
            throw new \InvalidArgumentException('An HMPanel client lives on inbounds: pass at least one inbound key.');
        }

        $panels = [];
        $inbounds = [];
        foreach (array_unique($inboundKeys) as $key) {
            $at = strpos($key, self::KEY_SEPARATOR);
            if ($at !== false) {
                $panels[substr($key, 0, $at)] = true;
            }
            $inbounds[] = $at === false ? $key : substr($key, $at + 1);
        }
        if (count($panels) > 1) {
            throw HmPanelApiException::severalPanels('POST', self::CLIENTS);
        }

        $made = $this->client->post(self::CLIENTS, [
            'email' => $spec->name,
            'inboundIds' => $inbounds,
            'total' => $spec->totalBytes,
            'expiryTime' => ExpiryTime::of($spec->expiry),
            'limitIp' => $spec->ipLimit,
        ] + self::remark($spec));

        try {
            $id = Raw::string($made, 'id') !== '' ? Raw::string($made, 'id') : $this->idOf($spec->name);
        } catch (ProviderException $e) {
            throw $e->afterWrite();
        }
        $this->ids[$spec->name] = $id;
        if (!$spec->enabled) {
            $this->switchedOff($spec->name, $id);
        }

        return $this->readAfterWrite($id);
    }

    /** One PATCH of the limits, the term and the switch — sent always: HMPanel would switch on one given more — then the client read. */
    public function updateClient(ClientSpec $spec): ClientInfo
    {
        return $this->byName($spec->name, function (string $id) use ($spec): ClientInfo {
            $this->client->patch(self::path($id), [
                'enable' => $spec->enabled,
                'total' => $spec->totalBytes,
                'expiryTime' => ExpiryTime::of($spec->expiry),
                'limitIp' => $spec->ipLimit,
            ] + self::remark($spec));

            return $this->readAfterWrite($id);
        });
    }

    /** HMPanel's key API cannot give a client new credentials or a new link. */
    public function rotateClientCredentials(string $name): ClientInfo
    {
        throw new UnsupportedOperationException('HMPanel\'s key API cannot renew a client\'s credentials or link.');
    }

    public function setClientEnabled(string $name, bool $enabled): void
    {
        $this->byName($name, fn(string $id): array => $this->client->patch(self::path($id), ['enable' => $enabled]));
    }

    public function deleteClient(string $name): void
    {
        $this->byName($name, function (string $id): void {
            $this->client->delete(self::path($id));
        });
        unset($this->ids[$name]);
    }

    /** HMPanel cannot tell whether a client is connected: no presence, asked or not. */
    public function findClient(string $name, bool $presence = false): ?ClientInfo
    {
        $client = $this->named($name);

        return $client === null ? null : $this->clientOf($client);
    }

    /**
     * Every client of the key's admin, page by page — HMPanel lists the newest first, so a client made meanwhile shifts
     * the pages: one seen twice is kept once —, until an empty page or the total the answer reports (an answer without
     * one stops at a short page). A panel past MAX_PAGES is not listed at once: the sync then asks for each service on
     * its own.
     *
     * @throws UnsupportedOperationException past MAX_PAGES
     */
    public function listClients(): array
    {
        $clients = [];
        $seen = 0;
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $answer = $this->client->get(self::CLIENTS . '/search', ['page' => $page, 'limit' => self::PAGE_SIZE]);
            $rows = Raw::objectsOf($answer['data'] ?? null);
            foreach ($rows as $row) {
                $seen++;
                $name = Raw::string($row, 'email');
                if ($name !== '' && !isset($clients[$name])) {
                    $this->ids[$name] = Raw::string($row, 'id');
                    $clients[$name] = $this->clientOf($row);
                }
            }

            $total = Raw::int($answer, 'total', -1);
            if ($rows === [] || ($total >= 0 ? $seen >= $total : count($rows) < self::PAGE_SIZE)) {
                return array_values($clients);
            }
        }

        throw new UnsupportedOperationException(sprintf('The HMPanel panel lists more than %d clients; its clients are read one by one.', self::MAX_PAGES * self::PAGE_SIZE));
    }

    /**
     * A subscription link's token or subscription id, or a config's UUID, looked up (/v1/clients/lookup: the panel
     * matching its own stored values) — at most LOOKUPS_MAX of them, the link's first. The client answered counts only
     * when the value is one of its secrets (carries()): lookup matches a client's name too, and a name is no secret.
     */
    public function findClientByLink(ClientLink $link): ?ClientInfo
    {
        $values = [
            ...array_filter($link->tokens, static fn(string $token): bool => preg_match(self::TOKEN, $token) === 1),
            ...array_filter($link->credentials, static fn(string $credential): bool => preg_match(self::UUID, $credential) === 1),
        ];
        foreach (array_slice(array_values(array_unique($values)), 0, self::LOOKUPS_MAX) as $value) {
            try {
                $client = $this->client->get(self::CLIENTS . '/lookup', ['key' => $value]);
            } catch (NotFoundException) {
                continue;
            }
            if (self::carries($client, $value)) {
                $this->ids[Raw::string($client, 'email')] = Raw::string($client, 'id');

                return $this->clientOf($client);
            }
        }

        return null;
    }

    public function resetClientTraffic(string $name): void
    {
        $this->byName($name, fn(string $id): array => $this->client->post(self::path($id) . '/reset-usage'));
    }

    /**
     * A client just made, switched off. One the panel will not switch off is taken back: nothing was made. One it will
     * not take back either is there switched on, a failure after the write (ProviderException::afterWrite()).
     *
     * @throws ProviderException
     */
    private function switchedOff(string $name, string $id): void
    {
        try {
            $this->client->patch(self::path($id), ['enable' => false]);
        } catch (ProviderException $e) {
            try {
                $this->client->delete(self::path($id));
            } catch (ProviderException) {
                throw $e->afterWrite();
            }
            unset($this->ids[$name]);

            throw $e;
        }
    }

    /**
     * The client after a write the panel took: a read that fails then is a failure after the write.
     *
     * @throws ProviderException
     */
    private function readAfterWrite(string $id): ClientInfo
    {
        try {
            return $this->clientOf($this->client->get(self::path($id)));
        } catch (ProviderException $e) {
            throw $e->afterWrite();
        }
    }

    /**
     * `$call` with the id of the client by this name: the one kept, else looked up. A kept id the panel no longer knows
     * (the client deleted and made again by hand) is looked up afresh once — unless the call failed after a write.
     *
     * @template T
     * @param \Closure(string): T $call
     * @return T
     * @throws NotFoundException when the panel has no client by that name
     * @throws ProviderException
     */
    private function byName(string $name, \Closure $call): mixed
    {
        $kept = $this->ids[$name] ?? null;
        if ($kept !== null) {
            try {
                return $call($kept);
            } catch (NotFoundException $e) {
                unset($this->ids[$name]);
                if ($e->mayHaveBeenDone()) {
                    throw $e;
                }
            }
        }

        return $call($this->idOf($name));
    }

    /**
     * HMPanel's id of the client by this name.
     *
     * @throws NotFoundException when it has none
     * @throws ProviderException
     */
    private function idOf(string $name): string
    {
        $client = $this->named($name) ?? throw new NotFoundException("HMPanel has no client \"{$name}\".");

        return Raw::string($client, 'id');
    }

    /**
     * The client by this name, as HMPanel answers it; null when it has none. Lookup matches a name among a client's
     * tokens and ids too, so a client it answers under another name is not this one: the name is then searched for.
     *
     * @return array<string, mixed>|null
     * @throws ProviderException
     */
    private function named(string $name): ?array
    {
        try {
            $client = $this->client->get(self::CLIENTS . '/lookup', ['key' => $name]);
        } catch (NotFoundException) {
            return null;
        }
        if (Raw::string($client, 'email') !== $name) {
            $found = Raw::objectsOf($this->client->get(self::CLIENTS . '/search', ['search' => $name, 'limit' => self::PAGE_SIZE])['data'] ?? null);
            $client = array_values(array_filter($found, static fn(array $row): bool => Raw::string($row, 'email') === $name))[0] ?? null;
            if ($client === null) {
                return null;
            }
        }
        $this->ids[$name] = Raw::string($client, 'id');

        return $client;
    }

    /**
     * Whether `$value`, which lookup answered `$client` for, is one of the client's secrets: its HMPanel token or its
     * subscription id — or, on a 3x-ui panel, where nothing else is left for it to have matched but its name, its UUID.
     *
     * @param array<string, mixed> $client
     */
    private static function carries(array $client, string $value): bool
    {
        if ($value === Raw::string($client, 'subToken') || $value === Raw::string($client, 'subId')) {
            return true;
        }

        return $value !== Raw::string($client, 'email')
            && strtolower(Raw::string(Raw::array($client, 'panel'), 'type')) === self::THREE_XUI
            && preg_match(self::UUID, $value) === 1;
    }

    /**
     * The comment (which carries the Telegram id) as the client's remark — HMPanel's API takes no Telegram id; nothing to
     * say leaves the panel's remark alone.
     *
     * @return array{remark?: string}
     */
    private static function remark(ClientSpec $spec): array
    {
        $remark = $spec->comment ?? ($spec->telegramId !== null ? (string) $spec->telegramId : null);

        return $remark !== null ? ['remark' => $remark] : [];
    }

    /**
     * A client as the contract's: HMPanel's copy of its 3x-ui counters, quota, term and device limit.
     *
     * @param array<string, mixed> $client
     */
    private function clientOf(array $client): ClientInfo
    {
        return new ClientInfo(
            name: Raw::string($client, 'email'),
            enabled: Raw::bool($client, 'enable', Raw::string($client, 'status') !== 'disabled'),
            uploadBytes: Raw::int($client, 'upBytes'),
            downloadBytes: Raw::int($client, 'downBytes'),
            totalBytes: Raw::int($client, 'totalBytes'),
            expiry: ExpiryTime::toExpiry(Raw::int($client, 'expiryTime')),
            subscriptionUrl: $this->linkOf($client),
            ipLimit: Raw::int($client, 'limitIp'),
        );
    }

    /**
     * The link the customer gets: HMPanel's own, made absolute against the panel's address when it is a path, or the
     * server's prefix in front of the client's token (its HMPanel token, else its subscription id) when one is set.
     *
     * @param array<string, mixed> $client
     */
    private function linkOf(array $client): ?string
    {
        if ($this->connection->subscriptionUrl !== null) {
            $token = Raw::string($client, 'subToken') !== '' ? Raw::string($client, 'subToken') : Raw::string($client, 'subId');

            return $token !== '' ? $this->connection->subscriptionUrl . '/' . rawurlencode($token) : null;
        }

        $url = Raw::string($client, 'subscriptionUrl');
        if ($url === '') {
            return null;
        }

        return str_starts_with($url, '/') ? $this->connection->baseUrl . $url : $url;
    }

    private static function path(string $id): string
    {
        return self::CLIENTS . '/' . rawurlencode($id);
    }
}
