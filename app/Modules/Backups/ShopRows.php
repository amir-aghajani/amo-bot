<?php

declare(strict_types=1);

namespace App\Modules\Backups;

/**
 * Holds a shop's backup to its shop as it is read: each own row (BackupTables::SHOP) under the shop's `bot_id`, each child
 * row (CHILDREN) under a parent row read before it. A row that is not the shop's is a stray: the file is refused whole.
 * A backup of the whole installation has none.
 */
final class ShopRows
{
    /** @var array<string, array<int|string, true>> The ids read so far of each table a child names */
    private array $parents = [];

    private bool $strays = false;

    public function __construct(private readonly BackupScope $scope) {}

    /**
     * @param list<string> $columns
     * @param list<mixed> $row
     */
    public function row(string $table, array $columns, array $row): void
    {
        if ($this->scope->shop === null) {
            return;
        }

        $value = static function (string $column) use ($columns, $row): mixed {
            $at = array_search($column, $columns, true);

            return $at === false ? null : ($row[$at] ?? null);
        };
        if (in_array($table, BackupTables::SHOP, true)) {
            $this->strays = $this->strays || (string) $value('bot_id') !== (string) $this->scope->shop;
        } elseif (isset(BackupTables::CHILDREN[$table])) {
            [$column, $parent] = BackupTables::CHILDREN[$table];
            $this->strays = $this->strays || !isset($this->parents[$parent][(string) $value($column)]);
        } else {
            $this->strays = true;
        }

        if (self::isParent($table)) {
            $this->parents[$table][(string) $value('id')] = true;
        }
    }

    public function strays(): bool
    {
        return $this->strays;
    }

    private static function isParent(string $table): bool
    {
        foreach (BackupTables::CHILDREN as [, $parent]) {
            if ($parent === $table) {
                return true;
            }
        }

        return false;
    }
}
