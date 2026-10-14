<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\Form;
use App\Modules\Settings\DeclaresSettings;
use App\Modules\Settings\Services\Settings;

/**
 * «انتقال سرویس» (the `service_transfers` group, off by default): whether a customer may hand one of their services to
 * another customer of the shop by their Telegram id. Support transfers from the panels whatever this says.
 */
final class ServiceTransferSettings implements DeclaresSettings
{
    private readonly Toggle $enabled;

    public function __construct(private readonly Settings $settings)
    {
        $this->enabled = new Toggle('transfer_enabled', 'transfers.enabled', default: false);
    }

    public function groups(): array
    {
        return [new Form('service_transfers', [$this->enabled])];
    }

    /** Whether customers may transfer their services themselves. */
    public function enabled(): bool
    {
        return $this->settings->read($this->enabled);
    }
}
