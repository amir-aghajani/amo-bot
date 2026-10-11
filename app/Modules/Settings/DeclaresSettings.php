<?php

declare(strict_types=1);

namespace App\Modules\Settings;

use App\Core\Forms\Form;

/**
 * A module that declares groups on the bot settings screen («تنظیمات ربات»), registered in bootstrap/container.php. The
 * screen knows no module; each module reads its own settings through its own typed getters.
 */
interface DeclaresSettings
{
    /** @return list<Form> The groups as the current bot's screen shows them */
    public function groups(): array;
}
