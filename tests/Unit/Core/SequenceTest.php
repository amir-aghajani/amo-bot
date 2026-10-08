<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Database\Sequence;
use Tests\DatabaseTestCase;

final class SequenceTest extends DatabaseTestCase
{
    public function testEachNameCountsFromOneOnItsOwn(): void
    {
        $sequences = $this->service(Sequence::class);

        self::assertSame([1, 2, 3], [$sequences->next('a'), $sequences->next('a'), $sequences->next('a')]);
        self::assertSame(1, $sequences->next('b'), 'another name is another counter');
        self::assertSame(4, $sequences->next('a'), 'and the counter survives the other name being used');
    }
}
