<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Core\Database\UniqueName;
use App\Core\Exceptions\ValidationException;
use Tests\DatabaseTestCase;
use Tests\Fakes\NamedRow;

/**
 * A name unique among its kind (a customer group, an agency level): a form hears it is taken before anything is
 * written, a row keeps its own name, and a save that lost the name to another in the same moment is refused the same
 * way instead of failing with the index's error.
 */
final class UniqueNameTest extends DatabaseTestCase
{
    private const TAKEN = 'این نام را گروه دیگری دارد.';

    protected function setUp(): void
    {
        parent::setUp();

        NamedRow::makeTable($this->db());
    }

    public function testANameIsTakenByAnotherRowOfItsKindInItsScopeOnly(): void
    {
        $vip = NamedRow::query()->create(['name' => 'VIP']);
        $this->db()->table(NamedRow::TABLE)->insert(['shop' => 2, 'name' => 'Friends']);

        self::assertTrue(UniqueName::taken(new NamedRow(), 'VIP'));
        self::assertFalse(UniqueName::taken($vip, 'VIP'), 'a row keeps its own name');
        self::assertFalse(UniqueName::taken(new NamedRow(), 'Friends'), 'another shop\'s rows are not in its scope');
        self::assertFalse(UniqueName::taken($vip, 'Colleagues'));
    }

    public function testANameTakenMeanwhileIsRefusedUnderNameAndNothingIsWritten(): void
    {
        $row = new NamedRow(['name' => 'VIP']);
        self::assertFalse(UniqueName::taken($row, 'VIP'), 'free when the form was checked');
        $this->db()->table(NamedRow::TABLE)->insert(['shop' => 1, 'name' => 'VIP']); // another save, in the same moment

        try {
            UniqueName::save($row, self::TAKEN);
            self::fail('the name was saved twice');
        } catch (ValidationException $e) {
            self::assertSame(['name' => [self::TAKEN]], $e->errors());
        }

        self::assertFalse($row->exists);
        self::assertSame(1, NamedRow::query()->where('name', 'VIP')->count());
    }

    public function testAFreeNameIsSavedAndARowRenamed(): void
    {
        $row = new NamedRow(['name' => 'VIP']);
        UniqueName::save($row, self::TAKEN);
        self::assertTrue($row->exists);

        $row->name = 'VIP+';
        UniqueName::save($row, self::TAKEN);

        self::assertSame(['VIP+'], NamedRow::query()->pluck('name')->all());
    }
}
