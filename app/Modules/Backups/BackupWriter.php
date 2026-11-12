<?php

declare(strict_types=1);

namespace App\Modules\Backups;

use App\Core\Application;
use App\Core\Database\Schema;
use App\Core\Security\Encrypter;
use App\Modules\Auth\Services\Reviewers;
use App\Modules\Bots\Models\Bot;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/**
 * Writes a backup file: gzip of one JSON document a line — the header (what the file is of, by which version, when),
 * then each table as a line naming it and its columns followed by its rows as arrays, then the rows each table held,
 * then the signature: an HMAC over every line before it, keyed from APP_KEY (Encrypter::macKey()). Only this
 * installation's key signs a file its restore takes, so a file nobody changed is the one thing restored — an agent
 * cannot write themselves rows of another shop, or of the owner's, into one. Rows are read a batch at a time and
 * written as they come: a large shop never sits in memory whole. A shop's file holds nothing only the owner reads
 * (BackupTables::OWNER_ONLY_COLUMNS, REVIEWER_COLUMNS): its agent downloads it.
 */
final class BackupWriter
{
    public const FORMAT = 'amobot-backup';
    public const FORMAT_VERSION = 1;

    /** The signature key's purpose (Encrypter::macKey()). */
    public const PURPOSE = 'amobot-backup';

    private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /** Rows read at once. */
    private const BATCH = 1000;

    public function __construct(
        private readonly Connection $db,
        private readonly Schema $schema,
        private readonly Encrypter $encrypter,
        private readonly Reviewers $reviewers,
    ) {}

    /**
     * Write the backup of `$scope` to `$path`: made whole beside it, checked, then put in place — a write that failed (a
     * full disk) leaves nothing behind.
     *
     * @return array<string, int> The rows each table held
     * @throws \RuntimeException when the file cannot be written
     */
    public function write(BackupScope $scope, string $path): array
    {
        $partial = $path . '.part';
        $file = gzopen($partial, 'wb6');
        if ($file === false) {
            throw new \RuntimeException("The backup cannot be written to {$partial}.");
        }
        $signature = hash_init('sha256', HASH_HMAC, $this->encrypter->macKey(self::PURPOSE));
        // What went in, for the check of what zlib wrote (its trailer): the bytes' CRC-32 and their count.
        $crc = hash_init('crc32b');
        $size = 0;
        $put = static function (string $text) use ($file, $crc, &$size): void {
            if (gzwrite($file, $text) !== strlen($text)) {
                throw new \RuntimeException('The backup could not be written whole.');
            }
            hash_update($crc, $text);
            $size += strlen($text);
        };
        $line = static function (mixed $document) use ($put, $signature): void {
            $text = json_encode($document, self::JSON) . "\n";
            hash_update($signature, $text);
            $put($text);
        };

        try {
            $line($this->header($scope));
            $rows = [];
            foreach (BackupTables::of($scope, $this->schema->tables()) as $table) {
                $columns = $this->db->getSchemaBuilder()->getColumnListing($table);
                $line(['table' => $table, 'columns' => $columns]);
                $rows[$table] = 0;
                $shown = $this->shown($table, $scope);
                foreach ($this->rows($table, $scope, $columns) as $row) {
                    $values = $shown((array) $row);
                    $line(array_map(static fn(string $column): mixed => $values[$column] ?? null, $columns));
                    $rows[$table]++;
                }
            }
            $line(['end' => ['rows' => $rows]]);
            $put(json_encode(['signature' => hash_final($signature)], self::JSON) . "\n");
        } catch (\Throwable $e) {
            gzclose($file);
            @unlink($partial);

            throw $e;
        }

        if (!gzclose($file) || !self::whole($partial, (int) hexdec(hash_final($crc)), $size)) {
            @unlink($partial);

            throw new \RuntimeException("The backup written to {$partial} is not whole.");
        }
        if (!@rename($partial, $path)) {
            @unlink($partial);

            throw new \RuntimeException("The backup cannot be put at {$path}.");
        }

        return $rows;
    }

    /**
     * Whether the gzip file ends as zlib ends one it finished: its trailer's CRC-32 and size those of what went in — a
     * cheap check of the whole, short of reading it back.
     */
    private static function whole(string $path, int $crc, int $size): bool
    {
        $file = @fopen($path, 'rb');
        if ($file === false) {
            return false;
        }
        try {
            $head = fread($file, 2);
            $tail = fseek($file, -8, SEEK_END) === 0 ? fread($file, 8) : false;
        } finally {
            fclose($file);
        }
        if ($head !== "\x1f\x8b" || !is_string($tail) || strlen($tail) !== 8) {
            return false;
        }
        $trailer = unpack('Vcrc/Vsize', $tail);

        return $trailer !== false && $trailer['crc'] === $crc && $trailer['size'] === $size % 2 ** 32;
    }

    /** @return array<string, mixed> */
    private function header(BackupScope $scope): array
    {
        $shop = $scope->shop === null ? null : Bot::query()->find($scope->shop);

        return [
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'app_version' => Application::VERSION,
            'scope' => $scope->kind(),
            'shop' => $scope->shop === null ? null : ['id' => $scope->shop, 'username' => $shop?->username, 'title' => $shop?->title],
            'made_at' => now()->toIso8601String(),
        ];
    }

    /**
     * A row of `$table` as the file holds it: for a shop, without what only the owner reads — its own columns empty, the
     * owner's login as «پشتیبانی».
     *
     * @return \Closure(array<string, mixed>): array<string, mixed>
     */
    private function shown(string $table, BackupScope $scope): \Closure
    {
        $hidden = $scope->isShop() ? BackupTables::OWNER_ONLY_COLUMNS[$table] ?? [] : [];
        $shared = $scope->isShop() ? BackupTables::REVIEWER_COLUMNS[$table] ?? [] : [];
        if ($hidden === [] && $shared === []) {
            return static fn(array $values): array => $values;
        }

        return function (array $values) use ($hidden, $shared): array {
            foreach ($hidden as $column) {
                $values[$column] = null;
            }
            foreach ($shared as $column) {
                $values[$column] = $this->reviewers->shared(is_string($values[$column] ?? null) ? $values[$column] : null);
            }

            return $values;
        };
    }

    /**
     * A table's rows a backup of `$scope` holds — a batch at a time, by id where it has one, else by its primary key's
     * columns: never a whole table at once, which MySQL's client would hold in memory.
     *
     * @param list<string> $columns
     * @return iterable<object>
     */
    private function rows(string $table, BackupScope $scope, array $columns): iterable
    {
        $query = $this->query($table, $scope);
        if (in_array('id', $columns, true)) {
            return $query->lazyById(self::BATCH, 'id');
        }
        $keys = $this->primaryKey($table);

        return $keys === [] ? $query->cursor() : $this->byKeys($query, $keys);
    }

    /**
     * The rows of `$query` in its key's order, a batch at a time: each batch those after the last one read.
     *
     * @param list<string> $keys
     * @return \Generator<int, object>
     */
    private function byKeys(Builder $query, array $keys): \Generator
    {
        $after = null;
        do {
            $next = clone $query;
            if ($after !== null) {
                // (k1 > v1) or (k1 = v1 and k2 > v2) or …: what comes after the last row read, in the key's order.
                $next->where(static function (Builder $later) use ($keys, $after): void {
                    foreach ($keys as $i => $key) {
                        $later->orWhere(static function (Builder $one) use ($keys, $after, $i, $key): void {
                            for ($j = 0; $j < $i; $j++) {
                                $one->where($keys[$j], $after[$j]);
                            }
                            $one->where($key, '>', $after[$i]);
                        });
                    }
                });
            }
            foreach ($keys as $key) {
                $next->orderBy($key);
            }
            $batch = $next->limit(self::BATCH)->get();
            foreach ($batch as $row) {
                yield $row;
            }
            $last = $batch->last();
            $after = $last === null ? null : array_map(static fn(string $key): mixed => $last->{$key}, $keys);
        } while ($batch->count() === self::BATCH);
    }

    /** @return list<string> The columns of the table's primary key; none when it has none */
    private function primaryKey(string $table): array
    {
        foreach ($this->db->getSchemaBuilder()->getIndexes($table) as $index) {
            if ($index['primary']) {
                return array_values($index['columns']);
            }
        }

        return [];
    }

    /** A table's rows of `$scope`: all of them for the installation; a shop's by its `bot_id`, or its parent's (CHILDREN). */
    private function query(string $table, BackupScope $scope): Builder
    {
        $query = $this->db->table($table);
        if ($scope->shop === null) {
            return $query;
        }
        if (in_array($table, BackupTables::SHOP, true)) {
            return $query->where('bot_id', $scope->shop);
        }
        [$column, $parent] = BackupTables::CHILDREN[$table] ?? throw new \LogicException("{$table} is no table of a shop's.");

        return $query->whereIn($column, $this->query($parent, $scope)->select('id'));
    }
}
