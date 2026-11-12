<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use App\Core\Database\Schema;
use App\Modules\Backups\BackupScope;
use App\Modules\Backups\BackupTables;
use App\Modules\Bots\Models\Bot;
use Illuminate\Database\Connection;
use Tests\DatabaseTestCase;

/**
 * What a backup holds is decided table by table, and every table is decided: a shop's own carries its `bot_id`, a child
 * reaches a shop's own through its parents, the owner's and what is only true now are named — so a table a later version
 * adds fails here until someone says whose it is.
 */
final class BackupTablesTest extends DatabaseTestCase
{
    /**
     * The owner's rows that name the main shop's alone, as "table.column": an agency request is filed in the main bot
     * (AgencyActions::mayRequest()), and the main shop is restored only with the whole installation (BackupScope::shop()).
     */
    private const MAIN_SHOPS_REFERENCES = ['agency_requests.user_id'];

    public function testEveryTableIsOneKindAndOneOnly(): void
    {
        $kinds = [...BackupTables::SHOP, ...array_keys(BackupTables::CHILDREN), ...BackupTables::OWNER, ...BackupTables::TRANSIENT];

        self::assertEqualsCanonicalizing($this->service(Schema::class)->tables(), $kinds, 'every table, and no other');
        self::assertSame(array_values(array_unique($kinds)), $kinds, 'each one kind');
        self::assertSame([], array_values(array_diff(BackupTables::KEPT_ON_RESTORE, BackupTables::TRANSIENT)));
    }

    public function testAShopsOwnCarryItsBotAndAChildReachesThemThroughItsParents(): void
    {
        $builder = $this->service(Connection::class)->getSchemaBuilder();

        foreach (BackupTables::SHOP as $table) {
            self::assertTrue($builder->hasColumn($table, 'bot_id'), "{$table} carries its shop");
        }
        foreach (BackupTables::CHILDREN as $table => [$column, $parent]) {
            self::assertFalse($builder->hasColumn($table, 'bot_id'), "{$table} is found by its parent");
            self::assertTrue($builder->hasColumn($table, $column), "{$table}.{$column}");
            for ($up = $parent; isset(BackupTables::CHILDREN[$up]); $up = BackupTables::CHILDREN[$up][1]);
            self::assertContains($up, BackupTables::SHOP, "{$table} reaches a shop's own");
        }
    }

    public function testWhatTheRestoreKeepsOfTheOwnersNamesColumnsThatAre(): void
    {
        $builder = $this->service(Connection::class)->getSchemaBuilder();

        foreach (BackupTables::OWNERS_COLUMNS as $table => $columns) {
            self::assertContains($table, BackupTables::SHOP);
            foreach ($columns as $column) {
                self::assertTrue($builder->hasColumn($table, $column), "{$table}.{$column}");
            }
        }
        foreach ([...array_keys(BackupTables::OWNER_ONLY_COLUMNS), ...array_keys(BackupTables::REVIEWER_COLUMNS)] as $table) {
            self::assertContains($table, [...BackupTables::SHOP, ...array_keys(BackupTables::CHILDREN)], "{$table} is a shop's");
            foreach ([...BackupTables::OWNER_ONLY_COLUMNS[$table] ?? [], ...BackupTables::REVIEWER_COLUMNS[$table] ?? []] as $column) {
                self::assertTrue($builder->hasColumn($table, $column), "{$table}.{$column}");
            }
        }
        foreach ([...BackupTables::SHOP, ...array_keys(BackupTables::CHILDREN)] as $table) {
            if ($builder->hasColumn($table, 'reviewer')) {
                self::assertContains('reviewer', BackupTables::REVIEWER_COLUMNS[$table] ?? [], "{$table}.reviewer may name the owner's login: REVIEWER_COLUMNS");
            }
        }
        foreach (BackupTables::OWNERS_REFERENCES as [$table, $column, $shops]) {
            self::assertContains($table, BackupTables::OWNER);
            self::assertContains($shops, [...BackupTables::SHOP, ...array_keys(BackupTables::CHILDREN)]);
            self::assertTrue($builder->hasColumn($table, $column), "{$table}.{$column}");
        }
        foreach (BackupTables::SHOP_REFERENCES as [$table, $column, $owners]) {
            self::assertContains($table, [...BackupTables::SHOP, ...array_keys(BackupTables::CHILDREN)]);
            self::assertContains($owners, BackupTables::OWNER);
            self::assertTrue($builder->hasColumn($table, $column), "{$table}.{$column}");
        }
    }

    /**
     * Every key between a shop's rows and the owner's is one the restore knows: an owner's row naming a shop's is kept or
     * cleared (OWNERS_REFERENCES) — or names the main shop's alone (MAIN_SHOPS_REFERENCES) —, a shop's row naming the
     * owner's — other than its own bot — goes as its rule says when that row is gone (SHOP_REFERENCES), and what is only
     * true now goes with the shop's rows it names. A key a later version adds across the line fails here until it is
     * decided. And the main shop is never restored alone: the owner's rows name its own.
     */
    public function testEveryKeyAcrossTheLineIsOneTheRestoreKnows(): void
    {
        $db = $this->service(Connection::class);
        $builder = $db->getSchemaBuilder();
        $prefix = $db->getTablePrefix();
        $shops = [...BackupTables::SHOP, ...array_keys(BackupTables::CHILDREN)];
        $owners = array_map(static fn(array $reference): string => "{$reference[0]}.{$reference[1]}", BackupTables::OWNERS_REFERENCES);
        $shopReferences = array_map(static fn(array $reference): string => "{$reference[0]}.{$reference[1]}", BackupTables::SHOP_REFERENCES);
        $owners = [...$owners, ...self::MAIN_SHOPS_REFERENCES];
        $children = array_map(static fn(array $child): string => $child[0], BackupTables::CHILDREN);

        foreach ($this->service(Schema::class)->tables() as $table) {
            foreach ($builder->getForeignKeys($table) as $key) {
                $column = "{$table}." . $key['columns'][0];
                $to = substr($key['foreign_table'], strlen($prefix));
                if (in_array($table, BackupTables::OWNER, true) && in_array($to, $shops, true)) {
                    self::assertContains($column, $owners, "{$column} names a shop's {$to}: OWNERS_REFERENCES, or MAIN_SHOPS_REFERENCES");
                }
                if (in_array($table, BackupTables::TRANSIENT, true) && in_array($to, $shops, true) && !in_array($table, BackupTables::KEPT_ON_RESTORE, true)) {
                    self::assertTrue($builder->hasColumn($table, 'bot_id'), "{$table} names a shop's {$to}: a shop's restore empties it by its bot_id");
                }
                if (in_array($table, $shops, true) && in_array($to, BackupTables::OWNER, true) && $key['columns'][0] !== 'bot_id' && !(isset(BackupTables::CHILDREN[$table]) && $children[$table] === $key['columns'][0])) {
                    self::assertContains($column, $shopReferences, "{$column} names the owner's {$to}: SHOP_REFERENCES");
                }
            }
        }

        $this->expectException(\LogicException::class);
        BackupScope::shop(Bot::MAIN);
    }
}
