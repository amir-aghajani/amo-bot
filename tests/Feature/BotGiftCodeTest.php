<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Rewards\Enums\GiftCodeKind;
use App\Modules\Rewards\Exceptions\RewardUnavailableException;
use App\Modules\Rewards\Models\GiftCode;
use App\Modules\Rewards\Services\GiftCodes;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Tests\BotTestCase;

/**
 * «🎁 کد هدیه» in the bot: on the menu only while the shop takes gift codes; its screen asks for a code and awaits it —
 * Toman confirmed with the new balance, a plan's service sent and confirmed, a code refused with its reason and another
 * awaited, too many wrong ones a wait —, until the customer goes elsewhere.
 */
final class BotGiftCodeTest extends BotTestCase
{
    private User $ali;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->withoutQr();
        $this->ali = $this->customer();
    }

    public function testTheButtonIsOnTheMenuOnlyWhileGiftCodesAreOn(): void
    {
        $this->send($this->message('/start'));
        self::assertNotContains(Messages::MENU_GIFT, array_merge(...$this->replyKeyboard(0)));

        $this->giftCodesOn();
        $this->send($this->message('/start'));
        self::assertContains(Messages::MENU_GIFT, array_merge(...$this->replyKeyboard(0)), 'the default menu\'s last row');
    }

    public function testACodeTypedIsRedeemedAndSaidWithTheBalance(): void
    {
        $this->giftCodesOn();
        $this->giftCode('NOWRUZ7K3M9QXAPT');

        $this->send($this->message(Messages::MENU_GIFT));
        self::assertSame([self::text(BotText::GiftCodeAsk)], $this->said());

        $this->send($this->message('nowruz-7k3m-9qxapt'));
        self::assertSame([self::text(BotText::GiftCodeWallet, ['amount' => '۵۰٬۰۰۰ تومان', 'balance' => '۵۰٬۰۰۰ تومان'])], $this->said());
        self::assertSame([MainMenu::HOME], $this->callbacks(0));
        self::assertSame('50000.00', $this->ali->balance());

        $this->send($this->message('NOWRUZ7K3M9QXAPT'));
        self::assertSame([self::text(BotText::Unknown)], $this->said(), 'the code no longer awaited: the menu\'s answer to a stray text');
    }

    public function testARefusedCodeIsSaidAndAnotherAwaited(): void
    {
        $this->giftCodesOn();
        $this->giftCode('TAKENCODE234', ['redeemed_by' => $this->customer(['telegram_id' => 7002])->id, 'redeemed_at' => now()]);
        $this->giftCode('OLDCODE2345', ['expires_at' => now()->subDay()]);
        $this->giftCode('GOODCODE2345', ['amount' => '20000']);

        $this->send($this->message(Messages::MENU_GIFT));
        foreach (['NOSUCHCODE22' => BotText::GiftCodeUnknown, 'TAKENCODE234' => BotText::GiftCodeTaken, 'OLDCODE2345' => BotText::GiftCodeExpired] as $code => $text) {
            $this->send($this->message($code));
            self::assertSame([self::text($text)], $this->said(), $code);
        }

        $this->send($this->message('GOODCODE2345'));
        self::assertSame('20000.00', $this->ali->balance(), 'still awaited after the refusals');
    }

    public function testAPlansServiceIsSentAndSaid(): void
    {
        $this->giftCodesOn();
        $server = $this->sellingServer();
        $plan = $this->plan(['name' => 'یک‌ماهه'], $server);
        $this->giftCode('PLANGIFT2345', ['kind' => GiftCodeKind::Plan, 'amount' => null, 'plan_id' => $plan->id]);
        $this->giftCode('NOSERVER2345', ['kind' => GiftCodeKind::Plan, 'amount' => null, 'plan_id' => $this->plan(['name' => 'بی‌سرور'])->id]);

        $this->send($this->message(Messages::MENU_GIFT));
        $this->send($this->message('NOSERVER2345'));
        self::assertSame([self::text(BotText::GiftCodeUnavailable, ['reason' => RewardUnavailableException::NO_SERVER])], $this->said());
        self::assertNull(GiftCode::query()->where('code', 'NOSERVER2345')->value('redeemed_at'));

        $this->send($this->message('PLANGIFT2345'));
        $said = $this->said();
        self::assertCount(2, $said, 'the service, then the word on it');
        self::assertStringContainsString('یک‌ماهه', $said[0]);
        self::assertSame(self::text(BotText::GiftCodePlan, ['plan' => 'یک‌ماهه']), $said[1]);
    }

    public function testTooManyWrongCodesIsAWait(): void
    {
        $this->giftCodesOn();
        $this->send($this->message(Messages::MENU_GIFT));
        for ($i = 0; $i < GiftCodes::WRONG_CODES; $i++) {
            $this->send($this->message("WRONG{$i}CODE"));
        }

        $this->send($this->message('WRONGAGAIN22'));
        self::assertSame([self::text(BotText::GiftCodeWait, ['minutes' => '۶۰'])], $this->said());
    }

    public function testWhileOffTheScreenSaysSo(): void
    {
        $this->send($this->tap(MainMenu::GIFT));

        self::assertSame([self::text(BotText::GiftCodeOff)], $this->said());
    }
}
