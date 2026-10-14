<?php

declare(strict_types=1);

namespace App\Modules\Agency\DTO;

use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\FieldRefused;
use App\Modules\Agency\Models\AgencyLevel;
use App\Modules\Agency\Services\AgencySettings;
use App\Support\Input;

/**
 * An agent's terms with the shop: their level — the price per GB of their bot's traffic —, how far below zero their
 * wallet may go and, when given, their terms of selling (an end, a monthly minimum: SellingTerms; null — unchanged, or
 * the program's on an approval).
 */
final class AgencyTerms
{
    public function __construct(
        public readonly AgencyLevel $level,
        public readonly string $credit,
        public readonly ?SellingTerms $selling = null,
    ) {}

    /**
     * The terms the agents page gives (approving a request, changing an agent's), Persian messages for each field — the
     * credit held to the program's default credit's own rule (AgencySettings::creditOf()), the terms of selling over
     * `$kept` (SellingTerms::fromInput()).
     *
     * @param array<string, mixed> $input {level_id, credit_limit, expires_at?, monthly_minimum?}
     * @throws ValidationException
     */
    public static function fromInput(array $input, ?SellingTerms $kept = null): self
    {
        $errors = [];
        $levelId = Input::integer($input, 'level_id');
        $level = $levelId === null ? null : AgencyLevel::query()->find($levelId);
        if ($level === null) {
            $errors['level_id'][] = 'سطح نمایندگی را انتخاب کنید.';
        }
        try {
            $credit = AgencySettings::creditOf($input);
        } catch (FieldRefused $e) {
            $errors['credit_limit'][] = $e->getMessage();
        }
        $selling = SellingTerms::fromInput($input, $kept ?? new SellingTerms(null, null), $errors);
        if ($level === null || !isset($credit) || $errors !== []) {
            throw new ValidationException($errors);
        }

        return new self($level, $credit, $selling);
    }
}
