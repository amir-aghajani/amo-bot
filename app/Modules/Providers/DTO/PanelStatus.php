<?php

declare(strict_types=1);

namespace App\Modules\Providers\DTO;

use App\Modules\Providers\Enums\CoreState;

/**
 * Panel-agnostic host health snapshot for the servers screen. Bytes and percentages.
 */
final class PanelStatus
{
    public function __construct(
        /** Null when the panel reports no CPU load (Remnawave). */
        public readonly ?float $cpuPercent,
        public readonly int $memoryUsedBytes,
        public readonly int $memoryTotalBytes,
        /** Both 0 when the panel reports no disk (Remnawave). */
        public readonly int $diskUsedBytes,
        public readonly int $diskTotalBytes,
        public readonly CoreState $coreState,
        /** What the panel calls its core ("Xray"); null when it has no named one. */
        public readonly ?string $coreName = null,
        public readonly string $coreVersion = '',
        public readonly string $coreError = '',
        public readonly int $uptimeSeconds = 0,
        public readonly int $connections = 0,
    ) {}
}
