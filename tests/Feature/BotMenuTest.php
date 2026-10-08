<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Settings\Services\Settings;
use App\Modules\Telegram\Handlers\TicketHandler;
use App\Modules\Telegram\Keyboard\KeyboardLayouts;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Support\Money;
use App\Support\Persian;
use Tests\BotTestCase;

/**
 * The main menu as the admin laid it out — a reply keyboard under the text field or buttons under a message, a button's
 * colour and premium emoji icon, a relabelled button routing by its new label, a stored layout the editor would refuse
 * never taking the menu away, one kept with a button the bot no longer has read without it, «بازگشت» putting the
 * greeting back in place of a screen — and the screens it opens that no flow of their own covers: support, «آموزش» in
 * the admin's words, and an agent's wallet with their credit.
 */
final class BotMenuTest extends BotTestCase
{
    public function testTheMenuFollowsTheAdminsLayoutInBothForms(): void
    {
        $layouts = $this->service(KeyboardLayouts::class);
        $layouts->save('start', ['type' => 'reply', 'rows' => [[['action' => 'support', 'label' => '📞 تماس با ما', 'style' => 'danger'], ['action' => 'buy', 'label' => 'خرید', 'style' => 'primary']]]]);
        $this->send($this->message('/start'));
        self::assertSame([[['text' => 'خرید', 'style' => 'primary'], ['text' => '📞 تماس با ما', 'style' => 'danger']]], $this->markup(0)['keyboard'] ?? null, 'reversed for the screen, styles passed to Telegram');

        // The relabelled button still routes.
        $this->send($this->message('📞 تماس با ما'));
        self::assertSame([self::text(BotText::SupportUnavailable)], $this->said());
        self::assertSame(self::supportButtons(), $this->callbacks(0), 'its own buttons — a first-level screen carries no inline back while the menu is under the field');

        // Switch to inline: the greeting takes the old reply keyboard away, the menu follows as buttons under a message.
        $this->inlineStartMenu([[['action' => 'buy', 'label' => 'خرید', 'style' => 'success']]]);
        $this->send($this->message('/start'));
        self::assertCount(2, $this->calls());
        self::assertSame(['remove_keyboard' => true], $this->markup(0));
        self::assertSame(self::text(BotText::MenuPrompt), $this->params(1)['text']);
        self::assertSame([[['text' => 'خرید', 'callback_data' => MainMenu::PLANS, 'style' => 'success']]], $this->inlineKeyboard(1));

        // Next /start is a single message; screens now carry "back" to the menu.
        $this->send($this->message('/start'));
        self::assertCount(1, $this->calls());
        $this->send($this->tap(MainMenu::SUPPORT));
        self::assertSame(['editMessageText', 'answerCallbackQuery'], $this->calls(), 'the screen in place of the tapped message, then the tap acknowledged');
        self::assertSame([...self::supportButtons(), MainMenu::HOME], $this->callbacks(0));
    }

    /** @return list<string> What «پشتیبانی» carries besides its words: «🗂️ تیکت‌های من» and «📨 تیکت جدید» (on the right — Telegram lays a row out left to right). */
    private static function supportButtons(): array
    {
        return [TicketHandler::listCallback(1), TicketHandler::START];
    }

    public function testBackOnAScreenPutsTheGreetingInPlaceOfItWithTheMenuWhenTheMenuIsButtons(): void
    {
        $this->send($this->message('/start'));
        $greeting = $this->params(0)['text'];

        // A «بازگشت» left from when the menu was buttons: the greeting comes back alone, the menu is under the field.
        $this->send($this->tap(MainMenu::HOME));
        self::assertSame(['editMessageText', 'answerCallbackQuery'], $this->calls());
        self::assertSame($greeting, $this->params(0)['text']);
        self::assertArrayNotHasKey('reply_markup', $this->params(0));

        $this->inlineStartMenu([[['action' => 'support', 'label' => 'پشتیبانی', 'style' => null]]]);
        $this->send($this->tap(MainMenu::SUPPORT));
        $this->send($this->tap(MainMenu::HOME));

        self::assertSame(['editMessageText', 'answerCallbackQuery'], $this->calls(), 'in place of the screen');
        self::assertSame($greeting, $this->params(0)['text']);
        self::assertSame([MainMenu::SUPPORT], $this->callbacks(0), 'with the menu under it');
    }

    public function testNotUnderstoodTakesAStaleReplyKeyboardAwayFromAnInlineMenu(): void
    {
        $this->send($this->message('/start'));
        $this->inlineStartMenu([[['action' => 'buy', 'label' => 'خرید', 'style' => null]]]);

        $this->send($this->message('hello?'));

        self::assertSame(['sendMessage', 'sendMessage'], $this->calls());
        self::assertSame([self::text(BotText::Unknown), ['remove_keyboard' => true]], [$this->params(0)['text'], $this->markup(0)]);
        self::assertSame([MainMenu::PLANS], $this->callbacks(1), 'the inline menu follows');
    }

    public function testAButtonsPremiumEmojiIconGoesToTelegramAndItsTapStillRoutesByTheLabel(): void
    {
        $this->service(KeyboardLayouts::class)->save('start', ['type' => 'reply', 'rows' => [[
            ['action' => 'support', 'label' => 'تماس', 'style' => null, 'icon' => '5368324170671202286'],
            ['action' => 'buy', 'label' => 'خرید', 'style' => 'primary'],
        ]]]);
        $this->send($this->message('/start'));
        self::assertSame([[['text' => 'خرید', 'style' => 'primary'], ['text' => 'تماس', 'icon_custom_emoji_id' => '5368324170671202286']]], $this->markup(0)['keyboard'] ?? null, 'the icon on its own button');

        // A reply button's tap sends its text alone, which still routes.
        $this->send($this->message('تماس'));
        self::assertSame([self::text(BotText::SupportUnavailable)], $this->said());

        $this->inlineStartMenu([[['action' => 'buy', 'label' => 'خرید', 'style' => 'success', 'icon' => '42']]]);
        $this->send($this->message('/start'));
        self::assertSame([[['text' => 'خرید', 'callback_data' => MainMenu::PLANS, 'style' => 'success', 'icon_custom_emoji_id' => '42']]], $this->inlineKeyboard(1));
    }

    public function testAStoredLayoutTheEditorWouldRefuseNeverTakesTheMenuAway(): void
    {
        $log = $this->logs();
        // A row changed by hand: an icon that is no premium emoji's id.
        $this->service(Settings::class)->set('bot.keyboard.start', ['type' => 'reply', 'rows' => [[['action' => 'buy', 'label' => 'خرید', 'style' => null, 'icon' => 'abc']]]]);

        $this->send($this->message('/start'));

        self::assertSame([[Messages::MENU_BUY], [Messages::MENU_RENEW, Messages::MENU_SERVICES], [Messages::MENU_WALLET, Messages::MENU_TUTORIAL], [Messages::MENU_SUPPORT]], $this->replyKeyboard(0), 'the built-in menu stands in');
        self::assertTrue($log->hasWarningThatContains('not a layout the editor would save'));
    }

    public function testALayoutKeptWithAButtonTheBotNoLongerHasReadsWithoutIt(): void
    {
        $log = $this->logs();
        // Saved when the menu still had «اکانت تست».
        $this->service(Settings::class)->set('bot.keyboard.start', ['type' => 'reply', 'rows' => [
            [['action' => 'trial', 'label' => '🔑 اکانت تست', 'style' => null, 'icon' => null], ['action' => 'buy', 'label' => 'خرید', 'style' => null, 'icon' => null]],
            [['action' => 'trial-2', 'label' => 'تست دوم', 'style' => null, 'icon' => null]],
        ]]);

        $this->send($this->message('/start'));

        self::assertSame([['خرید']], $this->replyKeyboard(0), 'the rest of the admin\'s layout, a row left empty gone with it');
        self::assertFalse($log->hasWarningThatContains('not a layout the editor would save'), 'nothing wrong with it');

        $this->send($this->message('🔑 اکانت تست'));
        self::assertSame([self::text(BotText::Unknown)], $this->said(), 'its label is no button any more');
    }

    public function testSupportShowsTheContactTheAdminGave(): void
    {
        $this->botSettings('general', ['enabled' => true, 'phone_required' => false, 'support_contact' => '@amo_support']);

        $this->send($this->tap(MainMenu::SUPPORT));

        self::assertSame([self::text(BotText::SupportContact, ['contact' => '@amo_support'])], $this->said());
    }

    public function testTheTutorialIsTheAdminsWords(): void
    {
        $this->send($this->message(Messages::MENU_TUTORIAL));
        self::assertSame([self::text(BotText::Tutorial)], $this->said(), 'a fresh shop\'s pointing at support');
        self::assertStringContainsString('«پشتیبانی»', self::text(BotText::Tutorial));

        $this->service(BotTexts::class)->save(BotText::Tutorial, '📚 برای آموزش اتصال به <a href="https://t.me/amo_help">کانال آموزش</a> سر بزنید.');
        $this->inlineStartMenu([[['action' => 'tutorial', 'label' => 'آموزش']]]);
        $this->send($this->tap(MainMenu::TUTORIAL));
        self::assertSame(['📚 برای آموزش اتصال به <a href="https://t.me/amo_help">کانال آموزش</a> سر بزنید.'], $this->said());
        self::assertSame([MainMenu::HOME], $this->callbacks(0), 'and the way back to the menu');
    }

    public function testAnAgentsWalletShowsTheirCreditAndADebtAsOne(): void
    {
        $this->wallet($this->agent(credit: '500000'), '-20000');

        $this->send($this->tap(MainMenu::WALLET));

        $line = self::text(BotText::WalletHistoryDebit, ['amount' => Money::format('20000'), 'entry' => 'test', 'when' => Persian::date(now())]);
        self::assertSame([
            self::text(BotText::WalletBalance, ['balance' => Messages::balance('-20000')])
            . self::text(BotText::WalletCredit, ['credit' => Messages::credit('500000')])
            . self::text(BotText::WalletHistory, ['history' => $line]),
        ], $this->said());

        // A customer who is no agent has no credit to show.
        $this->customer(['telegram_id' => 7070]);
        $this->send($this->tap(MainMenu::WALLET, 7070));
        self::assertSame([self::text(BotText::WalletBalance, ['balance' => Messages::balance('0')]) . self::text(BotText::WalletNoHistory)], $this->said());
    }
}
