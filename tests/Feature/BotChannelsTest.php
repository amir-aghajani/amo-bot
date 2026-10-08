<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Api\Limits;
use App\Modules\Telegram\Channels\ChannelMembership;
use App\Modules\Telegram\Channels\JoinPrompt;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Models\BotChannel;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Tests\BotTestCase;

/**
 * The required-channels rule as the customer meets it: the join screen, the "I joined" button, the
 * membership trusted a while once confirmed, and the rule failing open when Telegram cannot answer.
 */
final class BotChannelsTest extends BotTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->botSettings('channels', ['join_required' => true]);
        $this->channel(-1001, 'کانال اخبار', ['username' => 'news']);
        $this->channel(-1002, 'گروه VIP', ['type' => 'supergroup', 'username' => null, 'invite_link' => 'https://t.me/+vip']);
    }

    public function testACustomerOutsideAChannelGetsTheJoinScreenInsteadOfTheMenu(): void
    {
        $this->telegram()->reply(['status' => 'left'], ['status' => 'member']);

        $this->send($this->message('/start'));

        self::assertSame(['getChatMember', 'getChatMember', 'sendMessage'], $this->calls(), 'every channel looked up, then the screen');
        self::assertSame(['-1001', (string) self::CHAT], [$this->params(0)['chat_id'], $this->params(0)['user_id']]);
        self::assertSame([self::text(BotText::JoinPrompt)], $this->said());
        $keyboard = $this->inlineKeyboard(2);
        self::assertSame([['text' => self::text(BotText::JoinChannel, ['title' => 'کانال اخبار']), 'url' => 'https://t.me/news']], $keyboard[0], 'only the channel still missing is offered');
        self::assertSame(JoinPrompt::CHECK, $keyboard[1][0]['callback_data']);
        self::assertCount(2, $keyboard);
        self::assertSame(['is_disabled' => true], json_decode($this->params(2)['link_preview_options'], true), 'the links stay buttons, no preview');

        // Any other message gets the same screen, and nothing is cached.
        $this->telegram()->reply(['status' => 'left'], ['status' => 'left']);
        $this->send($this->message(Messages::MENU_BUY));
        self::assertSame(['getChatMember', 'getChatMember', 'sendMessage'], $this->calls());
        self::assertCount(3, $this->inlineKeyboard(2), 'both channels now');
    }

    public function testATapHeldBackGetsTheJoinScreenAndItsSpinnerStopped(): void
    {
        $this->telegram()->reply(['status' => 'left'], ['status' => 'member']);

        $this->send($this->tap(MainMenu::PLANS));

        self::assertSame([self::text(BotText::JoinPrompt)], $this->said());
        self::assertSame(['getChatMember', 'getChatMember', 'sendMessage', 'answerCallbackQuery'], $this->calls(), 'acknowledged once the gate answered');
        self::assertNull($this->popup());
    }

    public function testAMemberGetsThroughAndIsNotLookedUpAgainForAWhile(): void
    {
        $this->telegram()->reply(['status' => 'member'], ['status' => 'restricted', 'is_member' => true]);

        $this->send($this->message('/start'));
        self::assertSame(['getChatMember', 'getChatMember', 'sendMessage'], $this->calls());
        self::assertSame([self::text(BotText::Welcome, ['name' => 'Ali'])], $this->said());
        $this->send($this->message(Messages::MENU_SERVICES));
        self::assertSame(['sendMessage'], $this->calls(), 'membership confirmed a moment ago is trusted');

        // A new channel on the list invalidates that.
        $this->channel(-1003, 'سوم');
        $this->telegram()->reply(['status' => 'member'], ['status' => 'member'], ['status' => 'left']);
        $this->send($this->message(Messages::MENU_SERVICES));
        self::assertSame(['getChatMember', 'getChatMember', 'getChatMember', 'sendMessage'], $this->calls());
        self::assertSame([self::text(BotText::JoinPrompt)], $this->said());
    }

    public function testAConfirmedMembershipIsLookedUpAgainOnceItsWhileIsOver(): void
    {
        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->telegram()->reply(['status' => 'member'], ['status' => 'member']);
        $this->send($this->message('/start'));

        Carbon::setTestNow(now()->addSeconds(ChannelMembership::RECHECK_AFTER - 1));
        $this->send($this->message('hi'));
        self::assertSame(['sendMessage'], $this->calls(), 'still trusted');

        // The customer left a channel meanwhile.
        Carbon::setTestNow(now()->addSeconds(2));
        $this->telegram()->reply(['status' => 'member'], ['status' => 'left']);
        $this->send($this->message('hi'));
        self::assertSame(['getChatMember', 'getChatMember', 'sendMessage'], $this->calls());
        self::assertSame([self::text(BotText::JoinPrompt)], $this->said());
    }

    public function testTheJoinedButtonLooksAgainAndOpensTheMenuOnceEveryoneIsIn(): void
    {
        // Still outside one: a popup naming it, and the screen trimmed to what is left.
        $this->telegram()->reply(['status' => 'member'], ['status' => 'left']);
        $this->send($this->tap(JoinPrompt::CHECK));
        self::assertSame(['getChatMember', 'getChatMember', 'answerCallbackQuery', 'editMessageText'], $this->calls());
        self::assertSame([self::text(BotText::JoinStillMissing, ['title' => 'گروه VIP']), 'true'], [$this->popup(), $this->params(2)['show_alert']]);
        $keyboard = $this->inlineKeyboard(3);
        self::assertSame('https://t.me/+vip', $keyboard[0][0]['url']);
        self::assertCount(2, $keyboard);

        // Everyone joined: confirmed, and the menu follows.
        $this->telegram()->reply(['status' => 'member'], ['status' => 'administrator']);
        $this->send($this->tap(JoinPrompt::CHECK));
        self::assertSame(['getChatMember', 'getChatMember', 'answerCallbackQuery', 'editMessageText', 'sendMessage'], $this->calls());
        self::assertSame([self::text(BotText::JoinDone), self::text(BotText::Welcome, ['name' => 'Ali'])], $this->said());
        self::assertNotSame([], $this->replyKeyboard(4));

        // The confirmation holds for a while: the next message is not looked up…
        $this->send($this->message('hi'));
        self::assertSame(['sendMessage'], $this->calls());

        // …but the button always looks again.
        $this->telegram()->reply(['status' => 'member'], ['status' => 'member']);
        $this->send($this->tap(JoinPrompt::CHECK));
        self::assertSame(2, array_count_values($this->calls())['getChatMember'] ?? 0);
    }

    public function testAPopupTooLongForTelegramIsCutToWhatItShows(): void
    {
        // The admin's longest wording for a popup, about a channel with a long title: past what Telegram shows.
        $wording = str_repeat('ا', BotText::JoinStillMissing->spec()->kind->limit() - mb_strlen('%title%')) . '%title%';
        $this->service(BotTexts::class)->save(BotText::JoinStillMissing, $wording);
        $this->channel(-1003, $title = str_repeat('ب', 128));
        $this->telegram()->reply(['status' => 'member'], ['status' => 'member'], ['status' => 'left']);

        $this->send($this->tap(JoinPrompt::CHECK));

        $said = Messages::fill($wording, ['title' => $title]);
        self::assertGreaterThan(Limits::POPUP, mb_strlen($said));
        self::assertSame(mb_substr($said, 0, Limits::POPUP), $this->popup(), 'cut, not refused');
        self::assertSame([self::text(BotText::JoinPrompt)], $this->said(), 'and the screen trimmed all the same');
    }

    public function testAFreshlyVerifiedPhoneNumberStillHasToPassTheChannels(): void
    {
        $this->botSettings('general', ['enabled' => true, 'phone_required' => true]);
        $this->telegram()->reply(['status' => 'left'], ['status' => 'member']);

        $this->send($this->contact('+989120000000', userId: self::CHAT));

        self::assertSame(['sendMessage', 'getChatMember', 'getChatMember', 'sendMessage'], $this->calls());
        self::assertSame([self::text(BotText::PhoneVerified), self::text(BotText::JoinPrompt)], $this->said(), 'the number is accepted, and the channel rule comes right after, before any menu');
        self::assertTrue(User::query()->where('telegram_id', self::CHAT)->firstOrFail()->hasVerifiedPhone());

        // Once in the channels, the menu.
        $this->telegram()->reply(['status' => 'member'], ['status' => 'member']);
        $this->send($this->tap(JoinPrompt::CHECK));
        self::assertSame([self::text(BotText::JoinDone), self::text(BotText::Welcome, ['name' => 'Ali'])], $this->said());
    }

    public function testAChannelTelegramCannotAnswerForLetsTheCustomerThroughAndIsFlagged(): void
    {
        $log = $this->logs();
        $this->telegram()->fail(400, 'Bad Request: member list is inaccessible');
        $this->telegram()->reply(['status' => 'member']);

        $this->send($this->message('/start'));

        self::assertSame([self::text(BotText::Welcome, ['name' => 'Ali'])], $this->said(), 'a broken channel never locks the shop');
        self::assertFalse(BotChannel::query()->where('chat_id', -1001)->firstOrFail()->bot_is_admin, 'the admin sees the channel needs attention');
        self::assertTrue($log->hasWarningThatContains('Membership check for channel -1001 failed'));
    }

    public function testTelegramOutOfReachForAMomentLetsTheCustomerThroughWithoutFlaggingTheChannel(): void
    {
        $this->telegram()->fail(502, 'Bad Gateway');
        $this->telegram()->reply(['status' => 'member']);

        $this->send($this->message('/start'));

        self::assertSame([self::text(BotText::Welcome, ['name' => 'Ali'])], $this->said());
        self::assertTrue(BotChannel::query()->where('chat_id', -1001)->firstOrFail()->bot_is_admin, 'nothing is wrong with the channel');
    }

    public function testAdminsAndASwitchedOffRuleSkipTheLookupAltogether(): void
    {
        $admin = $this->admin();
        $this->send($this->message('/start'));
        self::assertSame(['sendMessage'], $this->calls(), 'an admin is never looked up');

        $admin->forceFill(['role' => UserRole::Customer])->save();
        $this->botSettings('channels', ['join_required' => false]);
        $this->send($this->message('/start'));
        self::assertSame(['sendMessage'], $this->calls());

        // A stale "I joined" button with the rule off just opens the menu.
        $this->send($this->tap(JoinPrompt::CHECK));
        self::assertSame(['answerCallbackQuery', 'editMessageText', 'sendMessage'], $this->calls());
    }
}
