<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Handlers\PurchaseHandler;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Models\User;
use Tests\BotTestCase;

/**
 * The admin's bot settings as the customer meets them: the master switch and phone verification are
 * gates every update passes before any handler — each answers what it holds back itself, and a bot admin
 * passes them all.
 */
final class BotGatesTest extends BotTestCase
{
    public function testAnOffBotAnswersEveryMessageWithTheNoticeAndTakesTheKeyboardAway(): void
    {
        $this->botSettings('general', ['enabled' => false, 'phone_required' => false]);

        $this->send($this->message('/start'));
        self::assertSame(['sendMessage'], $this->calls());
        self::assertSame(self::text(BotText::BotOff), $this->params(0)['text']);
        self::assertSame(['remove_keyboard' => true], $this->markup(0), 'the menu keyboard is taken off the screen');
        $this->send($this->message(Messages::MENU_BUY));
        self::assertSame([self::text(BotText::BotOff)], $this->said(), 'a leftover menu tap gets the notice, not the shop');
        $this->send($this->tap(PurchaseHandler::planCallback(1)));
        self::assertSame(['answerCallbackQuery'], $this->calls(), 'a button tap gets the popup only');
        self::assertSame([self::text(BotText::BotOff), 'true'], [$this->popup(), $this->params(0)['show_alert']]);
    }

    public function testTheChatRemembersTheNoticeTookTheKeyboardAway(): void
    {
        $this->send($this->message('/start'));
        $this->botSettings('general', ['enabled' => false, 'phone_required' => false]);
        $this->send($this->message('hi'));

        // Back on, with the menu inline now: nothing is under the text field to take away first.
        $this->botSettings('general', ['enabled' => true, 'phone_required' => false]);
        $this->inlineStartMenu([[['action' => 'buy', 'label' => 'خرید']]]);
        $this->send($this->message('/start'));

        self::assertSame(['sendMessage'], $this->calls());
        self::assertSame([MainMenu::PLANS], $this->callbacks(0), 'the greeting carries the menu itself');
    }

    public function testAdminsStillGetThroughAnOffBot(): void
    {
        $this->botSettings('general', ['enabled' => false, 'phone_required' => true]);
        $this->admin();

        $this->send($this->message('/start'));

        self::assertSame(self::text(BotText::Welcome, ['name' => 'Ali']), $this->params(0)['text']);
        self::assertNotSame([], $this->replyKeyboard(0), 'the menu, not the notice or the phone prompt');
    }

    public function testTurningTheBotBackOnBringsTheMenuBack(): void
    {
        $this->botSettings('general', ['enabled' => false, 'phone_required' => false]);
        $this->send($this->message('/start'));

        $this->botSettings('general', ['enabled' => true, 'phone_required' => false]);
        $this->send($this->message('hi'));

        self::assertSame(self::text(BotText::Unknown), $this->params(0)['text']);
        self::assertNotSame([], $this->replyKeyboard(0), 'any text re-sends the menu keyboard');
    }

    public function testPhoneVerificationAsksForTheNumberUntilTheOwnContactArrives(): void
    {
        $this->botSettings('general', ['enabled' => true, 'phone_required' => true]);

        $this->send($this->message('/start'));
        self::assertSame(['sendMessage'], $this->calls());
        self::assertSame(self::text(BotText::PhonePrompt), $this->params(0)['text']);
        self::assertSame([[['text' => self::text(BotText::PhoneButton), 'style' => 'primary', 'request_contact' => true]]], $this->markup(0)['keyboard'] ?? null, 'one button that shares the number');
        self::assertNull(User::query()->where('telegram_id', self::CHAT)->firstOrFail()->phone);

        // Text, a menu tap and a button press all get the same ask.
        $this->send($this->message(Messages::MENU_BUY));
        self::assertSame([self::text(BotText::PhonePrompt)], $this->said());
        $this->send($this->tap(PurchaseHandler::planCallback(1)));
        self::assertSame(['sendMessage', 'answerCallbackQuery'], $this->calls(), 'the ask, then the tap the gate left unanswered acknowledged');
        self::assertSame([self::text(BotText::PhonePrompt)], $this->said());
        self::assertNull($this->popup(), 'with nothing to say on the button');

        // A contact from the address book is not proof of anything.
        $this->send($this->contact('+989120000000', userId: 777));
        self::assertSame(self::text(BotText::PhoneNotYours), $this->params(0)['text']);
        self::assertArrayHasKey('request_contact', $this->markup(0)['keyboard'][0][0] ?? []);
        self::assertNull(User::query()->where('telegram_id', self::CHAT)->firstOrFail()->phone);

        // The customer's own card: stored, confirmed, and straight to the menu.
        $this->send($this->contact('98 912-000 0000', userId: self::CHAT));
        self::assertSame(self::text(BotText::PhoneVerified), $this->params(0)['text']);
        self::assertSame(self::text(BotText::Welcome, ['name' => 'Ali']), $this->params(1)['text']);
        self::assertNotSame([], $this->replyKeyboard(1), 'the menu replaces the contact button');

        $user = User::query()->where('telegram_id', self::CHAT)->firstOrFail();
        self::assertSame('+989120000000', $user->phone, 'digits only, with the international prefix');
        self::assertTrue($user->hasVerifiedPhone());

        // Verified once, never asked again.
        $this->send($this->message('/start'));
        self::assertSame(self::text(BotText::Welcome, ['name' => 'Ali']), $this->params(0)['text']);
    }

    public function testTheNumbersButtonIsTakenAwayForAnInlineMenuOnceTheNumberIsIn(): void
    {
        $this->inlineStartMenu([[['action' => 'buy', 'label' => 'خرید']]]);
        $this->botSettings('general', ['enabled' => true, 'phone_required' => true]);
        $this->send($this->message('/start'));

        $this->send($this->contact('+989120000000', userId: self::CHAT));

        self::assertSame([self::text(BotText::PhoneVerified), self::text(BotText::Welcome, ['name' => 'Ali']), self::text(BotText::MenuPrompt)], $this->said());
        self::assertSame(['remove_keyboard' => true], $this->markup(1), 'the greeting takes the button off the screen');
        self::assertSame([MainMenu::PLANS], $this->callbacks(2), 'the menu follows under a message');
    }

    public function testACustomerVerifiedEarlierIsNotAskedWhenTheSwitchComesBackOn(): void
    {
        $this->customer(['phone' => '+989350000000']);
        $this->botSettings('general', ['enabled' => true, 'phone_required' => true]);

        $this->send($this->message('/start'));

        self::assertSame(self::text(BotText::Welcome, ['name' => 'Ali']), $this->params(0)['text']);
    }

    public function testWithoutTheSwitchAContactJustOpensTheMenuAndStoresNothing(): void
    {
        $this->send($this->contact('+989120000000', userId: self::CHAT));

        self::assertSame(['sendMessage'], $this->calls());
        self::assertSame(self::text(BotText::Welcome, ['name' => 'Ali']), $this->params(0)['text']);
        self::assertNull(User::query()->where('telegram_id', self::CHAT)->firstOrFail()->phone, 'nothing is stored the customer was not asked for');
    }
}
