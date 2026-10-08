<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Referrals\Services\ReferralService;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\UserActions;
use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\BotTestCase;

/**
 * «زیرمجموعه‌گیری» in the bot: a customer's invite code (made once) and link, the newcomer it brings — someone not in
 * the database yet, theirs from that first /start (even one a gate holds back), never a customer the shop already knew —
 * the owner told of each, and the screen that shows the link, what it earns and the numbers so far; no link while the
 * program is off or the bot's @username unknown, and the menu's button only while the program runs.
 */
final class ReferralTest extends BotTestCase
{
    /** A newcomer's chat: someone other than the fixtures' customer. */
    private const NEWCOMER = 777_000_111;

    private User $owner;
    private string $code;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
        $this->referralProgram();
        $this->owner = $this->customer();
        $this->code = $this->service(ReferralService::class)->codeFor($this->owner);
    }

    public function testTheInviteCodeIsEightLowerCaseCharactersWithoutLookAlikesMadeOnce(): void
    {
        self::assertMatchesRegularExpression('/^[a-hjkmnp-z2-9]{8}$/', $this->code, 'no 0/o, 1/l/i');
        self::assertSame($this->code, $this->service(ReferralService::class)->codeFor($this->owner->refresh()), 'asked again, the same code');
        self::assertSame("https://t.me/amo_shop_bot?start=ref_{$this->code}", $this->service(ReferralService::class)->linkFor($this->owner));
    }

    public function testANewcomerThroughALinkBelongsToItsOwnerWhoIsTold(): void
    {
        $this->send($this->message('/start ref_' . $this->code, [], self::NEWCOMER));

        $newcomer = User::query()->where('telegram_id', self::NEWCOMER)->firstOrFail();
        self::assertSame($this->owner->id, $newcomer->referred_by);
        self::assertSame([self::text(BotText::ReferralJoined)], $this->telegram()->sentTo(self::CHAT), 'the owner hears of it');
        self::assertNotSame([], $this->telegram()->sentTo(self::NEWCOMER), 'the newcomer is greeted as usual');
    }

    public function testTheCodeIsReadWhateverItsCase(): void
    {
        $this->send($this->message('/start ref_' . strtoupper($this->code), [], self::NEWCOMER));

        self::assertSame($this->owner->id, User::query()->where('telegram_id', self::NEWCOMER)->firstOrFail()->referred_by);
    }

    public function testACustomerAlreadyInTheDatabaseNeverBecomesAReferral(): void
    {
        $this->send($this->message('/start', [], self::NEWCOMER));

        $this->send($this->message('/start ref_' . $this->code, [], self::NEWCOMER));

        self::assertNull(User::query()->where('telegram_id', self::NEWCOMER)->firstOrFail()->referred_by, 'registered by the plain /start a moment earlier');
        self::assertSame([], $this->telegram()->sentTo(self::CHAT));
    }

    /** @return array<string, array{\Closure(self): void, BotText}> A rule that holds a newcomer back, and what it answers them */
    public static function gates(): array
    {
        return [
            'the phone rule' => [static fn(self $test) => $test->botSettings('general', ['enabled' => true, 'phone_required' => true, 'support_contact' => '']), BotText::PhonePrompt],
            'the channel rule' => [static function (self $test): void {
                $test->botSettings('channels', ['join_required' => true]);
                $test->channel(-1001, 'کانال اخبار');
                $test->telegram()->on('getChatMember', static fn(): array => ['status' => 'left']);
            }, BotText::JoinPrompt],
        ];
    }

    /** @param \Closure(self): void $rule */
    #[DataProvider('gates')]
    public function testTheFirstStartCountsEvenWhenAGateHoldsItBack(\Closure $rule, BotText $answer): void
    {
        $rule($this);

        $this->send($this->message('/start ref_' . $this->code, [], self::NEWCOMER));

        self::assertContains(self::text($answer), $this->telegram()->sentTo(self::NEWCOMER), 'the rule answered the newcomer');
        self::assertSame($this->owner->id, User::query()->where('telegram_id', self::NEWCOMER)->firstOrFail()->referred_by);
        self::assertSame([self::text(BotText::ReferralJoined)], $this->telegram()->sentTo(self::CHAT));
    }

    public function testALinkBringsNobodyWhileTheProgramIsOffOrWhenItsCodeLeadsNowhere(): void
    {
        $this->referralProgram(enabled: false);
        $this->send($this->message('/start ref_' . $this->code, [], self::NEWCOMER));
        self::assertNull(User::query()->where('telegram_id', self::NEWCOMER)->firstOrFail()->referred_by, 'the program is off');

        $this->referralProgram();
        $this->send($this->message('/start ref_nosuchcode', [], self::NEWCOMER + 1));
        self::assertNull(User::query()->where('telegram_id', self::NEWCOMER + 1)->firstOrFail()->referred_by, 'nobody has that code');

        $this->service(UserActions::class)->setStatus($this->owner, $this->panelActor(), ['status' => 'banned']);
        $this->send($this->message('/start ref_' . $this->code, [], self::NEWCOMER + 2));
        self::assertNull(User::query()->where('telegram_id', self::NEWCOMER + 2)->firstOrFail()->referred_by, 'a banned customer brings nobody');
        self::assertSame([], $this->telegram()->sentTo(self::CHAT));
    }

    public function testTheScreenShowsTheLinkWhatItEarnsAndTheNumbersSoFar(): void
    {
        $friend = $this->customer(['telegram_id' => self::NEWCOMER, 'referred_by' => $this->owner->id]);
        $this->customer(['telegram_id' => self::NEWCOMER + 1, 'referred_by' => $this->owner->id]);
        $this->paidByCard($this->topUpOrder($friend, '120000'));

        $this->send($this->tap(MainMenu::AFFILIATES));

        $link = 'https://t.me/amo_shop_bot?start=ref_' . $this->code;
        $screen = static fn(BotText $terms): string => self::text(BotText::ReferralScreen, [
            'terms' => self::text($terms, ['rate' => '۱۰٪']),
            'link' => $link,
            'referrals' => '۲',
            'earned' => Money::format('12000'),
        ]);
        self::assertSame([$screen(BotText::ReferralTermsEvery)], $this->said());
        self::assertSame([[['text' => self::text(BotText::ReferralShare), 'url' => 'https://t.me/share/url?url=' . rawurlencode($link)]]], $this->inlineKeyboard(0));

        $this->referralProgram(firstOnly: true);
        $this->send($this->tap(MainMenu::AFFILIATES));
        self::assertSame([$screen(BotText::ReferralTermsFirst)], $this->said());
    }

    public function testTheMenuCarriesTheButtonOnlyWhileTheProgramRuns(): void
    {
        $this->send($this->message('/start'));
        self::assertSame([
            [Messages::MENU_BUY],
            [Messages::MENU_RENEW, Messages::MENU_SERVICES],
            [Messages::MENU_WALLET, Messages::MENU_TUTORIAL],
            [Messages::MENU_AFFILIATES, Messages::MENU_SUPPORT],
        ], $this->replyKeyboard(0));

        $this->referralProgram(enabled: false);
        $this->send($this->message('/start'));
        self::assertSame([
            [Messages::MENU_BUY],
            [Messages::MENU_RENEW, Messages::MENU_SERVICES],
            [Messages::MENU_WALLET, Messages::MENU_TUTORIAL],
            [Messages::MENU_SUPPORT],
        ], $this->replyKeyboard(0), 'the button waits on the layout for the program to come back');
    }

    public function testTheScreenSaysSoWhileTheProgramIsOff(): void
    {
        $this->referralProgram(enabled: false);

        $this->send($this->tap(MainMenu::AFFILIATES));

        self::assertSame([self::text(BotText::ReferralOff)], $this->said());
    }

    public function testWhileTheBotsUsernameIsUnknownThereIsNoLinkToShare(): void
    {
        $this->config(['telegram.username' => '']);

        $this->send($this->tap(MainMenu::AFFILIATES));

        self::assertNull($this->service(ReferralService::class)->linkFor($this->owner));
        self::assertSame([self::text(BotText::ReferralOff)], $this->said());
    }
}
