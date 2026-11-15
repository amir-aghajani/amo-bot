<?php

declare(strict_types=1);

namespace App\Modules\Imports\Migrators\MirzaBot;

use App\Modules\Imports\Records\ImportedPanel;
use App\Modules\Imports\Staging\Staged;
use App\Modules\Providers\Support\PanelConnection;

/**
 * What the panels of every bot of MirzaBot's line (its Panels: its `marzban_panel` rows by name, in the bot's order, as
 * `$rows`) answer alike: which hold clients and which configs sold by hand, a panel by the code its discount codes name it
 * by, the connector each kind is added through (the class's DRIVERS), and the panels as the import takes them. What
 * each bot keeps its own way the class says: its way in to a panel (way()), whether a panel was open to new sales
 * (open()), and whether an extra's price was set (priced()).
 */
trait LinePanels
{
    /** Configs sold by hand («فروش دستی»): no panel behind them. */
    private const MANUAL = 'Manualsale';

    /** A Marzban panel whose «نسخه پنل» is 1 speaks PasarGuard's API (as the bot adds a PasarGuard panel). */
    private const PASARGUARD_DIALECT = '1';

    /** Customers' links went through the old bot's own address (its «لینک ساب اختصاصی»), which goes with it. */
    private const LINKS_THROUGH_BOT = 'onsubvip';

    /** What the bot sold on a panel beside its products (`priceextravolume` …): extra traffic and days, a custom build. */
    private const EXTRA_PRICES = ['priceextravolume', 'priceextratime', 'pricecustomvolume', 'pricecustomtime'];

    private const NO_CONNECTOR = 'این فروشگاه هنوز کانکتوری برای این نوع پنل ندارد.';

    /** Whether `$name` is a panel with clients on it (not a «فروش دستی» one, nor one the bot no longer has). */
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

    /** The name of the panel the bot's code `$code` (`code_panel`, what its discount codes name a panel by) is; null for none. */
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
     * Every panel with clients, by name, in the bot's order.
     *
     * @return list<string>
     */
    public function panels(): array
    {
        return array_values(array_filter($this->names(), $this->isPanel(...)));
    }

    /**
     * The panels whose customers' links went through the old bot.
     *
     * @return list<string>
     */
    public function linkedThroughBot(): array
    {
        return array_values(array_filter($this->panels(), fn(string $name): bool => trim((string) ($this->rows[$name]['subvip'] ?? '')) === self::LINKS_THROUGH_BOT));
    }

    /** How many panels had prices of their own for extras (extra traffic and days, a custom build). */
    public function withExtraPrices(): int
    {
        return count(array_filter($this->panels(), fn(string $name): bool => array_filter(self::EXTRA_PRICES, fn(string $column): bool => self::priced($this->rows[$name][$column] ?? null)) !== []));
    }

    /**
     * Every panel with clients, as the import takes it: switched off when the bot had closed it to new sales (open()), as
     * full as its sales limit (`limit_panel`: a number, or «unlimited») when it had one.
     *
     * @param array<string, int> $services The services the import would bring of each, by name
     * @return list<ImportedPanel>
     */
    public function imported(array $services): array
    {
        $panels = [];
        foreach ($this->panels() as $name) {
            $row = $this->rows[$name];
            $driver = self::driverOf($row);
            $panels[] = new ImportedPanel(
                key: $name,
                name: $name,
                type: trim((string) ($row['type'] ?? '')),
                url: Cell::text($row['url_panel'] ?? null),
                driver: $driver,
                unsupported: $driver === null ? self::NO_CONNECTOR : null,
                connection: $driver === null ? [] : $this->connection($driver, $row),
                services: $services[$name] ?? 0,
                active: self::open($row),
                capacity: preg_match('/^\d{1,9}$/', trim((string) ($row['limit_panel'] ?? ''))) === 1 ? (int) trim((string) $row['limit_panel']) : null,
            );
        }

        return $panels;
    }

    /**
     * The staged copy's panels by name, the first row of a name; a row without a name is none (nothing names it).
     *
     * @return array<string, array<string, ?string>>
     */
    private static function rowsOf(Staged $staged): array
    {
        $rows = [];
        foreach ($staged->all('marzban_panel') as $row) {
            $name = Cell::text($row['name_panel'] ?? null);
            if ($name !== null && !isset($rows[$name])) {
                $rows[$name] = $row;
            }
        }

        return $rows;
    }

    /**
     * The panels' names in the bot's order, as text (PHP keeps a key of digits alone as a number).
     *
     * @return list<string>
     */
    private function names(): array
    {
        return array_map(strval(...), array_keys($this->rows));
    }

    /**
     * The connector a panel is added through (PanelDriver::key(), by its kind in the class's DRIVERS); null for a kind
     * none speaks to yet.
     *
     * @param array<string, ?string> $row
     */
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
     * name): its address, its way in as the bot kept it (way()), the TLS check, the default timeout, the subscription
     * links' prefix 3x-ui's links were built on, no proxy. Empty without an address or a way in.
     *
     * @param array<string, ?string> $row
     * @return array<string, mixed>
     */
    private function connection(string $driver, array $row): array
    {
        $url = Cell::text($row['url_panel'] ?? null);
        $way = $this->way($driver, $row);
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
     * What 3x-ui's links started with in the bot (`linksubx`: a client's link is it, a slash and its subId), so a
     * customer's link stays the one they have; blank for the panel's own when it was never set (the bot starts it as the
     * panel's address, where no link is served).
     *
     * @param array<string, ?string> $row
     */
    private static function threeXuiLinks(array $row, string $url): string
    {
        $links = rtrim((string) Cell::text($row['linksubx'] ?? null), '/');

        return preg_match('#^https?://#i', $links) === 1 && strcasecmp($links, $url) !== 0 ? $links : '';
    }

    /**
     * The connection form's way in to a panel the connector `$driver` adds, as the bot kept it: an API token's fields, or
     * an admin's login's; null for none.
     *
     * @param array<string, ?string> $row
     * @return array<string, string>|null
     */
    abstract private function way(string $driver, array $row): ?array;

    /**
     * Whether the bot kept a panel open to new sales (`status`).
     *
     * @param array<string, ?string> $row
     */
    abstract private static function open(array $row): bool;

    /** Whether an extra's price (`priceextravolume` …) is set. */
    abstract private static function priced(?string $value): bool;
}
