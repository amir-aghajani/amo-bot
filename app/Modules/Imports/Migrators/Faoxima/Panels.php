<?php

declare(strict_types=1);

namespace App\Modules\Imports\Migrators\Faoxima;

use App\Modules\Imports\Records\ImportedPanel;
use App\Modules\Imports\Staging\Staged;
use App\Modules\Providers\Enums\AuthMode;
use App\Modules\Providers\Support\PanelConnection;
use App\Modules\Providers\Support\PanelCredentials;

/**
 * Faoxima's panels (`marzban_panel`), by the name its services and plans give them: which of this shop's connectors
 * speaks to each kind, and the server form's values that add one — its address, its way in as Faoxima kept it (an API
 * token, or an admin's username and password) and the connection's options at their defaults. A «فروش دستی» panel
 * holds configs sold by hand, no clients: it is no panel here.
 */
final class Panels
{
    /**
     * The connector each kind is added through (PanelDriver::key()); null for a kind none speaks to yet. Rebecca —
     * Marzban's fork, signed in to with a static API token — waits for a connector that takes its token: a connector's
     * key here brings it in, its API key as that connector's token.
     */
    private const DRIVERS = [
        'marzban' => 'marzban',
        'pasarguard' => 'pasarguard',
        'pasargard' => 'pasarguard',
        'x-ui_single' => '3x-ui',
        'rebecca' => null,
        'remnawave' => null,
        'guard' => null,
        'WGDashboard' => null,
    ];

    /** A Marzban panel whose «نسخه پنل» is 1 speaks PasarGuard's API (Faoxima's isPasarGuardDialect()). */
    private const PASARGUARD_DIALECT = '1';

    /** Configs sold by hand (Faoxima's «فروش دستی»): no panel behind them. */
    private const MANUAL = 'Manualsale';

    /** Customers' links went through the old bot's own address (its «لینک ساب اختصاصی»), which goes with it. */
    private const LINKS_THROUGH_BOT = 'onsubvip';

    /** A panel Faoxima closed to new sales (`status`, «وضعیت فروش»); every other is `active`. */
    private const CLOSED = 'disable';

    /** What Faoxima sold on a panel beside its products (`priceextravolume` …): extra traffic and days, a custom build. */
    private const EXTRA_PRICES = ['priceextravolume', 'priceextratime', 'pricecustomvolume', 'pricecustomtime'];

    private const NO_CONNECTOR = 'این فروشگاه هنوز کانکتوری برای این نوع پنل ندارد.';

    /** @param array<string, array<string, ?string>> $rows By name, in Faoxima's order */
    private function __construct(private readonly array $rows) {}

    /** The staged copy's panels; a row without a name is none (nothing names it). */
    public static function of(Staged $staged): self
    {
        $rows = [];
        foreach ($staged->all('marzban_panel') as $row) {
            $name = Cell::text($row['name_panel'] ?? null);
            if ($name !== null && !isset($rows[$name])) {
                $rows[$name] = $row;
            }
        }

        return new self($rows);
    }

    /** Whether `$name` is a panel with clients on it (not a «فروش دستی» one, nor one Faoxima no longer has). */
    public function isPanel(?string $name): bool
    {
        return $name !== null && isset($this->rows[$name]) && !$this->isManual($name);
    }

    /** Whether `$name` is a «فروش دستی» panel: configs sold by hand. */
    public function isManual(?string $name): bool
    {
        return $name !== null && trim((string) ($this->rows[$name]['type'] ?? '')) === self::MANUAL;
    }

    /** Whether one of this shop's connectors speaks to the panel `$name`. */
    public function hasConnector(string $name): bool
    {
        return isset($this->rows[$name]) && self::driverOf($this->rows[$name]) !== null;
    }

    /** The name of the panel Faoxima's code `$code` (`code_panel`, what its discount codes name a panel by) is; null for none. */
    public function nameOf(string $code): ?string
    {
        foreach ($this->names() as $name) {
            if (Cell::text($this->rows[$name]['code_panel'] ?? null) === $code) {
                return $name;
            }
        }

        return null;
    }

    /**
     * The panels whose customers' links went through the old bot.
     *
     * @return list<string>
     */
    public function linkedThroughBot(): array
    {
        return array_values(array_filter($this->names(), fn(string $name): bool => $this->isPanel($name) && trim((string) ($this->rows[$name]['subvip'] ?? '')) === self::LINKS_THROUGH_BOT));
    }

    /**
     * The 3x-ui panels Faoxima signed in to with an admin's login (`xui_api_mode` legacy): its older API, which a panel
     * before 3x-ui v3 speaks — this shop's connector needs v3 or later.
     *
     * @return list<string>
     */
    public function oldThreeXui(): array
    {
        return array_values(array_filter($this->names(), fn(string $name): bool => trim((string) ($this->rows[$name]['type'] ?? '')) === 'x-ui_single' && self::threeXuiToken($this->rows[$name]) === null));
    }

    /** How many panels had prices of their own for extras (extra traffic and days, a custom build). */
    public function withExtraPrices(): int
    {
        return count(array_filter(
            $this->names(),
            fn(string $name): bool => $this->isPanel($name) && array_filter(self::EXTRA_PRICES, fn(string $column): bool => Cell::text($this->rows[$name][$column] ?? null) !== null) !== [],
        ));
    }

    /**
     * Every panel with clients, as the import takes it: switched off when Faoxima had closed it to new sales, as full as
     * its sales limit (`limit_panel`: a number, or «unlimited») when it had one.
     *
     * @param array<string, int> $services The services the import would bring of each, by name
     * @return list<ImportedPanel>
     */
    public function imported(array $services): array
    {
        $panels = [];
        foreach ($this->names() as $name) {
            $row = $this->rows[$name];
            if ($this->isManual($name)) {
                continue;
            }
            $driver = self::driverOf($row);
            $panels[] = new ImportedPanel(
                key: $name,
                name: $name,
                type: trim((string) ($row['type'] ?? '')),
                url: Cell::text($row['url_panel'] ?? null),
                driver: $driver,
                unsupported: $driver === null ? self::NO_CONNECTOR : null,
                connection: $driver === null ? [] : self::connection($driver, $row),
                services: $services[$name] ?? 0,
                active: strtolower(trim((string) ($row['status'] ?? ''))) !== self::CLOSED,
                capacity: preg_match('/^\d{1,9}$/', trim((string) ($row['limit_panel'] ?? ''))) === 1 ? (int) trim((string) $row['limit_panel']) : null,
            );
        }

        return $panels;
    }

    /**
     * The panels' names in Faoxima's order, as text (PHP keeps a key of digits alone as a number).
     *
     * @return list<string>
     */
    private function names(): array
    {
        return array_map(strval(...), array_keys($this->rows));
    }

    /** @param array<string, ?string> $row */
    private static function driverOf(array $row): ?string
    {
        $type = trim((string) ($row['type'] ?? ''));
        if ($type === 'marzban' && trim((string) ($row['version_panel'] ?? '')) === self::PASARGUARD_DIALECT) {
            return 'pasarguard';
        }

        return self::DRIVERS[$type] ?? null;
    }

    /**
     * The connector's form values that add the panel as a server (ServerService::create() with its `driver` and a
     * name): its address, its way in — the API token Faoxima signed in with, else the admin's login —, the TLS check,
     * the default timeout, the subscription links' prefix 3x-ui's links were built on, no proxy. Empty without an
     * address or a way in.
     *
     * @param array<string, ?string> $row
     * @return array<string, mixed>
     */
    private static function connection(string $driver, array $row): array
    {
        $url = Cell::text($row['url_panel'] ?? null);
        $token = match (trim((string) ($row['type'] ?? ''))) {
            'x-ui_single' => self::threeXuiToken($row),
            'pasarguard', 'pasargard' => self::pasarGuardToken($row),
            'rebecca' => Cell::text($row['api_key'] ?? null),
            default => null,
        };
        $username = Cell::text($row['username_panel'] ?? null);
        $password = Cell::text($row['password_panel'] ?? null);
        $way = match (true) {
            $token !== null => [PanelCredentials::MODE => AuthMode::Token->value, 'api_token' => $token],
            $username !== null && $password !== null => [PanelCredentials::MODE => AuthMode::Password->value, 'username' => $username, 'password' => $password],
            default => null,
        };
        if ($url === null || $way === null) {
            return [];
        }
        $url = rtrim($url, '/');

        return [PanelConnection::ADDRESS => $url] + $way + [
            'verify_tls' => true,
            'timeout' => PanelConnection::DEFAULT_TIMEOUT,
            'subscription_url' => $driver === '3x-ui' ? self::threeXuiLinks($row, $url) : '',
            PanelConnection::PROXY => false,
        ];
    }

    /**
     * 3x-ui's API token when Faoxima signed in with it (`xui_api_mode` token, or no mode and a token kept); null for the
     * admin's login (legacy).
     *
     * @param array<string, ?string> $row
     */
    private static function threeXuiToken(array $row): ?string
    {
        return trim((string) ($row['xui_api_mode'] ?? '')) === 'legacy' ? null : Cell::text($row['xui_api_token'] ?? null);
    }

    /**
     * PasarGuard's API key unless Faoxima signed in with the admin's login (`pasarguard_auth_mode` password).
     *
     * @param array<string, ?string> $row
     */
    private static function pasarGuardToken(array $row): ?string
    {
        return trim((string) ($row['pasarguard_auth_mode'] ?? '')) === 'password' ? null : Cell::text($row['api_key'] ?? null);
    }

    /**
     * What 3x-ui's links started with in Faoxima (`linksubx`: a client's link is it, a slash and its subId), so a
     * customer's link stays the one they have; blank for the panel's own when it was never set (Faoxima starts it as
     * the panel's address, where no link is served).
     *
     * @param array<string, ?string> $row
     */
    private static function threeXuiLinks(array $row, string $url): string
    {
        $links = rtrim((string) Cell::text($row['linksubx'] ?? null), '/');

        return preg_match('#^https?://#i', $links) === 1 && strcasecmp($links, $url) !== 0 ? $links : '';
    }
}
