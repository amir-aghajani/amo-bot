<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

/**
 * The code writes every direction mark it puts in a text as an escape («\u{200E}»), never the character itself: typed
 * in, a mark is invisible in an editor and in a review, and one an edit loses or adds goes unseen. The zero-width
 * non-joiner (نیم‌فاصله) is a part of Persian words, not a mark, and stays as typed.
 */
final class DirectionMarksTest extends TestCase
{
    /** LRM, RLM, the Arabic letter mark, the embeddings and overrides, and the isolates. */
    private const MARKS = '/[\x{200E}\x{200F}\x{061C}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    public function testNoMarkIsTypedIntoTheCode(): void
    {
        $base = dirname(__DIR__, 3);
        $files = 0;
        $typed = [];
        foreach (['app', 'database'] as $folder) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$base}/{$folder}", \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                    $files++;
                    if (preg_match(self::MARKS, (string) file_get_contents($file->getPathname())) === 1) {
                        $typed[] = substr($file->getPathname(), strlen($base) + 1);
                    }
                }
            }
        }

        self::assertGreaterThan(100, $files, 'the code was read');
        self::assertSame([], $typed, 'write the mark as "\u{200E}" (U+200E), "\u{200F}" (U+200F)…');
    }
}
