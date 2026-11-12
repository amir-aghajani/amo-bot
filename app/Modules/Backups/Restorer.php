<?php

declare(strict_types=1);

namespace App\Modules\Backups;

use App\Core\Application;
use App\Core\Database\Schema;
use App\Core\Database\Upgrades;
use App\Modules\Auth\Services\Reviewers;
use App\Modules\Backups\Exceptions\BackupRefusedException;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/**
 * Puts a checked backup file (BackupReader::read()) back, in one transaction — all of it or nothing:
 * - the whole installation: every table it holds emptied and filled from the file; the sign-ins, the chats half-way and
 *   the queued reports emptied (BackupTables::TRANSIENT): every customer signs in again (the panels stay signed in);
 * - one shop: its own rows deleted and filled from the file, its sign-ins, chats half-way and queued reports emptied —
 *   its customers sign in again —, and nothing else touched — never the owner's (the shop's terms, traffic and
 *   servers; a plan the owner keeps from sale stays kept: OWNERS_COLUMNS; what only the owner reads, which the file
 *   left out, stays as it is: OWNER_ONLY_COLUMNS, REVIEWER_COLUMNS) nor another shop's. A row naming a server that is
 *   gone now goes as its key would have it (SHOP_REFERENCES); an owner's row naming a shop's row keeps it when the
 *   file brings it back, and names none when it does not (OWNERS_REFERENCES). All of it in statements over sets of
 *   rows, never one a row: a shop with many orders restores as a small one does.
 * Columns a later version dropped are left out, columns it added take their default; a file of an older version (no
 * older than BackupTables::OLDEST_RESTORABLE) then goes through the upgrades since (each safe to repeat), as a
 * database of that version would — after the restore's commit, not in it: an upgrade's own changes to a table commit
 * on MySQL as they run, so one that fails leaves the rows restored, and restoring the file again runs it again.
 */
final class Restorer
{
    /** The values one insert carries at most: under any driver's limit (SQLite's oldest, 999). */
    private const INSERT_VALUES = 900;

    /** @var array<string, list<string>> */
    private array $columns = [];

    public function __construct(
        private readonly Connection $db,
        private readonly Schema $schema,
        private readonly BackupReader $reader,
        private readonly Application $app,
        private readonly Reviewers $reviewers,
    ) {}

    /**
     * What restoring the file would leave out or change for what is gone since it was made — a shop's — and whether it
     * can be restored at all.
     *
     * @throws \App\Core\Exceptions\ValidationException when it cannot: services on servers that are gone
     */
    public function analyse(string $path, BackupContents $contents): BackupAnalysis
    {
        if (!$contents->scope->isShop()) {
            return new BackupAnalysis([], []);
        }

        $named = [];
        foreach ($this->reader->rows($path) as [$table, $columns, $row]) {
            foreach (BackupTables::SHOP_REFERENCES as $i => [$referring, $column]) {
                if ($referring === $table && ($value = self::value($columns, $row, $column)) !== null) {
                    $named[$i][(string) $value] = ($named[$i][(string) $value] ?? 0) + 1;
                }
            }
        }

        $missing = [];
        $counts = [];
        foreach (BackupTables::SHOP_REFERENCES as $i => [$table, , $owner, $rule]) {
            $ids = array_keys($named[$i] ?? []);
            $existing = $ids === [] ? [] : array_map('strval', $this->db->table($owner)->whereIn('id', $ids)->pluck('id')->all());
            $gone = array_values(array_diff(array_map('strval', $ids), $existing));
            if ($gone === []) {
                continue;
            }
            $missing[$i] = $gone;
            $rows = array_sum(array_map(static fn(string $id): int => $named[$i][$id], $gone));
            if ($rule === 'refuse') {
                throw BackupRefusedException::because(sprintf(BackupRefusedException::GONE_SERVERS, $rows, implode('، ', array_map(static fn(string $id): string => '#' . $id, $gone))));
            }
            $counts[$rule][$table] = ($counts[$rule][$table] ?? 0) + $rows;
        }

        return new BackupAnalysis($missing, $counts);
    }

    /**
     * Restore the file, all of it or nothing.
     *
     * @return array<string, int> The rows put back, by table
     */
    public function restore(string $path, BackupContents $contents, BackupAnalysis $analysis): array
    {
        $scope = $contents->scope;
        $tables = BackupTables::of($scope, $this->schema->tables());
        $deleteActs = ForeignKeys::deleteActs($this->db);

        $restored = ForeignKeys::without($this->db, fn(): array => $this->db->transaction(function () use ($path, $scope, $tables, $analysis, $deleteActs): array {
            $owners = $scope->isShop() ? $this->ownersRows($scope, $deleteActs) : null;
            $epochs = $scope->isShop() ? [] : $this->db->table('bots')->pluck('panel_epoch', 'id')->all();
            foreach (array_diff(BackupTables::TRANSIENT, BackupTables::KEPT_ON_RESTORE) as $table) {
                $this->db->table($table)->when($scope->shop !== null, static fn(Builder $query) => $query->where('bot_id', $scope->shop))->delete();
            }
            foreach (array_reverse($tables) as $table) {
                $this->rowsOf($table, $scope)->delete();
            }

            $restored = $this->insertAll($path, $tables, $analysis);
            if ($owners !== null) {
                $this->putBack($owners);
            } else {
                $this->keepSignOuts($epochs);
            }

            return $restored;
        }));

        // Not part of the restore's transaction: the rows stand whether or not the upgrades go through (see above).
        if (version_compare($contents->appVersion, Application::VERSION, '<')) {
            (new Upgrades($this->db))->run($this->app->basePath('database/upgrades'), $contents->appVersion, Application::VERSION, static fn(string $version) => null);
        }

        return $restored;
    }

    /**
     * The whole installation's restore brings no agent's panel session back: each bot keeps the higher of its epoch now and
     * the file's — a sign-out since the backup («خروج از مرورگرهای دیگر», the agency taken away) stands —, and no one-time
     * login link the file held opens a panel.
     *
     * @param array<int|string, mixed> $epochs The bots' `panel_epoch` before the restore, by id
     */
    private function keepSignOuts(array $epochs): void
    {
        foreach ($epochs as $id => $epoch) {
            $this->db->table('bots')->where('id', $id)->where('panel_epoch', '<', (int) $epoch)->update(['panel_epoch' => (int) $epoch]);
        }
        $this->db->table('bots')->whereNotNull('login_code')->update(['login_code' => null, 'login_code_expires_at' => null]);
    }

    /**
     * Every row of the file into its table, a batch at a time; a shop's row naming what is gone, as its key would have it.
     *
     * @param list<string> $tables
     * @return array<string, int>
     */
    private function insertAll(string $path, array $tables, BackupAnalysis $analysis): array
    {
        $restored = array_fill_keys($tables, 0);
        /** @var list<list<mixed>> $batch */
        $batch = [];
        $batchTable = '';
        $batchColumns = [];
        /** @var array<string, array<string, true>> $dropped Ids of rows left out, by table: a child of one goes too */
        $dropped = [];

        foreach ($this->reader->rows($path) as [$table, $columns, $row]) {
            if (!in_array($table, $tables, true)) {
                continue;
            }
            if ($table !== $batchTable) {
                $this->flush($batchTable, $batchColumns, $batch, $restored);
                [$batchTable, $batchColumns] = [$table, $columns];
            }

            [$parentColumn, $parent] = BackupTables::CHILDREN[$table] ?? [null, null];
            if ($parent !== null && isset($dropped[$parent][(string) self::value($columns, $row, $parentColumn)])) {
                $dropped[$table][(string) self::value($columns, $row, 'id')] = true;

                continue;
            }
            foreach (BackupTables::SHOP_REFERENCES as $i => [$referring, $column, , $rule]) {
                if ($referring !== $table || !isset($analysis->missing[$i])) {
                    continue;
                }
                $at = array_search($column, $columns, true);
                if ($at === false || !in_array((string) $row[$at], $analysis->missing[$i], true)) {
                    continue;
                }
                if ($rule === 'drop') {
                    $dropped[$table][(string) self::value($columns, $row, 'id')] = true;

                    continue 2;
                }
                $row[$at] = null;
            }

            $batch[] = $row;
            if (count($batch) * max(1, count($columns)) >= self::INSERT_VALUES) {
                $this->flush($batchTable, $batchColumns, $batch, $restored);
            }
        }
        $this->flush($batchTable, $batchColumns, $batch, $restored);

        return $restored;
    }

    /**
     * The rows gathered of a table into it, counted, and the batch emptied.
     *
     * @param list<string> $columns
     * @param list<list<mixed>> $batch
     * @param array<string, int> $restored
     */
    private function flush(string $table, array $columns, array &$batch, array &$restored): void
    {
        if ($batch !== [] && $table !== '') {
            $this->insert($table, $columns, $batch);
            $restored[$table] = ($restored[$table] ?? 0) + count($batch);
        }
        $batch = [];
    }

    /**
     * Rows of a table in `$columns`' order, as many per insert as the drivers take — only the columns the table has now.
     *
     * @param list<string> $columns
     * @param list<list<mixed>> $rows
     */
    private function insert(string $table, array $columns, array $rows): void
    {
        $current = $this->columns[$table] ??= $this->db->getSchemaBuilder()->getColumnListing($table);
        $kept = array_values(array_intersect($columns, $current));
        $at = array_map(static fn(string $column): int => (int) array_search($column, $columns, true), $kept);
        $assoc = array_map(static fn(array $row): array => array_combine($kept, array_map(static fn(int $i): mixed => $row[$i] ?? null, $at)), $rows);

        foreach (array_chunk($assoc, max(1, intdiv(self::INSERT_VALUES, max(1, count($kept))))) as $chunk) {
            $this->db->table($table)->insert($chunk);
        }
    }

    /**
     * What of the owner's a shop's restore keeps, read before the shop's rows go: its columns of the shop's rows
     * (OWNERS_COLUMNS) and what only it reads there (OWNER_ONLY_COLUMNS, REVIEWER_COLUMNS), each value with the rows
     * holding it (kept()); and for its rows naming the shop's (OWNERS_REFERENCES), the least and the most id the shop had
     * — with each row's pair too where a delete applies its key's rule (`$deleteActs`, ForeignKeys::deleteActs()).
     *
     * @return array{columns: list<array{string, string, mixed, list<int|string>, bool, mixed}>, references: list<array{string, string, string, mixed, mixed, array<int|string, mixed>}>}
     */
    private function ownersRows(BackupScope $scope, bool $deleteActs): array
    {
        $columns = [];
        foreach (BackupTables::OWNERS_COLUMNS as $table => $names) {
            foreach ($names as $column) {
                array_push($columns, ...$this->kept($table, $column, $scope, static fn(mixed $value): bool => true, false));
            }
        }
        foreach (BackupTables::OWNER_ONLY_COLUMNS as $table => $names) {
            foreach ($names as $column) {
                array_push($columns, ...$this->kept($table, $column, $scope, static fn(mixed $value): bool => true, true, null));
            }
        }
        // The owner's login alone: the file holds it as anyone else reads it.
        $owners = fn(mixed $value): bool => is_string($value) && $this->reviewers->shared($value) !== $value;
        foreach (BackupTables::REVIEWER_COLUMNS as $table => $names) {
            foreach ($names as $column) {
                array_push($columns, ...$this->kept($table, $column, $scope, $owners, true, Reviewers::SUPPORT));
            }
        }

        $references = [];
        foreach (BackupTables::OWNERS_REFERENCES as [$table, $column, $parent]) {
            $named = $deleteActs ? $this->db->table($table)->whereIn($column, $this->rowsOf($parent, $scope)->select('id'))->pluck($column, 'id')->all() : [];
            $references[] = [$table, $column, $parent, $this->rowsOf($parent, $scope)->min('id'), $this->rowsOf($parent, $scope)->max('id'), $named];
        }

        return ['columns' => $columns, 'references' => $references];
    }

    /**
     * The values of a column of the shop's rows `$keep` takes — of those that hold one, when `$masked` —, each with the
     * ids of the rows holding it, read a batch at a time: [table, column, value, ids, `$masked`, `$mask`]. A masked
     * value is put back only on a restored row holding `$mask`: what the file holds in its place.
     *
     * @param \Closure(mixed): bool $keep
     * @return list<array{string, string, mixed, list<int|string>, bool, mixed}>
     */
    private function kept(string $table, string $column, BackupScope $scope, \Closure $keep, bool $masked, mixed $mask = null): array
    {
        $values = [];
        $query = $this->rowsOf($table, $scope)->select(['id', $column]);
        foreach (($masked ? $query->whereNotNull($column) : $query)->lazyById(1000, 'id') as $row) {
            $value = $row->{$column};
            if ($keep($value)) {
                $values[serialize($value)] ??= [$value, []];
                $values[serialize($value)][1][] = $row->id;
            }
        }

        return array_values(array_map(static fn(array $kept): array => [$table, $column, $kept[0], $kept[1], $masked, $mask], $values));
    }

    /**
     * The owner's again, after the shop's rows came back: its columns as they were — what only it reads where the file
     * holds its mask —, its rows naming a shop's row the file brought back naming it still, and those naming one it did
     * not naming none: those rows alone, the ones that name a row that is not there among the ids the shop had.
     *
     * @param array{columns: list<array{string, string, mixed, list<int|string>, bool, mixed}>, references: list<array{string, string, string, mixed, mixed, array<int|string, mixed>}>} $owners
     */
    private function putBack(array $owners): void
    {
        foreach ($owners['columns'] as [$table, $column, $value, $ids, $masked, $mask]) {
            foreach (array_chunk($ids, self::INSERT_VALUES) as $chunk) {
                $this->db->table($table)->whereIn('id', $chunk)
                    ->when($masked, static fn(Builder $query) => $mask === null ? $query->whereNull($column) : $query->where($column, $mask))
                    ->update([$column => $value]);
            }
        }

        foreach ($owners['references'] as [$table, $column, $parent, $least, $most, $named]) {
            // What a delete's own rule emptied (ForeignKeys::deleteActs()), named again — by parent, a set of rows at once.
            $byParent = [];
            foreach ($named as $id => $parentId) {
                $byParent[(string) $parentId][] = $id;
            }
            foreach ($byParent as $parentId => $ids) {
                foreach (array_chunk($ids, self::INSERT_VALUES) as $chunk) {
                    $this->db->table($table)->whereIn('id', $chunk)->update([$column => $parentId]);
                }
            }
            if ($least !== null && $most !== null) {
                $this->db->table($table)
                    ->whereBetween($column, [$least, $most])
                    ->whereNotIn($column, $this->db->table($parent)->select('id'))
                    ->update([$column => null]);
            }
        }
    }

    /** A table's rows of `$scope`: all of them for the installation; a shop's by its `bot_id`, or its parent's. */
    private function rowsOf(string $table, BackupScope $scope): Builder
    {
        $query = $this->db->table($table);
        if ($scope->shop === null) {
            return $query;
        }
        if (in_array($table, BackupTables::SHOP, true)) {
            return $query->where('bot_id', $scope->shop);
        }
        [$column, $parent] = BackupTables::CHILDREN[$table] ?? throw new \LogicException("{$table} is no table of a shop's.");

        return $query->whereIn($column, $this->rowsOf($parent, $scope)->select('id'));
    }

    /**
     * @param list<string> $columns
     * @param list<mixed> $row
     */
    private static function value(array $columns, array $row, ?string $column): mixed
    {
        $at = $column === null ? false : array_search($column, $columns, true);

        return $at === false ? null : ($row[$at] ?? null);
    }
}
