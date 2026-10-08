<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Core\Database\Sorting;
use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\PlanCategory;
use Tests\DatabaseTestCase;

/**
 * The admin's own order of a list (plans, categories, payment methods, levels…): a reorder puts the rows it names first,
 * in that order, and the rest after them as they were; a new row goes last — each shop's list its own.
 */
final class SortingTest extends DatabaseTestCase
{
    public function testTheRowsNamedComeFirstAndTheRestKeepTheirOrder(): void
    {
        [$a, $b, $c, $d] = array_map(fn(string $name): PlanCategory => $this->category(['name' => $name]), ['a', 'b', 'c', 'd']);
        Sorting::reorder(PlanCategory::class, [$d->id, $b->id, $c->id, $a->id]);
        self::assertSame(['d', 'b', 'c', 'a'], $this->order());

        Sorting::reorder(PlanCategory::class, [$c->id, $a->id, $c->id, 999_999]);

        self::assertSame(['c', 'a', 'd', 'b'], $this->order(), 'the rest as they stood, a repeated or unknown id passed over');
        $this->category(['name' => 'e']);
        self::assertSame(['c', 'a', 'd', 'b', 'e'], $this->order(), 'a new row after every existing one');
    }

    public function testEachShopsListIsItsOwn(): void
    {
        $bot = $this->agentBot();
        $theirs = CurrentBot::run($bot, fn(): PlanCategory => $this->category(['name' => 'theirs']));
        $mine = [$this->category(['name' => 'x']), $this->category(['name' => 'y'])];

        Sorting::reorder(PlanCategory::class, [$theirs->id, $mine[1]->id]);

        self::assertSame(['y', 'x'], $this->order());
        self::assertSame(1, CurrentBot::run($bot, static fn(): int => PlanCategory::query()->findOrFail($theirs->id)->sort), 'another shop\'s row is not reordered from here');
        self::assertSame(2, CurrentBot::run($bot, static fn(): int => Sorting::next(PlanCategory::class)), 'nor does it count in where theirs go');
    }

    /** @return list<string> The current shop's categories, in the admin's order */
    private function order(): array
    {
        return PlanCategory::query()->orderBy('sort')->orderBy('id')->pluck('name')->all();
    }
}
