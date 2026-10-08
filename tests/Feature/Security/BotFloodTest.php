<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Update\ReceivedUpdates;
use Illuminate\Support\Carbon;
use Tests\BotTestCase;

/**
 * One customer cannot hold the bot up for everyone else: a chat sending faster than a person — more than
 * ReceivedUpdates::FLOOD_UPDATES updates within FLOOD_SECONDS, taps or messages — is left unanswered while it keeps on
 * (every answer is a Telegram call its limits slow down for every chat the bot serves), said once in the log, and served
 * again once it slows down. What Telegram held back while the bot was away is no flood: it is served as it comes.
 */
final class BotFloodTest extends BotTestCase
{
    private const OTHER_CHAT = 777_001;

    public function testAChatFasterThanAPersonIsLeftUnansweredWhileItKeepsOn(): void
    {
        Carbon::setTestNow(now());
        $logs = $this->logs();

        for ($i = 1; $i <= ReceivedUpdates::FLOOD_UPDATES; $i++) {
            $this->send($this->message('/start'));
            self::assertSame(['sendMessage'], $this->calls(), "update {$i} is answered");
        }
        foreach ([$this->message('/start'), $this->tap(MainMenu::HOME), $this->message('سلام')] as $flood) {
            $this->send($flood);
            self::assertSame([], $this->calls(), 'past the limit: no answer, not even to a tap');
        }
        self::assertCount(1, array_filter($logs->getRecords(), static fn($record): bool => str_contains($record->message, 'left unanswered while it keeps on')), 'said once, as it began');

        $this->send($this->message('/start', chat: self::OTHER_CHAT));
        self::assertSame(['sendMessage'], $this->calls(), 'every other chat is served as ever');

        Carbon::setTestNow(now()->addSeconds(ReceivedUpdates::FLOOD_SECONDS + 1));
        $this->send($this->message('/start'));
        self::assertSame(['sendMessage'], $this->calls(), 'slowed down, it is answered again');
    }

    public function testWhatTelegramHeldBackIsServedAsItComes(): void
    {
        Carbon::setTestNow(now());
        // The bot was away for a while: Telegram hands over what the customer sent meanwhile, all at once.
        $sent = now()->subMinutes(5)->getTimestamp();

        for ($i = 1; $i <= ReceivedUpdates::FLOOD_UPDATES * 2; $i++) {
            $this->send($this->message('/start', ['date' => $sent + $i]));
            self::assertSame(['sendMessage'], $this->calls(), "held-back update {$i} is answered");
        }
    }
}
