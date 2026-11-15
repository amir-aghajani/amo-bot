<?php

declare(strict_types=1);

namespace App\Modules\Imports\Migrators\Faoxima;

use App\Modules\Imports\Migrators\MirzaBot\Cell;
use App\Modules\Imports\Migrators\MirzaBot\LinePanels;
use App\Modules\Imports\Staging\Staged;
use App\Modules\Providers\Enums\AuthMode;
use App\Modules\Providers\Support\PanelCredentials;

/**
 * Faoxima's panels (`marzban_panel`), by the name its services and plans give them: which of this shop's connectors
 * speaks to each kind, and the server form's values that add one — its address, its way in as Faoxima kept it (an API
 * token, or an admin's username and password) and the connection's options at their defaults (LinePanels). A «فروش دستی»
 * panel holds configs sold by hand, no clients: it is no panel here.
 */
final class Panels
{
    use LinePanels;

    /**
     * The connector each kind is added through (PanelDriver::key()); null for a kind none speaks to yet. Rebecca, Marzban's
     * descendant, is signed in to with the `rk_…` API key Faoxima kept (`api_key`), Remnawave with its API token
     * (`remna_api_token`).
     */
    private const DRIVERS = [
        'marzban' => 'marzban',
        'pasarguard' => 'pasarguard',
        'pasargard' => 'pasarguard',
        'x-ui_single' => '3x-ui',
        'rebecca' => 'rebecca',
        'remnawave' => 'remnawave',
        'guard' => null,
        'WGDashboard' => null,
    ];

    /** The connectors that take a panel's API token alone, never an admin's login. */
    private const TOKEN_ONLY = ['rebecca', 'remnawave'];

    /** A panel Faoxima closed to new sales (`status`, «وضعیت فروش»); every other is `active`. */
    private const CLOSED = 'disable';

    /** @param array<string, array<string, ?string>> $rows By name, in Faoxima's order */
    private function __construct(private readonly array $rows) {}

    /** The staged copy's panels (LinePanels::rowsOf()). */
    public static function of(Staged $staged): self
    {
        return new self(self::rowsOf($staged));
    }

    /**
     * The 3x-ui panels Faoxima signed in to with an admin's login (`xui_api_mode` legacy): its older API, which a panel
     * before 3x-ui v3 speaks — this shop's connector needs v3 or later.
     *
     * @return list<string>
     */
    public function oldThreeXui(): array
    {
        return array_values(array_filter($this->panels(), fn(string $name): bool => trim((string) ($this->rows[$name]['type'] ?? '')) === 'x-ui_single' && self::threeXuiToken($this->rows[$name]) === null));
    }

    /**
     * Its way in: the API token Faoxima signed in with, else the admin's login (never for Rebecca or Remnawave, whose
     * connectors take the token alone).
     *
     * @param array<string, ?string> $row
     * @return array<string, string>|null
     */
    private function way(string $driver, array $row): ?array
    {
        $token = match (trim((string) ($row['type'] ?? ''))) {
            'x-ui_single' => self::threeXuiToken($row),
            'pasarguard', 'pasargard' => self::pasarGuardToken($row),
            'rebecca' => Cell::text($row['api_key'] ?? null),
            'remnawave' => Cell::text($row['remna_api_token'] ?? null),
            default => null,
        };
        $username = Cell::text($row['username_panel'] ?? null);
        $password = Cell::text($row['password_panel'] ?? null);

        return match (true) {
            $token !== null => [PanelCredentials::MODE => AuthMode::Token->value, 'api_token' => $token],
            !in_array($driver, self::TOKEN_ONLY, true) && $username !== null && $password !== null => [PanelCredentials::MODE => AuthMode::Password->value, 'username' => $username, 'password' => $password],
            default => null,
        };
    }

    /** @param array<string, ?string> $row */
    private static function open(array $row): bool
    {
        return strtolower(trim((string) ($row['status'] ?? ''))) !== self::CLOSED;
    }

    /** Whether an extra's price is set: anything Faoxima kept there. */
    private static function priced(?string $value): bool
    {
        return Cell::text($value) !== null;
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
}
