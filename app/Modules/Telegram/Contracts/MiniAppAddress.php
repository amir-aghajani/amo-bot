<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Contracts;

/**
 * Where the current shop's Telegram mini app opens, as the bot's buttons need it. The Store module keeps the mini app
 * (Store\Services\MiniApps implements this), so the dependency runs one way: the bot asks, and names no Store class.
 */
interface MiniAppAddress
{
    /** The app's address while it is switched on and Telegram can open it (an https address); null otherwise. */
    public function url(): ?string;
}
