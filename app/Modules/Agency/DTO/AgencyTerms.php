<?php

declare(strict_types=1);

namespace App\Modules\Agency\DTO;

use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\Amount;
use App\Core\Forms\Fields\FieldRefused;
use App\Modules\Agency\Models\AgencyLevel;
use App\Modules\Agency\Services\AgencySettings;
use App\Support\Input;

/** An agent's terms with the shop: their level — the price per GB of their bot's traffic — and how far below zero their wallet may go. */
final class AgencyTerms
{
    public function __construct(
        public readonly AgencyLevel $level,
        public readonly string $credit,
    ) {}

    /**
     * The terms the agents page gives (approving a request, changing an agent's), Persian messages for each field — the
     * credit held to the program's default credit's own rule.
     *
     * @param array<string, mixed> $input {level_id, credit_limit}
     * @throws ValidationException
     */
    public static function fromInput(array $input): self
    {
        $errors = [];
        $levelId = Input::integer($input, 'level_id');
        $level = $levelId === null ? null : AgencyLevel::query()->find($levelId);
        if ($level === null) {
            $errors['level_id'][] = 'سطح نمایندگی را انتخاب کنید.';
        }
        try {
            $credit = (new Amount('credit_limit', 'credit_limit', '0', label: 'اعتبار', min: '0', max: (string) AgencySettings::CREDIT_MAX))->read($input, null);
        } catch (FieldRefused $e) {
            $errors['credit_limit'][] = $e->getMessage();
        }
        if ($level === null || !isset($credit)) {
            throw new ValidationException($errors);
        }

        return new self($level, $credit);
    }
}
