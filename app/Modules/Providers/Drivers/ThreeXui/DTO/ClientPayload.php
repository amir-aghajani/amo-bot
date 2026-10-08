<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\ThreeXui\DTO;

/**
 * A client row as /panel/api/clients/add and /update/{email} take it (schema Client). A new client needs only the
 * universal fields — the panel mints the protocol secrets (id, password, auth, keys, addresses) and the subscription id
 * itself. /update replaces the whole row, so an update starts from the row the panel has (fromRecord()) and changes
 * what it means to (with()).
 */
final class ClientPayload
{
    /**
     * @param list<string>|null $allowedIPs WireGuard/AmneziaWG addresses
     * @param array<string, mixed>|null $reverse VLESS reverse proxy settings ({tag})
     */
    public function __construct(
        public readonly string $email,
        public readonly bool $enable = true,
        /** Quota in bytes (the panel's `totalGB`), 0 = unlimited. */
        public readonly int $totalBytes = 0,
        /** See Support\ExpiryTime. */
        public readonly int $expiryTime = 0,
        public readonly int $limitIp = 0,
        public readonly int $limitHwid = 0,
        public readonly int $tgId = 0,
        /** Left blank on a new client: the panel mints it. */
        public readonly string $subId = '',
        public readonly string $comment = '',
        /** Renew every N days (0 = off) on calendar day `resetDay` (0 = interval), at most `resetMax` times (0 = unlimited). */
        public readonly int $reset = 0,
        public readonly int $resetDay = 0,
        public readonly int $resetMax = 0,
        /** vless / vmess UUID; null lets the panel make one. */
        public readonly ?string $id = null,
        public readonly ?string $password = null,
        public readonly ?string $auth = null,
        public readonly ?string $secret = null,
        /** XTLS flow (xtls-rprx-vision, …); an empty string clears it. */
        public readonly ?string $flow = null,
        public readonly ?string $security = null,
        public readonly ?string $group = null,
        /** never | hourly | daily | weekly | monthly */
        public readonly ?string $trafficReset = null,
        public readonly ?int $trafficResetDay = null,
        public readonly ?array $allowedIPs = null,
        public readonly ?string $publicKey = null,
        public readonly ?string $privateKey = null,
        public readonly ?string $preSharedKey = null,
        public readonly ?int $keepAlive = null,
        public readonly ?string $forwardedPorts = null,
        public readonly ?string $adTag = null,
        public readonly ?array $reverse = null,
    ) {}

    /** The row the panel has, ready to be changed with with() and sent to /update — nothing of it lost. */
    public static function fromRecord(ClientRecord $record): self
    {
        return new self(
            email: $record->email,
            enable: $record->enable,
            totalBytes: $record->totalBytes,
            expiryTime: $record->expiryTime,
            limitIp: $record->limitIp,
            limitHwid: $record->limitHwid,
            tgId: $record->tgId,
            subId: $record->subId,
            comment: $record->comment,
            reset: $record->reset,
            resetDay: $record->resetDay,
            resetMax: $record->resetMax,
            id: self::given($record->uuid),
            password: self::given($record->password),
            auth: self::given($record->auth),
            secret: self::given($record->secret),
            flow: $record->flow,
            security: self::given($record->security),
            group: self::given($record->group),
            trafficReset: self::given($record->trafficReset),
            trafficResetDay: $record->trafficResetDay,
            allowedIPs: $record->allowedIPs !== '' ? array_values(array_filter(array_map(trim(...), explode(',', $record->allowedIPs)))) : null,
            publicKey: self::given($record->publicKey),
            privateKey: self::given($record->privateKey),
            preSharedKey: self::given($record->preSharedKey),
            keepAlive: $record->keepAlive,
            forwardedPorts: self::given($record->forwardedPorts),
            adTag: self::given($record->adTag),
            reverse: $record->reverse,
        );
    }

    /** @param array<string, mixed> $changes By constructor argument name */
    public function with(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    /** @return array<string, mixed> The row as the panel takes it: an optional field left out when it has nothing. */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'enable' => $this->enable,
            'totalGB' => $this->totalBytes,
            'expiryTime' => $this->expiryTime,
            'limitIp' => $this->limitIp,
            'limitHwid' => $this->limitHwid,
            'tgId' => $this->tgId,
            'subId' => $this->subId,
            'comment' => $this->comment,
            'reset' => $this->reset,
            'resetDay' => $this->resetDay,
            'resetMax' => $this->resetMax,
        ] + array_filter([
            'id' => $this->id,
            'password' => $this->password,
            'auth' => $this->auth,
            'secret' => $this->secret,
            'flow' => $this->flow,
            'security' => $this->security,
            'group' => $this->group,
            'trafficReset' => $this->trafficReset,
            'trafficResetDay' => $this->trafficResetDay,
            'allowedIPs' => $this->allowedIPs,
            'publicKey' => $this->publicKey,
            'privateKey' => $this->privateKey,
            'preSharedKey' => $this->preSharedKey,
            'keepAlive' => $this->keepAlive,
            'forwardedPorts' => $this->forwardedPorts,
            'adTag' => $this->adTag,
            'reverse' => $this->reverse,
        ], static fn(mixed $value): bool => $value !== null);
    }

    /** A field the row has, or null when it has none — so the panel keeps (or mints) its own. */
    private static function given(string $value): ?string
    {
        return $value !== '' ? $value : null;
    }
}
