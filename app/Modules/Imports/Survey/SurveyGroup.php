<?php

declare(strict_types=1);

namespace App\Modules\Imports\Survey;

/**
 * A customer group the other bot's customers would join (ImportedCustomer::$group) — its agents, say: found in the shop
 * by its name, or made, paying plans' agent prices («همکار») when `$agentPricing`.
 */
final class SurveyGroup
{
    public function __construct(
        public readonly string $name,
        public readonly bool $agentPricing,
        public readonly int $customers,
    ) {}
}
