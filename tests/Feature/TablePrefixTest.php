<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\DatabaseTestCase;

/**
 * The suite runs on prefixed tables (TestCase::TABLE_PREFIX), as a shop sharing its database does (DB_PREFIX): SQL that
 * names a table bare fails a test, not that shop's page.
 */
final class TablePrefixTest extends DatabaseTestCase
{
    public function testEveryTableOfTheShopIsBehindThePrefix(): void
    {
        $names = array_column($this->db()->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"), 'name');

        self::assertContains(self::TABLE_PREFIX . 'orders', $names);
        self::assertSame([], array_values(array_filter($names, static fn(string $name): bool => !str_starts_with($name, self::TABLE_PREFIX))));
    }
}
