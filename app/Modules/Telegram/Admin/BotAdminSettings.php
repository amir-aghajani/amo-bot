<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin;

use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\Form;
use App\Modules\Auth\StaffGrant;
use App\Modules\Settings\DeclaresSettings;
use App\Modules\Settings\Services\Settings;

/**
 * «مدیران ربات» (the `bot_admins` group, each shop its own): whether the shop's admins — its customers whose role is
 * admin — manage it from its bot's private chat («⚙️ مدیریت», /admin), and what beyond the daily work: a switch per
 * grant, the website's own («مدیران سایت»: StaffGrant), all off by default. The daily work is on by default: the bot's
 * admins already do it in the report group. The owner's linked accounts and an agent in their own bot need none of it.
 */
final class BotAdminSettings implements DeclaresSettings
{
    private readonly Toggle $enabled;

    /**
     * @var array<string, Toggle> By grant
     */
    private readonly array $grants;

    public function __construct(private readonly Settings $settings)
    {
        $this->enabled = new Toggle('bot_admins_enabled', 'bot_admins.enabled', default: true);
        $grants = [];
        foreach (StaffGrant::cases() as $grant) {
            $grants[$grant->value] = new Toggle('bot_admins_grant_' . $grant->value, 'bot_admins.grant_' . $grant->value, default: false);
        }
        $this->grants = $grants;
    }

    public function groups(): array
    {
        return [new Form('bot_admins', [$this->enabled, ...array_values($this->grants)])];
    }

    /** Whether the shop's admins manage it from the bot. */
    public function enabled(): bool
    {
        return $this->settings->read($this->enabled);
    }

    /**
     * @return list<string> The grants the shop's admins hold in the bot (StaffGrant values, in its order).
     */
    public function grants(): array
    {
        return array_keys(array_filter($this->grants, fn(Toggle $toggle): bool => $this->settings->read($toggle)));
    }
}
