<?php

declare(strict_types=1);

namespace App\Modules\Rewards\Services;

use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\Form;
use App\Modules\Settings\DeclaresSettings;
use App\Modules\Settings\Services\Settings;

/** Cashback's switch (the `cashback` group, off by default): off, no order earns any, not even a loyalty tier's percent. */
final class CashbackSettings implements DeclaresSettings
{
    private readonly Toggle $enabled;

    public function __construct(private readonly Settings $settings)
    {
        $this->enabled = new Toggle('cashback_enabled', 'cashback.enabled', default: false);
    }

    public function groups(): array
    {
        return [new Form('cashback', [$this->enabled])];
    }

    public function enabled(): bool
    {
        return $this->settings->read($this->enabled);
    }
}
