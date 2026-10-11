<?php

declare(strict_types=1);

namespace App\Modules\Updates;

/**
 * One update's state between requests (storage/updates/state.json): its step and progress, the last refusal, and what
 * its install did (paths replaced, upgrades run). The next version's updater reads it too — a crashed install is
 * finished by the code it put in place — so FORMAT changes only together with a reader of the old one.
 */
final class UpdateRun
{
    /** The shape state.json has. */
    public const FORMAT = 1;

    /**
     * @param list<string> $paths The app's top-level paths the install replaces (or replaced)
     * @param list<string> $upgraded The database upgrades the install ran, by version
     */
    public function __construct(
        public readonly string $version,
        /** The version the shop ran when it began. */
        public readonly string $from,
        public readonly UpdateStep $step,
        /** How far into its step, in percent. */
        public readonly int $progress = 0,
        /** Why its step was last refused, worded for the owner; null while nothing stands in its way. */
        public readonly ?string $error = null,
        /** The next entry of the zip its unpacking takes. */
        public readonly int $entry = 0,
        /** Its install began: the app's paths may be the release's already, and it is no longer cancelled. */
        public readonly bool $installing = false,
        public readonly array $paths = [],
        public readonly array $upgraded = [],
        public readonly ?int $finishedAt = null,
    ) {}

    /** A run's beginning: the release's files to fetch. */
    public static function begin(string $version, string $from): self
    {
        return new self($version, $from, UpdateStep::Download);
    }

    /**
     * The run for files put in place by hand whose database waits for its upgrades: an install with nothing to swap.
     */
    public static function upgrading(string $version, string $from): self
    {
        return new self($version, $from, UpdateStep::Install, installing: true);
    }

    /** The run state.json holds; null for none, or for another FORMAT. */
    public static function fromKept(mixed $kept): ?self
    {
        $step = is_array($kept) && is_string($kept['step'] ?? null) ? UpdateStep::tryFrom($kept['step']) : null;
        if ($step === null || ($kept['format'] ?? null) !== self::FORMAT || !is_string($kept['version'] ?? null) || !is_string($kept['from'] ?? null)) {
            return null;
        }

        return new self(
            $kept['version'],
            $kept['from'],
            $step,
            is_int($kept['progress'] ?? null) ? $kept['progress'] : 0,
            is_string($kept['error'] ?? null) ? $kept['error'] : null,
            is_int($kept['entry'] ?? null) ? $kept['entry'] : 0,
            ($kept['installing'] ?? false) === true,
            self::names($kept['paths'] ?? null),
            self::names($kept['upgraded'] ?? null),
            is_int($kept['finished_at'] ?? null) ? $kept['finished_at'] : null,
        );
    }

    /** @return array<string, mixed> What state.json holds of it */
    public function kept(): array
    {
        return [
            'format' => self::FORMAT,
            'version' => $this->version,
            'from' => $this->from,
            'step' => $this->step->value,
            'progress' => $this->progress,
            'error' => $this->error,
            'entry' => $this->entry,
            'installing' => $this->installing,
            'paths' => $this->paths,
            'upgraded' => $this->upgraded,
            'finished_at' => $this->finishedAt,
        ];
    }

    /** On to `$step`, from its start. */
    public function at(UpdateStep $step): self
    {
        return new self($this->version, $this->from, $step, 0, null, 0, $this->installing, $this->paths, $this->upgraded, $this->finishedAt);
    }

    /** Unpacked as far as `$entry`, `$progress` percent of it. */
    public function unpacked(int $entry, int $progress): self
    {
        return new self($this->version, $this->from, $this->step, $progress, null, $entry, $this->installing, $this->paths, $this->upgraded, $this->finishedAt);
    }

    /** Its step refused, `$error` saying why. */
    public function refused(string $error): self
    {
        return new self($this->version, $this->from, $this->step, $this->progress, $error, $this->entry, $this->installing, $this->paths, $this->upgraded, $this->finishedAt);
    }

    /**
     * Its install begun on `$paths` — or, with `$installing` false, taken back.
     *
     * @param list<string> $paths
     */
    public function installing(bool $installing, array $paths): self
    {
        return new self($this->version, $this->from, $this->step, $this->progress, $this->error, $this->entry, $installing, $paths, $this->upgraded, $this->finishedAt);
    }

    /** The database upgrade to `$version` ran. */
    public function upgraded(string $version): self
    {
        return new self($this->version, $this->from, $this->step, $this->progress, $this->error, $this->entry, $this->installing, $this->paths, [...$this->upgraded, $version], $this->finishedAt);
    }

    /** Over: installed (Done) or taken back (RolledBack), now. */
    public function over(UpdateStep $step): self
    {
        return new self($this->version, $this->from, $step, 100, null, $this->entry, false, $this->paths, $this->upgraded, now()->getTimestamp());
    }

    /** Whether it may still be cancelled: under way, and nothing replaced yet. */
    public function isCancellable(): bool
    {
        return $this->step->isUnderWay() && !$this->installing;
    }

    /**
     * As the update screen shows it; `$rollback` says whether it may be taken back now.
     *
     * @return array{version: string, from: string, step: string, progress: int, error: string|null, cancel: bool, rollback: bool, finished_at: string|null}
     */
    public function present(bool $rollback): array
    {
        return [
            'version' => $this->version,
            'from' => $this->from,
            'step' => $this->step->value,
            'progress' => $this->progress,
            'error' => $this->error,
            'cancel' => $this->isCancellable(),
            'rollback' => $rollback,
            'finished_at' => $this->finishedAt === null ? null : gmdate(DATE_ATOM, $this->finishedAt),
        ];
    }

    /** @return list<string> The names a kept list holds */
    private static function names(mixed $list): array
    {
        return is_array($list) ? array_values(array_filter($list, is_string(...))) : [];
    }
}
