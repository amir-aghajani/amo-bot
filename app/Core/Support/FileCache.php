<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * Files kept a short while, by key: what another service hands over — and would hand over again, unchanged — as a screen
 * reads it again and again (a picture Telegram keeps: a receipt, a ticket's, a premium emoji's), so it is fetched once
 * in KEEP_SECONDS, then afresh. Each is a file of its own in the folder (the container's `files.cache`, storage/cache/files),
 * named by its key's hash and its owner's alone, as every file the app writes (Files); the ones past their time go as
 * new ones are kept. A folder that cannot be written keeps nothing — never an error: the answer is fetched each time.
 */
final class FileCache
{
    /** How long a file is kept from when it was fetched: a screen's reads of it in a row, never a copy that outlives a change. */
    public const KEEP_SECONDS = 600;

    public function __construct(private readonly string $directory) {}

    /**
     * What `$key` holds while it is fresh — else what `$fetch` answers, kept for the reads that follow (an answer of none
     * is not kept: it is asked again).
     *
     * @param \Closure(): ?string $fetch
     */
    public function remember(string $key, \Closure $fetch): ?string
    {
        $file = $this->directory . '/' . hash('sha256', $key);
        if (self::fresh($file)) {
            $kept = Files::read($file);
            if ($kept !== null) {
                return $kept;
            }
        }

        $fetched = $fetch();
        if ($fetched !== null) {
            $this->keep($file, $fetched);
        }

        return $fetched;
    }

    private function keep(string $file, string $bytes): void
    {
        try {
            Files::writeAtomically($file, $bytes);
        } catch (\RuntimeException) {
            return;
        }

        foreach (glob($this->directory . '/*') ?: [] as $kept) {
            if (!self::fresh($kept)) {
                Files::delete($kept);
            }
        }
    }

    private static function fresh(string $file): bool
    {
        clearstatcache(true, $file);

        return (Files::modifiedAt($file) ?? 0) > time() - self::KEEP_SECONDS;
    }
}
