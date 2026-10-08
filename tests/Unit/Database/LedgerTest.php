<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Core\Database\Ledger;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
