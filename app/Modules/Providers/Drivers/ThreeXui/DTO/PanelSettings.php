<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\ThreeXui\DTO;

use App\Modules\Providers\Drivers\ThreeXui\Support\Raw;

/**
 * The panel's settings (POST /panel/api/setting/all, ~120 keys) — the part the shop reads: its subscription server,
 * whether it is on and where it serves a client's link.
 */
final class PanelSettings
{
    /** @param array<string, mixed> $all */
    public function __construct(private readonly array $all) {}

    public function subscriptionsEnabled(): bool
    {
        return Raw::bool($this->all, 'subEnable');
    }

    /**
     * What a subscription id is appended to (with a trailing slash), as the panel builds its links: its reverse-proxy
     * URI when one is set, otherwise the scheme (https once the subscription server has a certificate), its domain — or
     * the panel's own host — its port and its path.
     */
    public function subscriptionBase(string $panelHost): string
    {
        $uri = Raw::string($this->all, 'subURI');
        if ($uri !== '') {
            return rtrim($uri, '/') . '/';
        }

        $domain = Raw::string($this->all, 'subDomain');
        $host = $domain !== '' ? $domain : $panelHost;
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = "[{$host}]"; // a bare IPv6 address
        }
        $tls = Raw::string($this->all, 'subCertFile') !== '' && Raw::string($this->all, 'subKeyFile') !== '';

        return sprintf('%s://%s:%d/%s/', $tls ? 'https' : 'http', $host, Raw::int($this->all, 'subPort', 2096), trim(Raw::string($this->all, 'subPath', '/sub/'), '/'));
    }
}
