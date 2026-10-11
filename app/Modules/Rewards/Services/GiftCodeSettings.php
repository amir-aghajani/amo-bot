<?php

declare(strict_types=1);

namespace App\Modules\Rewards\Services;

use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\Form;
use App\Modules\Settings\DeclaresSettings;
use App\Modules\Settings\Services\Settings;

/** Whether customers may redeem gift codes, in the bot and on the website (the `gift_codes` group, off by default). */
final class GiftCodeSettings implements DeclaresSettings
{
    private readonly Toggle $enabled;

    public function __construct(private readonly Settings $settings)
    {
        $this->enabled = new Toggle('gift_codes_enabled', 'gift_codes.enabled', default: false);
    }

    public function groups(): array
    {
        return [new Form('gift_codes', [$this->enabled])];
    }

    public function enabled(): bool
    {
        return $this->settings->read($this->enabled);
    }
}
