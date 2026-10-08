<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Support\FileCache;
use Tests\TestCase;

/**
 * What another service hands over, kept a short while by key: fetched once while fresh, afresh once its time is up; an
 * answer of none never kept; the stale ones gone as new ones are kept; a folder that cannot be written keeping nothing.
 */
final class FileCacheTest extends TestCase
{
    private int $fetches = 0;

    public function testAFileIsFetchedOnceWhileItIsFreshAndAfreshOnceItsTimeIsUp(): void
    {
        $dir = $this->scratchDir();
        $cache = new FileCache($dir);

        self::assertSame('picture', $cache->remember('telegram|AgAD1', $this->fetch('picture')));
        self::assertSame('picture', $cache->remember('telegram|AgAD1', $this->fetch('another')), 'kept: not fetched again');
        self::assertSame(1, $this->fetches);

        $kept = self::kept($dir);
        self::assertCount(1, $kept);
        touch($kept[0], time() - FileCache::KEEP_SECONDS - 1);
        self::assertSame('changed', $cache->remember('telegram|AgAD1', $this->fetch('changed')), 'its time up: fetched afresh');
        self::assertSame(2, $this->fetches);
    }

    public function testNoneIsNeverKeptAndTheStaleOnesGoAsNewOnesAreKept(): void
    {
        $dir = $this->scratchDir();
        $cache = new FileCache($dir);

        self::assertNull($cache->remember('telegram|gone', $this->fetch(null)));
        self::assertNull($cache->remember('telegram|gone', $this->fetch(null)));
        self::assertSame(2, $this->fetches, 'Telegram no longer has it: asked again, in case it has it now');
        self::assertSame([], self::kept($dir));

        $cache->remember('telegram|old', $this->fetch('old'));
        $kept = self::kept($dir);
        self::assertCount(1, $kept);
        $old = $kept[0];
        touch($old, time() - FileCache::KEEP_SECONDS - 1);
        $cache->remember('telegram|new', $this->fetch('new'));

        self::assertFileDoesNotExist($old);
        self::assertCount(1, self::kept($dir));
    }

    public function testAFolderThatCannotBeWrittenKeepsNothingAndTheAnswerStillComes(): void
    {
        $blocked = $this->scratchDir() . '/in-the-way';
        file_put_contents($blocked, '');
        $cache = new FileCache($blocked . '/files');

        self::assertSame('picture', $cache->remember('telegram|AgAD1', $this->fetch('picture')));
        self::assertSame('picture', $cache->remember('telegram|AgAD1', $this->fetch('picture')));
        self::assertSame(2, $this->fetches);
    }

    /**
     * @return list<string> The files the cache keeps now
     * @phpstan-impure
     */
    private static function kept(string $dir): array
    {
        return glob($dir . '/*') ?: [];
    }

    /** @return \Closure(): ?string What a fetch hands over, each one counted. */
    private function fetch(?string $bytes): \Closure
    {
        return function () use ($bytes): ?string {
            $this->fetches++;

            return $bytes;
        };
    }
}
