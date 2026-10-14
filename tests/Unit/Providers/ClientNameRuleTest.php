<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Modules\Providers\DTO\Capabilities;
use App\Modules\Providers\DTO\ClientNameRule;
use App\Modules\Providers\Enums\NameProblem;
use PHPUnit\Framework\TestCase;

/**
 * What a panel takes as a client's name: why it would refuse one (length, characters, two separators side by side), and
 * the shop's own name made to fit — characters dropped, runs cut, cut to the longest, padded to the shortest.
 */
final class ClientNameRuleTest extends TestCase
{
    public function testAPanelThatSaysNothingTakesAnyNameButAnEmptyOne(): void
    {
        $any = (new Capabilities())->names;

        self::assertNull($any->problem('Ali Reza__#1'));
        self::assertSame(NameProblem::Length, $any->problem(''));
        self::assertSame('Ali Reza__#1', $any->fit('Ali Reza__#1'));
    }

    public function testEachProblemIsNamedFirstByLengthThenCharactersThenRuns(): void
    {
        $rule = new ClientNameRule(min: 3, max: 8, characters: 'a-z0-9_-', separatorRuns: false);

        self::assertSame(
            [null, NameProblem::Length, NameProblem::Length, NameProblem::Characters, NameProblem::Runs],
            [$rule->problem('ali_1'), $rule->problem('al'), $rule->problem('ali_12345'), $rule->problem('Ali_1'), $rule->problem('ali-_1')],
        );
        self::assertNull((new ClientNameRule(min: 3, max: 3))->problem('علی'), 'its length counted in characters, not bytes');
    }

    public function testFittingDropsCutsAndPads(): void
    {
        $rule = new ClientNameRule(min: 3, max: 8, characters: 'a-z0-9_-', separatorRuns: false);

        self::assertSame('li__1', (new ClientNameRule(characters: 'a-z0-9_'))->fit('Ali__1'), 'only what is not taken goes');
        self::assertSame(['li_1', 'ali_reza', '007', 'a_b'], [$rule->fit('Ali__1'), $rule->fit('ali_reza_long'), $rule->fit('7'), $rule->fit('a_-_b')]);
        self::assertTrue($rule->allows($rule->fit('X.@-_')), 'whatever goes in, what comes out is taken');
    }
}
