<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Core\Database\Ledger;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\SQLiteConnection;
use Tests\DatabaseTestCase;
use Tests\Fakes\LedgerAccount;

/**
 * A running balance kept as lines (a wallet, an agent's traffic): each line is written from the balance the owner's
 * last line left, and a refusal writes nothing. (That two writers take turns is the locks' doing, which SQLite cannot
 * show: one writer holds the database there.)
 */
final class LedgerTest extends DatabaseTestCase
{
    private Ledger $ledger;

    private LedgerAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        LedgerAccount::makeTables($this->db());
        $this->ledger = $this->service(Ledger::class);
        $this->account = LedgerAccount::query()->create(['name' => 'ali']);
    }

    public function testEachLineIsWrittenFromTheBalanceTheLastOneLeft(): void
    {
        self::assertSame(['0', '500'], $this->append($this->account, 500), 'before the first line, nothing');
        self::assertSame(['500', '300'], $this->append($this->account, -200));
        self::assertSame([500, 300], $this->balances($this->account));
    }

    public function testAnOwnersBalanceIsItsOwnLinesAlone(): void
    {
        $other = LedgerAccount::query()->create(['name' => 'sara']);
        $this->append($this->account, 500);

        self::assertSame(['0', '70'], $this->append($other, 70), 'another owner starts from nothing');
        self::assertSame(['500', '510'], $this->append($this->account, 10));
    }

    public function testARefusalWritesNothingAndIsHeardAsItWasThrown(): void
    {
        $this->append($this->account, 500);

        $refusal = $this->thrown(fn() => $this->ledger->append($this->account, LedgerAccount::LINES, 'account_id', function (string $balance): void {
            $this->line($this->account, -900, (int) $balance - 900);

            throw new \DomainException('not enough');
        }));

        self::assertEquals(new \DomainException('not enough'), $refusal, 'heard as it was thrown');
        self::assertSame([500], $this->balances($this->account), 'the line written before the refusal went with it');
        self::assertSame(['500', '510'], $this->append($this->account, 10));
    }

    public function testAnOwnerThatIsGoneIsNotFoundAndNothingIsWritten(): void
    {
        $gone = LedgerAccount::query()->create(['name' => 'gone']);
        LedgerAccount::query()->whereKey($gone->id)->delete();

        $missing = $this->thrown(fn() => $this->ledger->append($gone, LedgerAccount::LINES, 'account_id', static fn(string $balance): never => throw new \LogicException('a line for nobody')));

        self::assertInstanceOf(ModelNotFoundException::class, $missing, 'not found, before any line is offered');
        self::assertSame([LedgerAccount::class, [$gone->id]], [$missing->getModel(), $missing->getIds()]);
    }

    public function testTwoOwnersLedgersFoldIntoOneCountedAgainLineByLine(): void
    {
        $other = LedgerAccount::query()->create(['name' => 'sara']);
        $this->append($this->account, 500);
        $this->append($other, 70);
        $this->append($this->account, -200);
        $this->append($other, -20);
        $third = LedgerAccount::query()->create(['name' => 'nima']);
        $this->append($third, 9);

        $moved = $this->ledger->merge($this->account, $other, LedgerAccount::LINES, 'account_id', static fn(string $balance, \stdClass $line): string => (string) ((int) $balance + (int) $line->amount));

        self::assertSame(2, $moved);
        self::assertSame([500, 570, 370, 350], $this->balances($this->account), 'in the order the lines were written, each from the one before');
        self::assertSame([], $this->balances($other));
        self::assertSame([9], $this->balances($third), "another owner's ledger as it was");
        self::assertSame(['350', '360'], $this->append($this->account, 10), 'the next line is written from the two balances together');
    }

    /**
     * On its own — no caller's transaction — a line the database aborted for a deadlock is written on the next try, from
     * the balance as the rollback left it; one aborted on every try is given up after ATTEMPTS, nothing written. (On a
     * connection of its own: in the test's transaction every transaction is a savepoint, which is never tried again.)
     */
    public function testOnItsOwnALineAbortedForADeadlockIsTriedAgainUpToTheAttempts(): void
    {
        [$ledger, $db, $account] = $this->ownConnection();
        $tries = 0;
        $write = static function (string $balance, int $deadlocks) use ($db, $account, &$tries): string {
            $db->table(LedgerAccount::LINES)->insert(['account_id' => $account->id, 'amount' => 10, 'balance_after' => (int) $balance + 10]);
            if (++$tries <= $deadlocks) {
                throw self::deadlock();
            }

            return $balance;
        };

        self::assertSame('0', $ledger->append($account, LedgerAccount::LINES, 'account_id', static fn(string $balance): string => $write($balance, 1)));
        self::assertSame(2, $tries, 'tried again once');
        self::assertSame(['10'], $db->table(LedgerAccount::LINES)->pluck('balance_after')->map(strval(...))->all(), "the first try's line went with it");

        $tries = 0;
        $given = $this->thrown(fn() => $ledger->append($account, LedgerAccount::LINES, 'account_id', static fn(string $balance): string => $write($balance, Ledger::ATTEMPTS)));
        self::assertInstanceOf(QueryException::class, $given);
        self::assertSame(Ledger::ATTEMPTS, $tries);
        self::assertSame(1, $db->table(LedgerAccount::LINES)->count(), 'nothing written');
    }

    /** Inside a caller's transaction the deadlock goes up to it, which — a money write's — tries the whole of it again. */
    public function testInsideACallersTransactionTheCallerTriesTheWholeAgain(): void
    {
        [$ledger, $db, $account] = $this->ownConnection();
        $outer = 0;
        $inner = 0;

        $db->transaction(static function () use ($ledger, $db, $account, &$outer, &$inner): void {
            $outer++;
            $ledger->append($account, LedgerAccount::LINES, 'account_id', static function (string $balance) use ($db, $account, &$inner): void {
                $db->table(LedgerAccount::LINES)->insert(['account_id' => $account->id, 'amount' => 10, 'balance_after' => (int) $balance + 10]);
                if (++$inner === 1) {
                    throw self::deadlock();
                }
            });
        }, Ledger::ATTEMPTS);

        self::assertSame([2, 2], [$outer, $inner], 'the line is not tried alone: the whole transaction is');
        self::assertSame(1, $db->table(LedgerAccount::LINES)->count());
    }

    /** @return array{Ledger, Connection, LedgerAccount} A ledger on an in-memory database of its own, with one owner */
    private function ownConnection(): array
    {
        $db = new SQLiteConnection(new \PDO('sqlite::memory:'));
        LedgerAccount::makeTables($db);
        $db->table('ledger_accounts')->insert(['id' => 1, 'name' => 'ali']);

        return [new Ledger($db), $db, (new LedgerAccount())->forceFill(['id' => 1])];
    }

    /** What MySQL throws at the transaction it aborts, whole, to break a deadlock. */
    private static function deadlock(): QueryException
    {
        return new QueryException('mysql', 'insert into `ledger_lines` …', [], new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
    }

    /** What `$call` threw; failing the test when it threw nothing. */
    private function thrown(\Closure $call): \Throwable
    {
        try {
            $call();
        } catch (\Throwable $e) {
            return $e;
        }

        self::fail('nothing was thrown');
    }

    /** @return array{string, string} The balance the line was written from, and the one it left */
    private function append(LedgerAccount $account, int $amount): array
    {
        return $this->ledger->append($account, LedgerAccount::LINES, 'account_id', function (string $balance) use ($account, $amount): array {
            $after = (int) $balance + $amount;
            $this->line($account, $amount, $after);

            return [$balance, (string) $after];
        });
    }

    private function line(LedgerAccount $account, int $amount, int $balanceAfter): void
    {
        $this->db()->table(LedgerAccount::LINES)->insert(['account_id' => $account->id, 'amount' => $amount, 'balance_after' => $balanceAfter]);
    }

    /** @return list<int> The owner's balances, line by line */
    private function balances(LedgerAccount $account): array
    {
        return array_values(array_map(intval(...), $this->db()->table(LedgerAccount::LINES)->where('account_id', $account->id)->orderBy('id')->pluck('balance_after')->all()));
    }
}
