<?php

declare(strict_types=1);

namespace App\Modules\Imports\Survey;

/** Something the other bot has that the import does not bring (its payment history, its tickets …), and how much. */
final class LeftBehind
{
    public function __construct(
        /** In the owner's words: «تاریخچه پرداخت‌ها». */
        public readonly string $what,
        public readonly int $count,
    ) {}
}
