<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Core\Database\Drivers\MySqlDriver;
use App\Core\Database\Lease;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Tests\DatabaseTestCase;
use Tests\Fakes\LeasedRun;

/**
 * One worker at a time on a row (a run past a cursor): the lease is taken only when free or run out, every step is a
 * conditional write that a pause, a cancel or a takeover turns down, an undo and a release need only the token.
 */
final class LeaseTest extends DatabaseTestCase
{
    private LeasedRun $run;

    protected function setUp(): void
    {
        parent::setUp();

        LeasedRun::makeTable($this->db());
        $this->run = LeasedRun::query()->create(['status' => 'sending']);
    }

    public function testOneHolderAtATimeUntilItsTimeRunsOut(): void
    {
        $lease = $this->take();

        self::assertNotNull($lease);
        self::assertSame($lease->token, $this->fresh()->lease_token);
        self::assertSame(now()->addSeconds(60)->toDateTimeString(), $this->fresh()->leased_until?->toDateTimeString());
        self::assertSame($lease->token, $this->run->lease_token, 'the model holds what was written');
        self::assertNull($this->take($this->fresh()), 'held: nobody else takes it');

        Carbon::setTestNow(now()->addSeconds(61));
        $successor = $this->take($this->fresh());
        self::assertNotNull($successor, 'a holder that went quiet lost it');
        self::assertFalse($lease->write(['cursor' => 5]), 'the old holder learns it at its next step');
        self::assertFalse($lease->release());
        self::assertSame([0, $successor->token], [$this->fresh()->cursor, $this->fresh()->lease_token], 'nothing of the old holder\'s was written');
    }

    public function testEveryStepHoldsTheRowItsSecondsLonger(): void
    {
        $lease = $this->take();
        self::assertNotNull($lease);

        foreach ([static fn() => $lease->write(['cursor' => 1]), static fn() => $lease->increment('sent'), static fn() => $lease->write([]), static fn() => $lease->renew()] as $step) {
            Carbon::setTestNow(now()->addSeconds(45));

            self::assertTrue($step());
            self::assertSame(now()->addSeconds(60)->toDateTimeString(), $this->fresh()->leased_until?->toDateTimeString());
        }
        self::assertNull($this->take($this->fresh()), 'a holder that keeps stepping keeps the row past its first minute');
    }

    public function testARenewalIsTheHoldersAloneAndFreeRowsAreTheOnesNobodyHolds(): void
    {
        $lease = $this->take();
        self::assertNotNull($lease);
        $idle = LeasedRun::query()->create(['status' => 'sending']);
        $quiet = LeasedRun::query()->create(['status' => 'sending', 'lease_token' => 'gone-quiet', 'leased_until' => now()->subSecond()]);
        $free = static fn(): array => Lease::whereFree(LeasedRun::query())->orderBy('id')->pluck('id')->all();

        self::assertSame([$idle->id, $quiet->id], $free(), 'never held, or held by one that let its time run out');

        $this->set([Lease::TOKEN => 'another-worker']);
        self::assertFalse($lease->renew(), 'taken over: the old holder cannot hold it longer');
        self::assertSame('another-worker', $this->fresh()->lease_token);
    }

    public function testEveryStepMovesTheWorkOnOnlyWhileTheRowIsWorkable(): void
    {
        $lease = $this->take();
        self::assertNotNull($lease);

        self::assertTrue($lease->write(['cursor' => 7]));
        self::assertSame(7, $this->run->cursor);
        self::assertTrue($lease->increment('sent', ['cursor' => 8]), 'a tally and the cursor in one statement');
        self::assertSame([8, 1], [$this->fresh()->cursor, $this->fresh()->sent]);
        self::assertSame([8, 1], [$this->run->cursor, $this->run->sent]);
        self::assertTrue($lease->write([]), 'a step that writes nothing new, in the same second, still finds the row');

        $this->set(['status' => 'paused']);
        self::assertFalse($lease->write(['cursor' => 9]), 'paused: the holder stops at its next step');
        self::assertFalse($lease->increment('sent'));
        self::assertFalse($lease->finish(['status' => 'done']), 'a paused run is not marked done');
        self::assertSame(['paused', 8, 1], [$this->fresh()->status, $this->fresh()->cursor, $this->fresh()->sent]);

        $this->set(['status' => 'sending']);
        self::assertTrue($lease->write(['cursor' => 9]), 'resumed while it still held the row');
    }

    public function testAStepIsUndoneAndTheRowLetGoWithTheTokenAlone(): void
    {
        $lease = $this->take();
        self::assertNotNull($lease);
        $lease->write(['cursor' => 12]);

        $this->set(['status' => 'paused']);
        self::assertTrue($lease->move('cursor', 12, 11), 'a pause between a step and its undo does not skip the item');
        self::assertFalse($lease->move('cursor', 12, 10), 'only from where it was put');
        self::assertSame(11, $this->fresh()->cursor);

        self::assertTrue($lease->release(), 'a paused row is let go at once');
        self::assertSame([null, null], [$this->fresh()->lease_token, $this->fresh()->leased_until]);
        self::assertFalse($lease->release(), 'once');

        $this->set(['status' => 'sending']);
        self::assertNotNull($this->take($this->fresh()), 'resumed, it is taken without waiting for the time to run out');
    }

    public function testAStopFromOutsideEndsTheLeaseAndTheEndStandsOnce(): void
    {
        $lease = $this->take();
        self::assertNotNull($lease);

        $this->set(['status' => 'cancelled'] + Lease::FREE);
        self::assertFalse($lease->finish(['status' => 'done']), 'the cancel stands');
        self::assertFalse($lease->move('cursor', 0, 1));
        self::assertSame('cancelled', $this->fresh()->status);

        $this->set(['status' => 'sending']);
        $next = $this->take($this->fresh());
        self::assertNotNull($next);
        self::assertTrue($next->finish(['status' => 'done']));
        self::assertSame(['done', null, null], [$this->fresh()->status, $this->fresh()->lease_token, $this->fresh()->leased_until]);
        self::assertFalse($next->finish(['status' => 'done']));
        self::assertNull($this->take($this->fresh()), 'done: not workable any more');
    }

    public function testMySqlReportsTheRowsAnUpdateMatchedAsSqliteDoes(): void
    {
        // A renewal or a compare-and-swap that writes what is already there must not read as lost.
        $foundRows = \defined('Pdo\Mysql::ATTR_FOUND_ROWS') ? (int) \constant('Pdo\Mysql::ATTR_FOUND_ROWS') : \PDO::MYSQL_ATTR_FOUND_ROWS;

        self::assertTrue((new MySqlDriver())->connection([])['options'][$foundRows] ?? false);
        self::assertSame(1, LeasedRun::query()->whereKey($this->run->id)->update(['status' => 'sending']), 'SQLite counts a row it rewrote unchanged');
    }

    /**
     * The run, taken for a minute while it is sending.
     *
     * @phpstan-impure
     */
    private function take(?LeasedRun $run = null): ?Lease
    {
        return Lease::take($run ?? $this->run, 60, static fn(Builder $query) => $query->where('status', 'sending'));
    }

    /** @param array<string, mixed> $values Written from outside: a pause, a cancel */
    private function set(array $values): void
    {
        LeasedRun::query()->whereKey($this->run->id)->update($values);
    }

    /** @phpstan-impure */
    private function fresh(): LeasedRun
    {
        return LeasedRun::query()->findOrFail($this->run->id);
    }
}
