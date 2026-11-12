<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerCards;

use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\Customers\CustomerCards;
use App\Modules\Telegram\Reports\GroupButtons;
use App\Modules\Users\Models\User;
use Tests\BotTestCase;

/**
 * What every card's question shares (CustomerCards::ask() and reply()): it is asked as a reply to the card, naming the
 * admin who pressed (the reply field theirs alone — anyone's when they have no handle to name); it is answered once — a
 * refused reply gives it back for another, a later one is told it was answered —; a reply that is no text is asked
 * again, one by anyone but a bot admin changes nothing and says who may, and one about a customer gone since says so and
 * takes the question away. A reply to anything else of the bot's is not a card's business.
 */
final class CardRepliesTest extends BotTestCase
{
    /** A card of the bot's in the group, under which the buttons are pressed. */
    private const CARD = 5252;

    /** @var array<string, int> */
    private array $threads;

    private User $ali;

    protected function setUp(): void
    {
        parent::setUp();

        $this->threads = $this->reportGroup();
        $this->admin(['telegram_id' => self::GROUP_ADMIN, 'username' => 'boss']);
        $this->ali = $this->customer(['telegram_id' => 7070, 'username' => 'ali']);
    }

    public function testTheQuestionIsAskedUnderTheCardNamingTheAdmin(): void
    {
        $this->send($this->groupTap("ud:a:{$this->ali->id}", self::CARD, $this->threads['users']));

        self::assertSame(['sendMessage', 'answerCallbackQuery'], $this->calls());
        $question = $this->params(0);
        self::assertSame(self::CARD, json_decode($question['reply_parameters'], true)['message_id'], 'under the card');
        self::assertStringEndsWith(" \u{200E}@boss", $question['text']);
        self::assertTrue(json_decode($question['reply_markup'], true)['force_reply']);
        self::assertSame(CustomerCards::WRITE_REPLY, $this->popup());
        self::assertStringStartsWith('ask:ud:', (string) ReportMessage::query()->where('message_id', $this->prompt())->value('ref'));
    }

    public function testTheReplyFieldOpensForTheAdminItNamesOrForAnyoneWhenItNamesNobody(): void
    {
        $this->send($this->groupTap("ud:a:{$this->ali->id}", self::CARD, $this->threads['users']));
        self::assertTrue($this->markup(0)['selective'], 'for @boss alone');

        // Telegram's `selective` opens the field only for those the text @mentions: for nobody, without a handle.
        $this->admin(['telegram_id' => 9191, 'username' => null, 'first_name' => 'Sara']);
        $this->send($this->groupTap("ud:a:{$this->ali->id}", self::CARD, $this->threads['users'], from: 9191));
        $markup = $this->markup(0);
        self::assertTrue($markup['force_reply']);
        self::assertArrayNotHasKey('selective', $markup);
        self::assertStringNotContainsString('@', $this->params(0)['text']);
    }

    public function testAQuestionIsAnsweredOnceAndARefusedReplyKeepsItForAnother(): void
    {
        $this->send($this->groupTap("ud:a:{$this->ali->id}", self::CARD, $this->threads['users']));
        $prompt = $this->prompt();

        $this->send($this->groupReply('…', '150', $this->threads['users'], $prompt));
        self::assertSame(['sendMessage'], $this->calls(), 'refused: the question stays');
        self::assertSame(0, $this->ali->refresh()->discount_percent);

        $this->send($this->groupReply('…', '30', $this->threads['users'], $prompt));
        self::assertSame(['editMessageText', 'deleteMessage', 'sendMessage'], $this->calls(), 'given back, then answered');
        self::assertSame(30, $this->ali->refresh()->discount_percent);

        // Answered again — before the question was deleted, once Telegram no longer deletes it, or by another admin —:
        // nothing done, said so.
        $this->admin(['telegram_id' => 9191, 'username' => 'sara']);
        foreach ([self::GROUP_ADMIN, 9191] as $from) {
            $this->send($this->groupReply('…', '40', $this->threads['users'], $prompt, $from));
            self::assertSame(['sendMessage'], $this->calls());
            self::assertSame(CustomerCards::ANSWERED, $this->params(0)['text']);
        }
        self::assertSame(30, $this->ali->refresh()->discount_percent);
    }

    public function testAReplyThatIsNoTextIsAskedAgain(): void
    {
        $this->send($this->groupTap("ud:a:{$this->ali->id}", self::CARD, $this->threads['users']));

        $this->send($this->groupReply('…', null, $this->threads['users'], $this->prompt(), extra: ['photo' => [['file_id' => 'p', 'width' => 90, 'height' => 90]]]));

        self::assertSame(CustomerCards::TEXT_ONLY, $this->params(0)['text']);
        self::assertNotContains('deleteMessage', $this->calls(), 'the question stays');
    }

    public function testAReplyByAnyoneButABotAdminChangesNothing(): void
    {
        $this->send($this->groupTap("ud:a:{$this->ali->id}", self::CARD, $this->threads['users']));

        $this->send($this->groupReply('…', '30', $this->threads['users'], $this->prompt(), from: 9090));

        self::assertSame(GroupButtons::onlyAdmins(CustomerCards::MAY), $this->params(0)['text']);
        self::assertSame(0, $this->ali->refresh()->discount_percent);
    }

    public function testAReplyAboutACustomerGoneSaysSoAndTakesTheQuestionAway(): void
    {
        $this->send($this->groupTap("ud:a:{$this->ali->id}", self::CARD, $this->threads['users']));
        $prompt = $this->prompt();
        $this->ali->delete();

        $this->send($this->groupReply('…', '30', $this->threads['users'], $prompt));

        self::assertSame(['sendMessage', 'deleteMessage'], $this->calls());
        self::assertSame(CustomerCards::GONE, $this->params(0)['text']);
    }

    public function testAReplyToAnotherMessageOfTheBotsIsNotACardsBusiness(): void
    {
        $this->send($this->groupReply('گزارش', '30', $this->threads['users'], 4444));

        self::assertSame([], $this->calls());
    }

    /** The message id of the latest question a card asked. */
    private function prompt(): int
    {
        return (int) ReportMessage::query()->where('ref', 'like', 'ask:%')->latest('id')->value('message_id');
    }
}
