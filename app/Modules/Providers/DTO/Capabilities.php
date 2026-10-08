<?php

declare(strict_types=1);

namespace App\Modules\Providers\DTO;

/**
 * What a driver's panel can do beyond the contract's minimum, so the shop adapts instead of assuming. A driver claims
 * each one itself: what it does not say it can, the shop does not offer.
 */
final class Capabilities
{
    public function __construct(
        /** Clients sit on inbounds the admin picks; without, a server is sold whole. */
        public readonly bool $inbounds = false,
        /** A client's credentials — and so its link — can be renewed in place («تغییر لینک»). */
        public readonly bool $linkRotation = false,
    ) {}

    /**
     * What the connector's description says of them (Core\Drivers\Descriptor::$traits).
     *
     * @return array{inbounds: bool, link_rotation: bool}
     */
    public function traits(): array
    {
        return ['inbounds' => $this->inbounds, 'link_rotation' => $this->linkRotation];
    }
}
