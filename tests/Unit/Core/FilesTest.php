<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Support\FileLock;
use App\Core\Support\Files;
use Tests\TestCase;

/**
 * The app's own files (config.php, the scheduler's state, the install lock, the throttle): written whole or
 * not at all, their permissions kept, changed one process after the other — and a folder that cannot be written is an
 * answer, never a PHP warning (which the suite would fail on). "Only one of us at a time" between processes is a file
 * lock the operating system holds.
 */
final class FilesTest extends TestCase
{
    public function testAFileIsWrittenWholeInPlaceOfTheOldOneAndKeepsItsPermissions(): void
    {
        $dir = $this->scratchDir();
        $path = $dir . '/schedule.json';
        Files::writeAtomically($path, 'first');
        chmod($path, 0o640);
        $mode = fileperms($path) & 0o777;

        Files::writeAtomically($path, 'second');

        self::assertSame('second', Files::read($path));
        self::assertSame($mode, fileperms($path) & 0o777, 'an owner who let their group read it keeps it so');
        self::assertSame(['schedule.json'], array_values(array_diff((array) scandir($dir), ['.', '..'])), 'nothing left beside it');
    }

    public function testWhatTheAppMakesIsItsOwnersAlone(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Windows keeps no owner, group and others apart: CI runs this.');
        }
        $dir = $this->scratchDir() . '/uploads/receipts';

        Files::writeAtomically($dir . '/1-12-receipt.png', 'a customer\'s picture');
        Files::rewrite(dirname($dir) . '/install-key.txt', static fn(): string => 'K7M2-Q9XP');

        self::assertSame(Files::FOLDER_MODE, fileperms($dir) & 0o777, 'a folder made, its owner\'s to enter');
        self::assertSame(Files::FOLDER_MODE, fileperms(dirname($dir)) & 0o777, 'its parents made too');
        self::assertSame(Files::FILE_MODE, fileperms($dir . '/1-12-receipt.png') & 0o777, 'config.php, a key, a picture: nobody else\'s on a shared host');
        self::assertSame(Files::FILE_MODE, fileperms(dirname($dir) . '/install-key.txt') & 0o777);
    }

    /**
     * Where PHP runs as another user than the account that owns the app (mod_php), the account opens the install key, the
     * recovery key and config.php in its host's file manager: what the app makes is the account's to read there.
     */
    public function testWherePhpRunsAsAnotherUserWhatTheAppMakesIsTheAccountsToRead(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Windows keeps no owner, group and others apart: CI runs this.');
        }
        $dir = $this->scratchDir() . '/uploads/receipts';

        Files::ownerOnly(false);
        try {
            Files::writeAtomically($dir . '/1-12-receipt.png', 'a customer\'s picture');
            Files::rewrite(dirname($dir) . '/install-key.txt', static fn(): string => 'K7M2-Q9XP');
        } finally {
            Files::ownerOnly(Files::processOwns($this->app()->basePath()));
        }

        self::assertSame(Files::SHARED_FOLDER_MODE, fileperms($dir) & 0o777, 'a folder made, the account\'s to enter');
        self::assertSame(Files::SHARED_FILE_MODE, fileperms($dir . '/1-12-receipt.png') & 0o777);
        self::assertSame(Files::SHARED_FILE_MODE, fileperms(dirname($dir) . '/install-key.txt') & 0o777, 'the install key, the account\'s to read');
    }

    /**
     * The modes follow who runs the app, as it boots: owner-only while the process's user owns its folder (or the host
     * keeps no users apart), the account's to read otherwise — a stub of that answer stands for each host.
     */
    public function testTheModesFollowWhetherThePhpProcessOwnsTheAppsFolder(): void
    {
        try {
            Files::ownerOnly(false);
            self::assertSame([0o644, 0o755], [Files::fileMode(), Files::folderMode()], 'PHP as another user: the account reads what it makes');
            Files::ownerOnly(true);
            self::assertSame([0o600, 0o700], [Files::fileMode(), Files::folderMode()], 'PHP as the account: its own alone');
        } finally {
            Files::ownerOnly(Files::processOwns($this->app()->basePath()));
        }

        self::assertTrue(Files::processOwns($this->scratchDir()), 'a folder this process made is its own');
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            self::assertFalse(Files::processOwns('/'), 'the system\'s root folder is not this user\'s');
        }
    }

    public function testAFolderThatCannotBeWrittenIsAnAnswerNotAWarning(): void
    {
        // A file stands where the folder would be made.
        $blocked = $this->scratchDir() . '/in-the-way';
        file_put_contents($blocked, '');

        self::assertFalse(Files::writableDirectory($blocked . '/cache'));
        try {
            Files::writeAtomically($blocked . '/cache/schedule.json', '{}');
            self::fail('a file was written where no folder can be');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString($blocked, $e->getMessage(), 'which folder to fix');
        }
        self::assertNull(Files::read($blocked . '/cache/schedule.json'));
        self::assertNull(Files::modifiedAt($blocked . '/cache/schedule.json'));

        $this->expectException(\RuntimeException::class);
        FileLock::take($blocked . '/cache/bot-poll.lock');
    }

    public function testARewriteIsMadeUnderALockNobodyElseGetsMeanwhile(): void
    {
        $path = $this->scratchDir() . '/attempts';

        self::assertSame('1', Files::rewrite($path, static fn(string $held): string => (string) ((int) $held + 1)), 'made, empty, when it was not there');
        $written = Files::rewrite($path, static function (string $held) use ($path): string {
            $other = fopen($path, 'c');
            self::assertNotFalse($other);
            self::assertFalse(flock($other, LOCK_EX | LOCK_NB), 'another writer waits its turn');
            fclose($other);

            return (string) ((int) $held + 1);
        });

        self::assertSame(['2', '2'], [$written, Files::read($path)]);
    }

    public function testAFileThatIsNotThereReadsAsNothingAndIsGoneAlready(): void
    {
        $path = $this->scratchDir() . '/install-key.txt';

        self::assertNull(Files::read($path));
        self::assertTrue(Files::delete($path), 'gone is what was asked for');

        file_put_contents($path, 'K7M2');
        self::assertEqualsWithDelta(time(), Files::modifiedAt($path), 2);
        self::assertTrue(Files::delete($path));
        self::assertFileDoesNotExist($path);
    }

    public function testALockHasOneHolderAtATime(): void
    {
        $path = $this->scratchDir() . '/cache/bot-poll.lock';

        $first = self::take($path);
        self::assertNotNull($first, 'its folder made when missing');
        self::assertNull(self::take($path), 'held: the second process steps aside');

        $first->release();
        $first->release();
        $next = self::take($path);
        self::assertNotNull($next, 'let go (safely twice): the next one takes it');
        $next->release();
    }

    public function testAWriterWaitsForTheLockAndHoldsIt(): void
    {
        $path = $this->scratchDir() . '/cache/config.lock';

        $lock = FileLock::wait($path);
        self::assertNotNull($lock, 'nobody held it: taken at once, its folder made');
        self::assertNull(self::take($path), 'held: another steps aside, or waits its turn');
        $lock->release();
        $next = self::take($path);
        self::assertNotNull($next, 'let go: the next one has it');
        $next->release();

        $blocked = $this->scratchDir() . '/in-the-way';
        file_put_contents($blocked, '');
        self::assertNull(FileLock::wait($blocked . '/cache/config.lock'), 'no file to lock: none to wait for either');
    }

    /** @phpstan-impure */
    private static function take(string $path): ?FileLock
    {
        return FileLock::take($path);
    }
}
