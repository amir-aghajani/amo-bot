<?php

declare(strict_types=1);

namespace App\Modules\Imports\Migrators\MirzaBot;

use App\Modules\Imports\Staging\Staged;
use App\Modules\Providers\Enums\AuthMode;
use App\Modules\Providers\Support\PanelCredentials;

/**
 * MirzaBot's panels (`marzban_panel`), by the name its services and products give them, in its order (`display_order`,
 * then id): which of this shop's connectors speaks to each kind, and the server form's values that add one — its address,
 * its way in as MirzaBot kept it, the connection's options at their defaults (LinePanels). A «فروش دستی» panel holds
 * configs sold by hand, no clients: it is no panel here.
 */
final class Panels
{
    use LinePanels;

    /**
     * The connector each kind is added through (PanelDriver::key()); null for a kind none speaks to yet — among them the
     * kinds MirzaBot dropped (alireza_single, s_ui), which an old database may still have. Rebecca is signed in to with the
     * `rk_…` API key MirzaBot kept in `password_panel`.
     */
    private const DRIVERS = [
        'marzban' => 'marzban',
        'x-ui_single' => '3x-ui',
        'marzneshin' => null,
        'hiddify' => null,
        'WGDashboard' => null,
        'ibsng' => null,
        'mikrotik' => null,
        'mirza_agent' => null,
        'rebecca' => 'rebecca',
        'nexora' => null,
        'wg_mate' => null,
        'alireza_single' => null,
        's_ui' => null,
    ];

    /** A panel open to new sales (`status`); every other was closed. */
    private const OPEN = 'active';

    /** Who a panel was shown to (`agent`): everyone; else one audience alone — customers, or an agents' level. */
    private const EVERYONE = 'all';

    private const FOR_SOME = 'پنل %s در ربات قبلی فقط به بخشی از مشتری‌ها (کاربران عادی یا یکی از سطح‌های نمایندگی) نشان داده می‌شد؛ اینجا پلن‌های روی آن به همه فروخته می‌شوند، پس پیش از روشن کردنشان بررسی کنید.';
    private const HIDDEN_FROM = 'پنل %s در ربات قبلی از چند مشتری انتخاب‌شده پنهان بود؛ اینجا از کسی پنهان نمی‌ماند.';

    /**
     * @param array<string, array<string, ?string>> $rows By name, in MirzaBot's order
     * @param bool $later Whether the database is of a MirzaBot later than its move to 3x-ui's API tokens (0.1.9)
     */
    private function __construct(
        private readonly array $rows,
        private readonly bool $later,
    ) {}

    /**
     * The staged copy's panels (LinePanels::rowsOf()). Whether the bot is later than 0.1.9 its tables say: the edited
     * texts its settings kept from 0.2.x (`setting.text_edit`), or the `config` table its settings moved into in 0.9.3.
     */
    public static function of(Staged $staged): self
    {
        $rows = self::rowsOf($staged);
        $order = static fn(array $row): array => [Cell::whole($row['display_order'] ?? null), Cell::whole($row['id'] ?? null)];
        uasort($rows, static fn(array $a, array $b): int => $order($a) <=> $order($b));
        $later = $staged->count('config') > 0 || array_filter($staged->all('setting'), static fn(array $row): bool => ($row['text_edit'] ?? null) !== null) !== [];

        return new self($rows, $later);
    }

    /**
     * The 3x-ui panels signed in to with an admin's login, as a MirzaBot before 0.1.9 did: 3x-ui's older API, which a
     * panel before v3 speaks — this shop's connector needs v3 or later.
     *
     * @return list<string>
     */
    public function oldThreeXui(): array
    {
        return array_values(array_filter($this->panels(), fn(string $name): bool => trim((string) ($this->rows[$name]['type'] ?? '')) === 'x-ui_single' && !$this->onToken($this->rows[$name])));
    }

    /**
     * What the owner is told of panels MirzaBot showed to some customers alone — one audience (`agent`), or all but those
     * it hid it from (`hide_user`) —, which this shop does not keep.
     *
     * @return list<string>
     */
    public function hiding(): array
    {
        $forSome = array_values(array_filter($this->panels(), fn(string $name): bool => !in_array(strtolower(trim((string) ($this->rows[$name]['agent'] ?? ''))), ['', self::EVERYONE], true)));
        $hidden = array_values(array_filter($this->panels(), function (string $name): bool {
            $users = json_decode((string) ($this->rows[$name]['hide_user'] ?? ''), true);

            return is_array($users) && $users !== [];
        }));

        return array_values(array_filter([
            $forSome !== [] ? sprintf(self::FOR_SOME, Notes::names($forSome)) : null,
            $hidden !== [] ? sprintf(self::HIDDEN_FROM, Notes::names($hidden)) : null,
        ]));
    }

    /**
     * Whether MirzaBot signed in to a 3x-ui panel with an API token (in `password_panel`): every one of a MirzaBot since
     * 0.1.9, and any it added with no username (as it adds them since). Before, with the admin's username and password.
     *
     * @param array<string, ?string> $row
     */
    private function onToken(array $row): bool
    {
        return $this->later || Cell::text($row['username_panel'] ?? null) === null;
    }

    /**
     * Its way in: a Rebecca panel's API key or a 3x-ui panel's API token (onToken()), both kept in `password_panel`; else
     * the admin's login MirzaBot kept.
     *
     * @param array<string, ?string> $row
     * @return array<string, string>|null
     */
    private function way(string $driver, array $row): ?array
    {
        $username = Cell::text($row['username_panel'] ?? null);
        $password = Cell::text($row['password_panel'] ?? null);

        return match (true) {
            $password !== null && ($driver === 'rebecca' || ($driver === '3x-ui' && $this->onToken($row))) => [PanelCredentials::MODE => AuthMode::Token->value, 'api_token' => $password],
            $username !== null && $password !== null => [PanelCredentials::MODE => AuthMode::Password->value, 'username' => $username, 'password' => $password],
            default => null,
        };
    }

    /** @param array<string, ?string> $row */
    private static function open(array $row): bool
    {
        $status = strtolower(trim((string) ($row['status'] ?? '')));

        return $status === '' || $status === self::OPEN;
    }

    /**
     * Whether an extra's price is set: MirzaBot keeps one for each audience (`{"f":"4000","n":…}`), older ones a number.
     */
    private static function priced(?string $value): bool
    {
        $prices = json_decode((string) $value, true);
        $prices = is_array($prices) ? $prices : [$value];

        return array_filter($prices, static fn(mixed $price): bool => is_scalar($price) && Cell::whole((string) $price) > 0) !== [];
    }
}
