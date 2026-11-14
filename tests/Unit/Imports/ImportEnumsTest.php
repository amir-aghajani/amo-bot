<?php

declare(strict_types=1);

namespace Tests\Unit\Imports;

use App\Modules\Imports\Enums\ImportPhase;
use App\Modules\Imports\Enums\ImportStage;
use App\Modules\Imports\Enums\ProblemReason;
use PHPUnit\Framework\TestCase;
use Tests\HttpTestCase;

/**
 * The import's enums as resources/api/openapi.yaml describes them to the panels: the same values, in the same order — a
 * reason, a stage or a phase added on one side alone would reach the panels' types as an answer they cannot read.
 */
final class ImportEnumsTest extends TestCase
{
    public function testTheDescriptionNamesEveryPhaseStageAndReasonOfTheCode(): void
    {
        foreach (['ImportPhase' => ImportPhase::cases(), 'ImportStage' => ImportStage::cases(), 'ImportProblemReason' => ProblemReason::cases()] as $schema => $cases) {
            $described = HttpTestCase::apiDescription()->components->schemas[$schema]->enum ?? null;

            self::assertSame(array_map(static fn(\BackedEnum $case): string => (string) $case->value, $cases), $described, $schema);
        }
    }
}
