<?php

declare(strict_types=1);

namespace Tests\Feature\Imports;

use App\Modules\Imports\Budget;
use App\Modules\Imports\Enums\ImportPhase;
use App\Modules\Imports\Imports;
use App\Modules\Imports\Models\Import;

/**
 * How a bot's end-to-end test (an HttpTestCase) moves its import on: continued through the panels' API as the page does,
 * or stepped straight, a few checks at a time.
 */
trait ImportSteps
{
    /**
     * Continue the import while it is in `$phase`, as the page does.
     *
     * @return array<string, mixed> The import as it left it
     */
    private function continueWhile(int $id, string $phase): array
    {
        for ($step = 0; $step < 50; $step++) {
            $response = $this->postJson("/api/admin/imports/{$id}/continue");
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $import = $this->decode($response)['import'];
            if ($import['phase'] !== $phase) {
                return $import;
            }
        }

        self::fail("Import #{$id} stayed {$phase} after 50 steps.");
    }

    /**
     * Step the import while it is in `$phase`, each step spent after `$checks` checks; `$after` sees it after each.
     *
     * @param (\Closure(Import): void)|null $after
     * @return int The steps it took
     */
    private function stepWhile(Import $import, ImportPhase $phase, int $checks, ?\Closure $after = null): int
    {
        $imports = $this->service(Imports::class);
        for ($steps = 1; $steps <= 1000; $steps++) {
            $imports->advance($import, Budget::checks($checks));
            if ($after !== null) {
                $after($import);
            }
            if ($import->phase !== $phase) {
                return $steps;
            }
        }

        self::fail("Import #{$import->id} stayed {$phase->value} after 1000 steps.");
    }
}
