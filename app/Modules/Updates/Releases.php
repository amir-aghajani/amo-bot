<?php

declare(strict_types=1);

namespace App\Modules\Updates;

use App\Modules\Bots\Models\Bot;
use App\Modules\Settings\Services\Settings;
use App\Modules\Updates\Exceptions\UpdateRefusedException;

/**
 * The newest release, kept in the main bot's settings with when it was read and tried. A screen reads GitHub again at
 * most every KEEP_SECONDS — its API allows 60 calls an hour per address, and a shared host's address is every site's —
 * the owner's «بررسی دوباره» at once, and CheckReleasesTask daily. What was read stands while GitHub does not answer.
 */
final class Releases
{
    /** Where AmoBot is published. */
    public const REPOSITORY = 'amir-aghajani/amo-bot';

    /** How long a release read stands before a screen asks GitHub again. */
    public const KEEP_SECONDS = 6 * 3600;

    /** The installation's runtime state it is kept as. */
    private const KEPT = 'update.latest';

    public function __construct(
        private readonly GitHub $gitHub,
        private readonly Settings $settings,
    ) {}

    /**
     * The newest release as last read, read again first when both the last read and the last try are KEEP_SECONDS old
     * (so a GitHub that did not answer is not waited on at every screen).
     *
     * @return array{release: Release|null, checked_at: int|null}
     */
    public function latest(): array
    {
        $kept = $this->kept();
        if (max($kept['checked_at'] ?? 0, $kept['tried_at']) < now()->getTimestamp() - self::KEEP_SECONDS) {
            try {
                return $this->check();
            } catch (UpdateRefusedException) {
                // What stood stands; the try is kept (check()), so the next screen does not wait on GitHub again.
            }
        }

        return ['release' => $kept['release'], 'checked_at' => $kept['checked_at']];
    }

    /**
     * Read the newest release from GitHub now and keep it (null while none is published).
     *
     * @return array{release: Release|null, checked_at: int}
     * @throws UpdateRefusedException when GitHub does not answer (what stood stays)
     */
    public function check(): array
    {
        $kept = $this->kept();
        try {
            $answer = $this->gitHub->latest();
        } catch (UpdateRefusedException $e) {
            $this->keep($kept['release'], $kept['checked_at'], now()->getTimestamp());

            throw $e;
        }

        $release = $answer === null ? null : Release::fromApi($answer);
        $now = now()->getTimestamp();
        $this->keep($release, $now, $now);

        return ['release' => $release, 'checked_at' => $now];
    }

    /** @return array{release: Release|null, checked_at: int|null, tried_at: int} */
    private function kept(): array
    {
        $kept = $this->settings->get(self::KEPT, null, Bot::MAIN);
        $kept = is_array($kept) ? $kept : [];

        return [
            'release' => Release::fromKept($kept['release'] ?? null),
            'checked_at' => is_int($kept['checked_at'] ?? null) ? $kept['checked_at'] : null,
            'tried_at' => is_int($kept['tried_at'] ?? null) ? $kept['tried_at'] : 0,
        ];
    }

    private function keep(?Release $release, ?int $checkedAt, int $triedAt): void
    {
        $this->settings->set(self::KEPT, ['release' => $release?->kept(), 'checked_at' => $checkedAt, 'tried_at' => $triedAt], Bot::MAIN);
    }
}
