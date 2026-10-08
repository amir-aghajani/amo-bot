<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Telegram\Models\ReportChat;
use App\Modules\Telegram\Reports\GroupProblem;
use App\Modules\Telegram\Reports\ReportGroupState;
use Illuminate\Support\Carbon;
use Tests\DatabaseTestCase;

/**
 * A bot's report group as the shop knows it: every read is the table's — what another process wrote a moment ago is
 * what this one acts on —, a change that depends on what is there is one conditional write, and the connect code is
 * used once.
 */
final class ReportGroupStateTest extends DatabaseTestCase
{
    private const GROUP = -1001234567890;

    public function testNothingIsKnownUntilAGroupIsConnectedAndForgettingItLeavesNothing(): void
    {
        $state = new ReportGroupState();
        self::assertSame([null, null, null, null, null, null, null], [$state->chatId(), $state->title(), $state->connectedAt(), $state->problem(), $state->pausedUntil(), $state->code(), $state->attempt()]);

        Carbon::setTestNow('2026-10-05 10:00:00');
        $state->issueCode();
        $state->recordAttempt('گروه بی‌تاپیک', GroupProblem::ForumOff);
        $state->connected(self::GROUP, 'گزارش‌ها');

        self::assertSame([self::GROUP, 'گزارش‌ها', '2026-10-05 10:00:00'], [$state->chatId(), $state->title(), $state->connectedAt()?->toDateTimeString()]);
        self::assertSame([null, null], [$state->code(), $state->attempt()], 'connecting uses the code up, and the refusal goes with it');

        $state->forget();
        self::assertNull($state->chatId());
        self::assertSame(0, ReportChat::query()->count());
    }

    public function testWhatAnotherProcessWroteIsWhatThisOneReads(): void
    {
        $poller = new ReportGroupState();
        self::assertNull($poller->chatId());

        // The panel — another process — connects a group and the sender pauses meanwhile.
        (new ReportGroupState())->connected(self::GROUP, 'گزارش‌ها');
        (new ReportGroupState())->pause(60);

        self::assertSame(self::GROUP, $poller->chatId());
        self::assertNotNull($poller->pausedUntil());

        (new ReportGroupState())->forget();
        self::assertNull($poller->chatId());
    }

    public function testAProblemIsWrittenOnlyWhenItChanges(): void
    {
        $state = new ReportGroupState();
        $state->connected(self::GROUP, 'گزارش‌ها');
        Carbon::setTestNow('2026-10-05 10:00:00');
        $state->setProblem(GroupProblem::Removed);

        Carbon::setTestNow('2026-10-05 10:05:00');
        $state->setProblem(GroupProblem::Removed);
        self::assertSame(GroupProblem::Removed, $state->problem());
        self::assertSame('2026-10-05 10:00:00', ReportChat::query()->firstOrFail()->updated_at->toDateTimeString(), 'the same problem again is no write');

        $state->setProblem(GroupProblem::NoTopicsRight);
        self::assertSame(GroupProblem::NoTopicsRight, $state->problem());

        $state->setProblem(null);
        self::assertNull($state->problem());
    }

    public function testALongerHoldStandsAndResumingEndsIt(): void
    {
        $state = new ReportGroupState();
        $state->connected(self::GROUP, 'گزارش‌ها');
        Carbon::setTestNow('2026-10-05 10:00:00');

        $state->pause(600);
        $state->pause(30);
        self::assertSame('2026-10-05 10:10:00', $state->pausedUntil()?->toDateTimeString(), 'a shorter hold does not cut a longer one short');

        $state->pause(1200);
        self::assertSame('2026-10-05 10:20:00', $state->pausedUntil()?->toDateTimeString());

        Carbon::setTestNow('2026-10-05 10:21:00');
        self::assertNull($state->pausedUntil(), 'a hold that is over is none');

        $state->pause(60);
        $state->resume();
        self::assertNull($state->pausedUntil());
    }

    public function testTheCodeWorksForItsHourAndConnectsOneGroup(): void
    {
        Carbon::setTestNow('2026-10-05 10:00:00');
        $state = new ReportGroupState();
        $code = $state->issueCode()['code'];

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $code);
        self::assertStringNotContainsString($code, (string) ReportChat::query()->toBase()->value('code'), 'kept encrypted');
        $outstanding = $state->code();
        self::assertNotNull($outstanding);
        self::assertSame([$code, '2026-10-05 11:00:00'], [$outstanding['code'], $outstanding['expires_at']->toDateTimeString()]);
        self::assertTrue($state->matches($code));
        self::assertFalse($state->matches('not-it'));
        self::assertTrue($state->matches($code), 'matching does not use it up');

        $first = new ReportGroupState();
        $second = new ReportGroupState();
        self::assertTrue($first->claim($code));
        self::assertFalse($second->claim($code), 'two messages carrying it connect one group');
        self::assertNull($state->code());

        $late = $state->issueCode()['code'];
        Carbon::setTestNow('2026-10-05 11:00:01');
        self::assertFalse($state->matches($late));
        self::assertFalse($state->claim($late), 'an hour later the link is dead');
    }

    public function testANewCodeReplacesTheOldOneAndTheRefusalThatCameWithIt(): void
    {
        $state = new ReportGroupState();
        $old = $state->issueCode()['code'];
        $state->recordAttempt('گروه اول', GroupProblem::NotAdmin);

        $attempt = $state->attempt();
        self::assertSame(['گروه اول', GroupProblem::NotAdmin], [$attempt['title'] ?? null, $attempt['problem'] ?? null]);

        $state->issueCode();
        self::assertFalse($state->matches($old));
        self::assertNull($state->attempt());
    }

    public function testEveryBotHasAGroupOfItsOwn(): void
    {
        $agent = $this->agentBot();
        $state = new ReportGroupState();
        $state->connected(self::GROUP, 'گزارش‌های فروشگاه');

        $agents = static fn(): ?int => CurrentBot::run($agent, static fn() => $state->chatId());
        self::assertNull($agents());
        CurrentBot::run($agent, static fn() => $state->connected(-1009999, 'گزارش‌های نماینده'));

        self::assertSame(self::GROUP, $state->chatId());
        self::assertSame(-1009999, $agents());
    }
}
