<?php

declare(strict_types=1);

namespace App\Modules\Settings;

use App\Core\Forms\Form;

/**
 * A module's settings for every bot: its groups on the bot settings screen («تنظیمات ربات»), registered in
 * bootstrap/container.php (Services\BotSettingsScreen) — the screen knows no module, a module keeps its own rules and
 * reads them through its own typed getters.
 */
interface DeclaresSettings
{
    /** @return list<Form> The groups as the current bot's screen has them */
    public function groups(): array;
}
