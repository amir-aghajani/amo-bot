<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Telegram\BotState;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Polling\Poller;
use App\Modules\Telegram\Tasks\PruneUpdatesTask;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Update\ReceivedUpdates;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Output\NullOutput;
use Tests\BotTestCase;
use Tests\Support\FakeTelegram;

/**
 * Each update once: one Telegram sends again — a webhook answered past its patience, a poller that died before it
 * confirmed its offset — is left alone, a group's too, and one whose serving failed is not served again either (at most
 * once); the same id from another bot is that bot's own; the newest says when a bot last heard from Telegram, and the
 * record is cut back once Telegram can no longer send an update again.
 */
final class ReceivedUpdatesTest extends BotTestCase
{
    public function testAnUpdateTelegramSendsAgainIsServedOnce(): void
    {
        $log = $this->logs();
        $start = $this->message('/start');
        $this->send($start);
        self::assertSame(['sendMessage'], $this->calls());

        // A webhook answered past Telegram's patience: the same update comes again — and gets nothing.
        $this->send($start);
        self::assertSame([], $this->calls());
        self::assertTrue($log->hasInfoThatContains("update {$start->id()} again; it was served once already"));

        $tap = $this->tap(MainMenu::SUPPORT);
        $this->send($tap);
        $this->send($tap);
        self::assertSame([], $this->calls(), 'a tap too');
    }

    public function testAnUpdateWhoseServingFailedIsNotServedAgain(): void
    {
        $start = $this->message('/start');
        // Telegram out of reach as the welcome goes: the serving fails halfway, and the customer is told.
        $this->telegram()->fail(502, 'Bad Gateway');
        $this->send($start);
        self::assertSame(['sendMessage', 'sendMessage'], $this->calls());
        self::assertSame(self::text(BotText::Error), $this->params(1)['text']);

        $this->send($start);

        self::assertSame([], $this->calls(), 'at most once, by decision: what was half done is not done twice');
    }

    public function testAGroupsUpdateTelegramSendsAgainIsLeftAlone(): void
    {
        $press = $this->groupTap('nobody:knows', 9, self::FIRST_THREAD);
        $this->send($press);
        self::assertSame(['answerCallbackQuery'], $this->calls(), 'a button no route takes: its spinner stopped');

        $this->send($press);

        self::assertSame([], $this->calls());
    }

    public function testThePollerServesAnUpdateHandedOverAgainOnce(): void
    {
        $start = $this->message('/start');
        $this->telegram()->on('getMe', static fn(): array => ['id' => FakeTelegram::BOT_ID, 'is_bot' => true, 'username' => 'amo_bot', 'first_name' => 'AmoBot']);
        // A poller that stopped before confirming its offset: the next one is handed the same update.
        $this->telegram()->on('getUpdates', static fn(): array => [$start->raw]);
        $poll = fn(): bool => $this->service(Poller::class)->run(new NullOutput(), 1, once: true, dropPending: false, schedule: false, stop: static fn(): bool => false);

        self::assertTrue($poll());
        self::assertTrue($poll());

        self::assertSame([self::text(BotText::Welcome, ['name' => 'Ali'])], $this->telegram()->sentTo(self::CHAT), 'one welcome');
    }

    public function testAnUpdateIdIsEachBotsOwn(): void
    {
        $bot = $this->agentBot();
        $start = $this->message('/start');

        $this->send($start);
        self::assertSame(['sendMessage'], $this->calls());

        $this->sendTo($bot, $start);
        self::assertSame(['sendMessage'], $this->calls(), "the agent's bot numbers its updates on its own");

        $this->sendTo($bot, $start);
        self::assertSame([], $this->calls());
    }

    public function testTheNewestRecordIsWhenTheBotLastHeardFromTelegram(): void
    {
        $lastAt = fn(): ?string => $this->service(BotState::class)->lastUpdateAt()?->toDateTimeString();
        self::assertNull($lastAt(), 'nothing yet');

        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->send($this->message('/start'));
        Carbon::setTestNow('2026-10-05 11:30:00');
        $this->send($this->message('hi'));

        self::assertSame('2026-10-05 11:30:00', $lastAt());
        self::assertNull(CurrentBot::run($this->agentBot(), $lastAt), "another bot's are not this one's");
    }

    public function testWhatTelegramCanNoLongerSendAgainIsForgotten(): void
    {
        Carbon::setTestNow('2026-10-01 10:00:00');
        $old = $this->message('/start');
        $this->send($old);
        $recent = $this->message('hi');
        Carbon::setTestNow(now()->addHours(ReceivedUpdates::KEEP_HOURS)->subMinute());
        $this->send($recent);

        Carbon::setTestNow(Carbon::parse('2026-10-01 10:00:01')->addHours(ReceivedUpdates::KEEP_HOURS));
        $this->service(PruneUpdatesTask::class)->run();

        $this->send($old);
        self::assertSame(['sendMessage'], $this->calls(), 'older than KEEP_HOURS: gone from the record — Telegram no longer sends it');
        $this->send($recent);
        self::assertSame([], $this->calls(), 'still remembered');
    }
}
