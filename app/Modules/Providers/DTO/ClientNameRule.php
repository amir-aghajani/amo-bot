<?php

declare(strict_types=1);

namespace App\Modules\Providers\DTO;

use App\Modules\Providers\Enums\NameProblem;

/**
 * What a panel takes as a client's name (Capabilities::$names): its length, its characters, and whether two separators
 * (`_ - . @`) may stand side by side. The shop makes every name of its own fit() the rule of the panel it goes to
 * (Subscriptions\Services\ClientNaming), so a panel never refuses one; a name a buyer typed, or one a client already
 * carries elsewhere (moved, claimed by its link), is asked whether it is allowed() as it is.
 */
final readonly class ClientNameRule
{
    /** A separator followed by more. */
    private const RUN = '/([_.@-])[_.@-]+/';

    public function __construct(
        public int $min = 1,
        /** null = the panel sets none. */
        public ?int $max = null,
        /** The characters it takes, as the inside of a regex character class ("A-Za-z0-9_.@-"); null = any. */
        public ?string $characters = null,
        /** Whether two separators may stand side by side ("ali__1"). */
        public bool $separatorRuns = true,
    ) {}

    /** Why the panel would refuse `$name`; null when it takes it as it is. */
    public function problem(string $name): ?NameProblem
    {
        $length = mb_strlen($name);

        return match (true) {
            $length < $this->min || ($this->max !== null && $length > $this->max) => NameProblem::Length,
            $this->characters !== null && preg_match('/^[' . $this->characters . ']*$/u', $name) !== 1 => NameProblem::Characters,
            !$this->separatorRuns && preg_match(self::RUN, $name) === 1 => NameProblem::Runs,
            default => null,
        };
    }

    /** Whether the panel takes `$name` as it is. */
    public function allows(string $name): bool
    {
        return $this->problem($name) === null;
    }

    /**
     * `$name` as the panel takes it: the characters it does not take dropped, each run of separators cut to its first,
     * cut to `$max`, and too short padded with leading zeros ("7" → "007").
     */
    public function fit(string $name): string
    {
        if ($this->characters !== null) {
            $name = (string) preg_replace('/[^' . $this->characters . ']/u', '', $name);
        }
        if (!$this->separatorRuns) {
            $name = (string) preg_replace(self::RUN, '$1', $name);
        }
        if ($this->max !== null) {
            $name = mb_substr($name, 0, $this->max);
        }

        return str_repeat('0', max(0, $this->min - mb_strlen($name))) . $name;
    }
}
