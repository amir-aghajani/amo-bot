<?php

declare(strict_types=1);

namespace App\Modules\Imports\Records;

/**
 * A plan the other bot sold. Imported switched off unless the owner says otherwise, sold on the servers its panels map
 * to (every inbound each marks selectable), in a category made by its name when the shop has none so named. Plans come
 * after the shop's own, in the order the migrator yields them.
 */
final class ImportedPlan
{
    /** @param list<string>|null $panelKeys The panels it sells on (ImportedPanel::$key); null for every one */
    public function __construct(
        /** What the other bot's services name it by: ImportedService::$planKey. */
        public readonly string $key,
        public readonly string $name,
        public readonly ?string $category,
        /** Toman, a decimal string. */
        public readonly string $price,
        /** What agents and «همکار» groups pay (plans.agent_price); null for the price. */
        public readonly ?string $agentPrice,
        /** GB, a decimal string; '0' for unlimited. */
        public readonly string $trafficGb,
        /** Days from the first connection; 0 for no end. */
        public readonly int $durationDays,
        /** Devices at once; 0 for no limit. */
        public readonly int $ipLimit = 0,
        /** A note under its checkout (plans.checkout_note); null for none. */
        public readonly ?string $note = null,
        public readonly ?array $panelKeys = null,
        /** A plan only the other bot's agents saw: imported switched off whatever the owner says, and named in the report. */
        public readonly bool $agentsOnly = false,
    ) {}
}
