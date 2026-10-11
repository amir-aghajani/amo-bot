<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Rewards\Enums\RewardKind;
use App\Modules\Telegram\Handlers\TopUpHandler;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Models\User;
use App\Support\Persian;
use Illuminate\Support\Carbon;
use Tests\BotTestCase;
use Tests\Fakes\FakeProvider;

/**
 * «🎡 گردونه شانس» in the bot: on the menu only while the wheel turns; its screen lists the slices, the cost and how
 * often one may spin, with «بچرخان» — or when the next spin is —; a spin answers at once, in one message, with the
 * slices, the one drawn marked, and what came of it; too soon is a popup, a short wallet the top-up.
 */
final class BotWheelTest extends BotTestCase
{
    private const SPIN = 'wheel:spin';

    private User $ali;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->withoutQr();
        $this->ali = $this->customer();
    }

    public function testTheButtonIsOnTheMenuOnlyWhileTheWheelTurns(): void
    {
        $this->send($this->message('/start'));
        self::assertNotContains(Messages::MENU_WHEEL, array_merge(...$this->replyKeyboard(0)));

        $this->wheelOn();
        $this->send($this->message('/start'));
        self::assertContains(Messages::MENU_WHEEL, array_merge(...$this->replyKeyboard(0)));
    }

    public function testTheScreenListsTheSlicesTheCostAndTheSpinButton(): void
    {
        $this->wheelOn(['wheel_cost' => 5000, 'wheel_cooldown_hours' => 12]);
        $this->wheelPrize('۱۰ هزار تومان');
        $this->wheelPrize('پوچ', ['kind' => RewardKind::Nothing, 'amount' => null]);
        $this->wheelPrize('خاموش', ['enabled' => false]);

        $this->send($this->message(Messages::MENU_WHEEL));

        self::assertSame([self::text(BotText::WheelScreen, [
            'prizes' => "🎁 ۱۰ هزار تومان\n🎁 پوچ",
            'cost' => self::text(BotText::WheelCost, ['amount' => '۵٬۰۰۰ تومان', 'every' => '۱۲ ساعت']),
            'turn' => '',
        ])], $this->said());
        self::assertSame([self::SPIN], $this->callbacks(0));
    }

    public function testASpinAnswersAtOnceWithTheSliceDrawnMarkedAndWhatWasWon(): void
    {
        $this->wheelOn();
        $this->wheelPrize('پوچ', ['kind' => RewardKind::Nothing, 'amount' => null, 'enabled' => false]);
        $this->wheelPrize('۱۰ هزار تومان');
        $this->wheelPrize('۵ گیگ', ['kind' => RewardKind::Traffic, 'amount' => '5']);

        $this->send($this->tap(self::SPIN));

        self::assertSame(['editMessageText', 'answerCallbackQuery'], $this->calls(), 'one message, no frames: a handler never sleeps');
        $next = now()->addHours(24);
        $result = self::text(BotText::WheelWon, [
            'prize' => '۱۰ هزار تومان',
            'given' => self::text(BotText::WheelGivenWallet, ['amount' => '۱۰٬۰۰۰ تومان', 'balance' => '۱۰٬۰۰۰ تومان']),
            'turn' => self::text(BotText::WheelNext, ['when' => Persian::date($next, withTime: true), 'left' => '۱ روز']),
        ]);
        // The slice drawn is the only one that reaches a customer without a service.
        self::assertSame(["👉 <b>۱۰ هزار تومان</b>\n▫️ ۵ گیگ\n\n" . $result], $this->said());
        self::assertSame([MainMenu::WHEEL], $this->callbacks(0));
        self::assertSame('10000.00', $this->ali->balance());
    }

    public function testTooSoonIsAPopupAndTheScreenSaysWhen(): void
    {
        $this->wheelOn();
        $this->wheelPrize();
        $this->send($this->tap(self::SPIN));
        Carbon::setTestNow(now()->addHours(2));

        $this->send($this->tap(self::SPIN));
        self::assertSame(['answerCallbackQuery'], $this->calls());
        self::assertSame(self::text(BotText::WheelTooSoon, ['left' => '۲۲ ساعت']), $this->popup());

        $this->send($this->tap(MainMenu::WHEEL));
        self::assertStringContainsString(self::text(BotText::WheelNext, ['when' => Persian::date(now()->addHours(22), withTime: true), 'left' => '۲۲ ساعت']), $this->said()[0]);
        self::assertSame([], $this->callbacks(0), 'no «بچرخان» until then');
    }

    public function testAShortWalletIsOfferedTheTopUp(): void
    {
        $this->wheelOn(['wheel_cost' => 5000]);
        $this->wheelPrize();
        $this->wallet($this->ali, '2000');

        $this->send($this->tap(self::SPIN));

        self::assertSame([self::text(BotText::WheelShort, ['amount' => '۵٬۰۰۰ تومان', 'missing' => '۳٬۰۰۰ تومان'])], $this->said());
        self::assertSame([TopUpHandler::START, MainMenu::WHEEL], $this->callbacks(0));
    }

    public function testNothingWonAndAPrizeNotGivenAreSaid(): void
    {
        $this->wheelOn(['wheel_cost' => 1000]);
        $this->wallet($this->ali, '5000');
        $server = $this->sellingServer();
        $service = $this->subscription($this->ali, $this->plan([], $server), $server, 'ali_1', ['expires_at' => now()->addDays(20)]);
        FakeProvider::mirror($service);
        $traffic = $this->wheelPrize('۵ گیگ', ['kind' => RewardKind::Traffic, 'amount' => '5']);
        FakeProvider::$unreachable = true;

        $this->send($this->tap(self::SPIN));
        $said = $this->said();
        self::assertStringStartsWith("👉 <b>۵ گیگ</b>\n\n⚠️ گردونه روی «۵ گیگ» ایستاد", end($said), 'the slice drawn, the result under it');
        self::assertStringContainsString(self::text(BotText::WheelRefunded), end($said));
        self::assertSame('5000.00', $this->ali->balance());

        FakeProvider::$unreachable = false;
        $traffic->forceFill(['enabled' => false])->save();
        $this->wheelPrize('پوچ', ['kind' => RewardKind::Nothing, 'amount' => null]);
        $this->send($this->tap(self::SPIN));
        $said = $this->said();
        self::assertStringStartsWith("👉 <b>پوچ</b>\n\n😕 <b>پوچ!</b>", end($said), 'the failed spin counted for no cooldown');
    }

    public function testWhileOffTheScreenSaysSo(): void
    {
        $this->send($this->tap(MainMenu::WHEEL));
        self::assertSame([self::text(BotText::WheelOff)], $this->said());

        $this->wheelOn(['wheel_buyers_only' => true]);
        $this->send($this->tap(MainMenu::WHEEL));
        self::assertSame([self::text(BotText::WheelBuyersOnly)], $this->said());
    }
}
