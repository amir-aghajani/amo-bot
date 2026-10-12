<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Session\ChatSession;
use App\Modules\Telegram\Session\SessionStore;
use Illuminate\Support\Carbon;
use Tests\BotTestCase;

/**
 * A chat's conversation (Session\ChatSession): a step of a flow with its scratch, left behind by the next step or by
 * leaving the flow while the chat's own facts (the `_` keys) stay through both; a last step that runs once however many
 * taps reach it in the same moment; a held /start link taken once, within the hour; and its row written only when an
 * update changed something.
 */
final class ChatSessionTest extends BotTestCase
{
    public function testTheNextStepLeavesThePreviousStepsScratchAndTheChatsFactsStay(): void
    {
        $session = $this->session();
        $session->markReplyKeyboard(true);
        $session->enter('flow.amount', ['amount' => 50_000]);
        $session->enter('flow.confirm', ['note' => 'ok']);
        $session->save();

        $stored = $this->session();
        self::assertSame('flow.confirm', $stored->state());
        self::assertTrue($stored->inState('flow'), 'a step of the flow');
        self::assertNull($stored->get('amount'), "the previous step's scratch is behind");
        self::assertSame('ok', $stored->get('note'));
        self::assertTrue($stored->showsReplyKeyboard(), "the chat's own fact stays");
    }

    public function testLeavingTheFlowKeepsOnlyTheChatsFacts(): void
    {
        $session = $this->session();
        $session->enter('flow.amount', ['amount' => 50_000]);
        $session->markReplyKeyboard(true);
        $session->clear();
        $session->save();

        $stored = $this->session();
        self::assertNull($stored->state());
        self::assertFalse($stored->inState('flow'));
        self::assertNull($stored->get('amount'));
        self::assertTrue($stored->showsReplyKeyboard());
    }

    public function testOfTwoTapsOnALastStepInTheSameMomentOneRunsIt(): void
    {
        $session = $this->session();
        $session->enter('flow.confirm');
        $session->save();
        // Both taps found the chat at the confirmation.
        $first = $this->session();
        $second = $this->session();

        self::assertTrue($first->claim('flow.confirm'));
        self::assertFalse($second->claim('flow.confirm'), 'the other finds it spent and does nothing');
        self::assertFalse($this->session()->claim('flow.confirm'), 'as does one after the chat left the step');
        self::assertNull($this->session()->state());
    }

    public function testAHeldStartLinkOpensOnceWithinTheHour(): void
    {
        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->keep('usertest');

        Carbon::setTestNow('2026-10-07 13:00:00');
        $session = $this->session();
        self::assertSame('usertest', $session->takeStartLink(), 'an hour on, it still opens');
        $session->save();
        self::assertNull($this->session()->takeStartLink(), 'once');

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->keep('usertest');
        Carbon::setTestNow('2026-10-07 13:00:01');
        self::assertNull($this->session()->takeStartLink(), 'past the hour, nothing');
    }

    public function testTheMenuAgainWritesNothing(): void
    {
        // A chat with two facts: a membership confirmed (the channel rule) and the menu's keyboard on its screen.
        $this->botSettings('channels', ['join_required' => true]);
        $this->channel(-1001, 'کانال اخبار');
        $this->telegram()->reply(['status' => 'member']);
        $this->send($this->message('/start'));
        self::assertSame(['getChatMember', 'sendMessage'], $this->calls(), 'a member, then the menu');

        $this->db()->flushQueryLog();
        $this->db()->enableQueryLog();
        try {
            $this->send($this->message('/start'));
            $again = $this->calls();
            $this->send($this->message('hello?'));
            $sql = array_column($this->db()->getQueryLog(), 'query');
        } finally {
            $this->db()->disableQueryLog();
            $this->db()->flushQueryLog();
        }

        self::assertSame(['sendMessage'], $again, 'the membership trusted, the menu again');
        $writes = array_filter($sql, static fn(string $query): bool => preg_match('/^(insert|update)\b.*telegram_sessions/is', $query) === 1);
        self::assertSame([], array_values($writes), 'no flow to leave, every fact put again as it was');
    }

    private function session(): ChatSession
    {
        return $this->service(SessionStore::class)->load(self::CHAT);
    }

    /** A /start link a gate held back, kept in the chat now. */
    private function keep(string $link): void
    {
        $session = $this->session();
        $session->keepStartLink($link);
        $session->save();
    }
}
