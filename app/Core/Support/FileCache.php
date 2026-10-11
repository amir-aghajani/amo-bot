<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * A short-lived file cache (`files.cache`, storage/cache/files) for what another service would hand over again
 * unchanged — a picture Telegram keeps — so a screen's repeated reads fetch it once per KEEP_SECONDS. Stale files go as
 * new ones are kept; an unwritable folder keeps nothing, never an error.
 */
final class FileCache
{
    /** How long a file is kept after it was fetched. */
    public const KEEP_SECONDS = 600;

    public function __construct(private readonly string $directory) {}

    /**
     * What `$key` holds while fresh, else what `$fetch` returns, kept (a null is not kept).
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
