<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Database\Sorting;
use App\Core\Exceptions\ValidationException;
use App\Modules\Catalog\Models\PlanCategory;
use Tests\DatabaseTestCase;

/** The admin's order of a list: the ids given first, the rest after — and no more ids than a list the admin orders has. */
final class SortingTest extends DatabaseTestCase
{
    public function testTheIdsGivenComeFirstAndTheRestKeepTheirOrder(): void
    {
        [$a, $b, $c] = [$this->category(['name' => 'الف']), $this->category(['name' => 'ب']), $this->category(['name' => 'ج'])];

        Sorting::reorder(PlanCategory::class, [$c->id, 999, $c->id]);

        self::assertSame([$c->id, $a->id, $b->id], PlanCategory::query()->orderBy('sort')->pluck('id')->all(), 'duplicates and unknown ids ignored');
    }

    public function testAListLongerThanAnyTheAdminOrdersIsRefusedBeforeAnythingIsWritten(): void
    {
        $category = $this->category();
        $sort = $category->sort;

        try {
            Sorting::reorder(PlanCategory::class, range(1, Sorting::MAX_IDS + 1));
            self::fail('a body of ids past any list was taken');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('ids', $e->errors());
        }
        self::assertSame($sort, $category->refresh()->sort);

        Sorting::reorder(PlanCategory::class, range(1, Sorting::MAX_IDS));
        self::assertSame(1, $category->refresh()->sort, 'as many as a list may have');
    }
}
