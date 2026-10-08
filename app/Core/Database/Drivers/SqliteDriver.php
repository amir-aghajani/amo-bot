<?php

declare(strict_types=1);

namespace App\Core\Database\Drivers;

use App\Core\Drivers\Descriptor;
use App\Core\Forms\FieldSpec;
use App\Core\Forms\Form;
use App\Core\Support\Files;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * SQLite: a file of the shop's own, no server — the test suite's database (in memory) and a developer's. Not offered
 * by the installer: PHP before 8.4 cannot begin the IMMEDIATE transactions that would keep the bot, the panel and the
 * cron from failing each other's writes with SQLITE_BUSY (the code's read-then-write transactions lean on row locks
 * SQLite does not have), its locking is unsafe on the network filesystems some shared hosts use, and one slow
 * transaction holds every writer. A file database still gets WAL and a busy timeout.
 */
final class SqliteDriver implements DatabaseDriver
{
    /** The test suite's database, gone with its connection. */
    public const MEMORY = ':memory:';

    /** The oldest SQLite a probe accepts: renaming a table rewrites the foreign keys pointing at it from 3.26 on. */
    private const MINIMUM = ['SQLite' => '3.26'];

    /** Milliseconds a writer waits for another's lock before it fails. */
    private const BUSY_TIMEOUT = 5000;

    private readonly SqliteFile $file;

    /** @param string $basePath The application's folder: a relative path is the file's place in it */
    public function __construct(string $basePath)
    {
        $this->file = new SqliteFile(
            'path',
            'DB_DATABASE',
            'storage/amobot.sqlite',
            $basePath,
            new FieldSpec('فایل دیتابیس', hint: 'نسبت به پوشه برنامه یا مسیر کامل، بیرون از پوشه public؛ وب‌سرور باید اجازه نوشتن در پوشه‌اش را داشته باشد.', placeholder: 'storage/amobot.sqlite'),
        );
    }

    public function key(): string
    {
        return 'sqlite';
    }

    public function describe(): Descriptor
    {
        return new Descriptor(
            key: $this->key(),
            label: 'SQLite',
            description: 'یک فایل در پوشه برنامه، بدون سرور دیتابیس؛ برای تست‌ها و توسعه. فروشگاه واقعی روی MySQL / MariaDB اجرا می‌شود.',
            form: new Form($this->key(), [$this->file]),
            notes: ['SQLite 3.26 یا بالاتر'],
            traits: [self::INSTALLABLE => false],
        );
    }

    public function extensions(): array
    {
        return ['pdo_sqlite'];
    }

    public function connection(array $config): array
    {
        $path = $this->path($config);
        $connection = ['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true];

        // Readers never wait for the writer in WAL, and a writer waits its turn instead of failing at once.
        return $path === self::MEMORY ? $connection : $connection + ['busy_timeout' => self::BUSY_TIMEOUT, 'journal_mode' => 'wal'];
    }

    public function probe(array $config): ProbeResult
    {
        $path = $this->path($config);
        if ($path !== self::MEMORY) {
            $directory = dirname($path);
            if (!is_dir($directory)) {
                throw new ProbeFailedException("پوشه {$directory} وجود ندارد.");
            }
            if (!is_writable($directory)) {
                throw new ProbeFailedException("وب‌سرور اجازه نوشتن در پوشه {$directory} را ندارد.");
            }
            // Illuminate's connector opens only a file that is there.
            if (!is_file($path)) {
                try {
                    Files::writeAtomically($path, '');
                } catch (\RuntimeException $e) {
                    throw new ProbeFailedException("فایل {$path} ساخته نشد.", previous: $e);
                }
            }
        }

        try {
            return ProbeResult::probe($this->connection($config), self::MINIMUM);
        } catch (\PDOException $e) {
            throw new ProbeFailedException('فایل دیتابیس باز نشد. (' . trim($e->getMessage()) . ')', previous: $e);
        }
    }

    public function localDate(string $column, int $offset): string
    {
        return sprintf("DATE(%s, '%+d seconds')", $column, $offset);
    }

    public function releaseNames(Builder $schema, string $table): void
    {
        // An index's name is unique across the whole database on SQLite; a foreign key has none.
        $names = array_column(array_filter($schema->getIndexes($table), static fn(array $index): bool => !$index['primary']), 'name');
        if ($names !== []) {
            $schema->table($table, static function (Blueprint $blueprint) use ($names): void {
                foreach ($names as $name) {
                    $blueprint->dropIndex($name);
                }
            });
        }
    }

    /** @param array<string, mixed> $config */
    private function path(array $config): string
    {
        $path = $this->file->cast($config[$this->file->key] ?? null);

        return $path === self::MEMORY ? $path : $this->file->absolute($path);
    }
}
