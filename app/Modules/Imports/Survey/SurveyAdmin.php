<?php

declare(strict_types=1);

namespace App\Modules\Imports\Survey;

/** An admin of the other bot, whom the owner may make an admin of this shop too (ImportedCustomer::$admin). */
final class SurveyAdmin
{
    public function __construct(
        /** Their ImportedCustomer::$key. */
        public readonly string $key,
        public readonly int $telegramId,
        public readonly ?string $username,
        /** Their role in the other bot, in its words ("administrator", "support"); null for none. */
        public readonly ?string $role,
    ) {}
}
