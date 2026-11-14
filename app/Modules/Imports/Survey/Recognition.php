<?php

declare(strict_types=1);

namespace App\Modules\Imports\Survey;

/**
 * Whether a database is the bot a migrator knows (Migrator::recognize()), which version of it when it can tell, and —
 * for one it does not take — what it lacks, which the owner is told.
 */
final class Recognition
{
    /** @param list<string> $missing The tables (or `table.column`) it looked for and did not find */
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $version,
        public readonly array $missing,
    ) {}

    /** Its bot's, of `$version` (as the owner reads it: "1.2") when it can tell. */
    public static function yes(?string $version = null): self
    {
        return new self(true, $version, []);
    }

    /** @param list<string> $missing */
    public static function no(array $missing): self
    {
        return new self(false, null, $missing);
    }
}
