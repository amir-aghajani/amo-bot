<?php

declare(strict_types=1);

namespace Tests\Unit\Accounts;

use App\Modules\Accounts\Services\AccountMerger;
use Illuminate\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * A merge carries everything a customer owns to the account that stays: every column of database/schema.php that points
 * at a customer — a foreign key on `users`, and `bots.user_id` (an agent's own bot, which has none) — is one the merger
 * says what to do with (AccountMerger::REFERENCES), and it names none the schema does not have. A table that adds such a
 * column fails here until the merger handles it, so no merge leaves a row behind on an account that is gone.
 */
final class AccountMergerReferencesTest extends TestCase
{
    public function testTheMergerHandlesEveryColumnThatPointsAtACustomer(): void
    {
        self::assertSame(self::referencesInTheSchema(), self::handled());
    }

    public function testEveryReferenceSaysHowItIsCarriedOver(): void
    {
        foreach (AccountMerger::REFERENCES as $reference => $rule) {
            self::assertContains($rule['how'], ['move', 'union', 'drop', 'ledger', 'referrer'], $reference);
            self::assertSame($rule['how'] === 'union', isset($rule['key']), "{$reference}: a union names the key it is unique by");
            self::assertSame($rule['how'] !== 'drop', isset($rule['as']), "{$reference}: what moves is counted on the merge's record");
        }
    }

    /** @return list<string> Every `table.column` of the shop's tables that points at a customer, in order. */
    private static function referencesInTheSchema(): array
    {
        $db = (new ConnectionFactory(new Container()))->make(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->useDefaultSchemaGrammar();

        $references = ['bots.user_id'];
        foreach ((require dirname(__DIR__, 3) . '/database/schema.php')['tables'] as $table => $define) {
            foreach ((new Blueprint($db, $table, $define))->getCommands() as $command) {
                if ($command->get('name') === 'foreign' && $command->get('on') === 'users') {
                    foreach ((array) $command->get('columns') as $column) {
                        $references[] = "{$table}.{$column}";
                    }
                }
            }
        }
        sort($references);

        return $references;
    }

    /** @return list<string> */
    private static function handled(): array
    {
        $handled = array_keys(AccountMerger::REFERENCES);
        sort($handled);

        return $handled;
    }
}
