<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\ThreeXui;

use App\Modules\Providers\Contracts\ProviderInterface;
use App\Modules\Providers\Drivers\ThreeXui\DTO\ClientPayload;
use App\Modules\Providers\Drivers\ThreeXui\DTO\ClientRecord;
use App\Modules\Providers\Drivers\ThreeXui\DTO\Inbound;
use App\Modules\Providers\Drivers\ThreeXui\DTO\PanelSettings;
use App\Modules\Providers\Drivers\ThreeXui\DTO\Traffic;
use App\Modules\Providers\Drivers\ThreeXui\Support\ExpiryTime;
use App\Modules\Providers\Drivers\ThreeXui\Support\Raw;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\DTO\ClientSpec;
use App\Modules\Providers\DTO\InboundInfo;
use App\Modules\Providers\DTO\PanelStatus;
use App\Modules\Providers\Enums\CoreState;
use App\Modules\Providers\Exceptions\NotFoundException;
use App\Modules\Providers\Support\PanelConnection;
use Ramsey\Uuid\Uuid;

/**
 * ProviderInterface for 3x-ui v3 (MHSanaei) panels — the client of one server's panel, which its connector builds
 * (ThreeXuiDriver::connect()).
 *
 * Clients are the panel's first-class client rows (/panel/api/clients): one identity on any number of inbounds,
 * addressed by email — the contract's `name`. Inbound keys are the panel's inbound ids, as strings. The subscription
 * link is built the way the panel builds it, from its settings and the client's subscription id — or, behind a reverse
 * proxy its settings do not describe, from the server's own prefix (`meta.subscription_url`).
 *
 * Server columns: base_url (with the web base path), api_token (preferred) or username/password, plus totp_secret when
 * the panel's two-factor login is on; meta: verify_tls, timeout, subscription_url (Support\PanelConnection).
 */
final class ThreeXuiProvider implements ProviderInterface
{
    /** The panel's settings, read once for the links of the clients this instance maps (servesSubscriptions() reads them anew). */
    private ?PanelSettings $settings = null;

    public function __construct(
        private readonly ThreeXuiApi $api,
        private readonly PanelConnection $connection,
    ) {}

    public function testConnection(): void
    {
        $this->api->inboundOptions();
    }

    public function status(): PanelStatus
    {
        $status = $this->api->status();

        return new PanelStatus(
            cpuPercent: $status->cpu,
            memoryUsedBytes: $status->memUsed,
            memoryTotalBytes: $status->memTotal,
            diskUsedBytes: $status->diskUsed,
            diskTotalBytes: $status->diskTotal,
            coreState: match ($status->xrayState) {
                'running' => CoreState::Running,
                'stop', 'stopped' => CoreState::Stopped,
                'error' => CoreState::Error,
                default => CoreState::Unknown,
            },
            coreName: 'Xray',
            coreVersion: $status->xrayVersion,
            coreError: $status->xrayError,
            uptimeSeconds: $status->uptime,
            connections: $status->connections,
        );
    }

    public function listInbounds(): array
    {
        return array_map(static fn(Inbound $inbound): InboundInfo => new InboundInfo(
            key: (string) $inbound->id,
            tag: $inbound->tag,
            remark: $inbound->remark,
            enabled: $inbound->enable,
            protocol: $inbound->protocol,
            port: $inbound->port,
            network: $inbound->network,
            security: $inbound->security,
            clientCount: $inbound->clientCount,
        ), $this->api->inbounds());
    }

    /** Asked anew every time — the question is "right now" —, and what it read serves the links that follow. */
    public function servesSubscriptions(): bool
    {
        $this->settings = $this->api->settings();

        return $this->settings->subscriptionsEnabled();
    }

    public function createClient(array $inboundKeys, ClientSpec $spec): ClientInfo
    {
        if ($inboundKeys === []) {
            throw new \InvalidArgumentException('A 3x-ui client lives on inbounds: pass at least one inbound key.');
        }

        // The subscription id is left blank: the panel mints one, and the read-back learns it.
        $payload = new ClientPayload(
            email: $spec->name,
            totalBytes: $spec->totalBytes,
            expiryTime: ExpiryTime::of($spec->expiry),
            limitIp: $spec->ipLimit,
            tgId: $spec->telegramId ?? 0,
            comment: $spec->comment ?? '',
        );
        $this->api->addClient($payload, array_values(array_unique(array_map(intval(...), $inboundKeys))));

        // A client the panel took but cannot show again is answered as it was sent, without a link — only the panel
        // knows the one it minted — so the caller never sells it, and takes it back.
        $record = $this->api->client($spec->name) ?? ClientRecord::fromArray($payload->toArray() + ['traffic' => ['up' => 0, 'down' => 0]]);

        return $this->clientOf($record, null);
    }

    public function updateClient(ClientSpec $spec): ClientInfo
    {
        $existing = $this->existing($spec->name);

        // update() replaces the row: it starts from what the panel has, so the credentials, the subscription id and the
        // protocol's own fields (keys, addresses, flow) stay.
        $this->api->updateClient($spec->name, ClientPayload::fromRecord($existing)->with([
            'enable' => true,
            'totalBytes' => $spec->totalBytes,
            'expiryTime' => ExpiryTime::of($spec->expiry),
            'limitIp' => $spec->ipLimit,
            'tgId' => $spec->telegramId ?? $existing->tgId,
            'comment' => $spec->comment ?? $existing->comment,
        ]));

        return $this->readBack($spec->name);
    }

    /**
     * update() replaces the row, so the new secrets ride on a copy of the existing one: a new UUID where the client has
     * one (vless/vmess), a new password where it has one (trojan/shadowsocks — 32 random bytes in base64, also a valid
     * 2022 key; a cipher wanting 16 bytes makes the panel mint its own, and the read-back learns it), a new hysteria
     * auth, and always a new subscription id (a UUID, as the panel mints them). mtproto secrets and WireGuard keys are
     * derived or paired on the panel's side and stay.
     */
    public function rotateClientCredentials(string $name): ClientInfo
    {
        $existing = $this->existing($name);

        $changes = ['subId' => Uuid::uuid4()->toString()];
        if ($existing->uuid !== '') {
            $changes['id'] = Uuid::uuid4()->toString();
        }
        if ($existing->password !== '') {
            $changes['password'] = base64_encode(random_bytes(32));
        }
        if ($existing->auth !== '') {
            $changes['auth'] = bin2hex(random_bytes(16));
        }

        $this->api->updateClient($name, ClientPayload::fromRecord($existing)->with($changes));

        return $this->readBack($name);
    }

    public function setClientEnabled(string $name, bool $enabled): void
    {
        $this->api->setClientEnabled($name, $enabled);
    }

    public function deleteClient(string $name): void
    {
        $this->api->deleteClient($name);
    }

    public function findClient(string $name, bool $presence = false): ?ClientInfo
    {
        $record = $this->api->client($name);
        if ($record === null) {
            return null;
        }

        return $this->clientOf($record, $presence ? in_array($name, $this->api->onlines(), true) : null);
    }

    /** /clients/list: every row with its counters, and one read of the settings for every link. */
    public function listClients(): array
    {
        return array_map(fn(ClientRecord $record): ClientInfo => $this->clientOf($record, null), $this->api->clients());
    }

    public function resetClientTraffic(string $name): void
    {
        $this->api->resetClientTraffic($name);
    }

    /** The client a write left, as the panel stored it — its secrets, its subscription id, its counters. */
    private function readBack(string $name): ClientInfo
    {
        return $this->clientOf($this->existing($name), null);
    }

    /** @throws NotFoundException */
    private function existing(string $name): ClientRecord
    {
        return $this->api->client($name) ?? throw new NotFoundException("3x-ui has no client \"{$name}\".");
    }

    /** The row as the contract's client: its counters are on the row (a list's) or asked for (a single row's). */
    private function clientOf(ClientRecord $record, ?bool $online): ClientInfo
    {
        $traffic = $record->traffic ?? $this->api->traffic($record->email) ?? new Traffic(0, 0);

        return new ClientInfo(
            name: $record->email,
            enabled: $record->enable,
            uploadBytes: $traffic->up,
            downloadBytes: $traffic->down,
            totalBytes: $record->totalBytes,
            expiry: ExpiryTime::toExpiry($record->expiryTime),
            subscriptionUrl: $this->linkOf($record),
            online: $online,
            lastOnlineAt: Raw::millis($traffic->lastOnline),
        );
    }

    /** The link the panel serves for the client: none without a subscription id, or with the subscription server off. */
    private function linkOf(ClientRecord $record): ?string
    {
        if ($record->subId === '') {
            return null;
        }

        $this->settings ??= $this->api->settings();
        if (!$this->settings->subscriptionsEnabled()) {
            return null;
        }
        $base = $this->connection->subscriptionUrl !== null
            ? $this->connection->subscriptionUrl . '/'
            : $this->settings->subscriptionBase((string) parse_url($this->connection->baseUrl, PHP_URL_HOST));

        return $base . rawurlencode($record->subId);
    }
}
