<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\Form;
use App\Modules\Bots\CurrentBot;
use App\Modules\Settings\DeclaresSettings;
use App\Modules\Settings\Services\Settings;

/**
 * «افزودن سرویس با لینک» (the `service_claims` group, off by default): whether a customer may add a service to their
 * account by sending its subscription link or a config of it — a client support made on the panel by hand
 * (ServiceClaims). The main shop's alone — its owner's panels are where such clients are made —, so an agent's shop has
 * no such group.
 */
final class ServiceClaimSettings implements DeclaresSettings
{
    private readonly Toggle $enabled;

    public function __construct(private readonly Settings $settings)
    {
        $this->enabled = new Toggle('claim_enabled', 'claims.enabled', default: false);
    }

    public function groups(): array
    {
        return CurrentBot::isMain() ? [new Form('service_claims', [$this->enabled])] : [];
    }

    /** The switch as stored; ServiceClaims::offered() adds the main-shop rule. */
    public function enabled(): bool
    {
        return $this->settings->read($this->enabled);
    }
}
