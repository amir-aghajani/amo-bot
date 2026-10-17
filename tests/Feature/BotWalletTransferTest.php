<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Agency\Services\AgencyStanding;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Exceptions\SellingSuspendedException;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Telegram\Handlers\TopUpHandler;
use App\Modules\Telegram\Handlers\WalletTransferHandler;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Models\TelegramSession;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\User;
use App\Modules\Users\Models\WalletTransfer;
use App\Modules\Users\Services\CustomerLookup;
use App\Modules\Users\Services\WalletTransfers;
use Illuminate\Support\Carbon;
use Tests\BotTestCase;

/**
 * «💸 انتقال موجودی» in the bot: the button beside the wallet's top-up while the shop offers it; the recipient's numeric
 * Telegram id asked for and typed (Persian digits too); the amount asked for within what the customer may send now; whom
 * and how much, to confirm or cancel; the money sent once confirmed, the question answered in place and the recipient
 * told. A refused recipient or amount says why and another is awaited; a customer who may send nothing says why at once;
 * a confirmation is claimed once.
 */
final class BotWalletTransferTest extends BotTestCase
{
    private const SARA = 7070;

    private User $ali;
    private User $sara;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-14 12:00:00');
        $this->ali = $this->wallet($this->customer(), '100000');
        $this->sara = $this->customer(['telegram_id' => self::SARA, 'first_name' => 'Sara', 'last_name' => 'Ahmadi', 'username' => 'sara']);
    }

    public function testTheWalletOffersTheTransferBesideTheTopUpOnlyWhileTheShopDoes(): void
    {
        $this->send($this->tap(MainMenu::WALLET));
        self::assertSame([TopUpHandler::START], $this->callbacks(0), 'off by default');

        $this->walletTransfersOn();
        $this->send($this->tap(MainMenu::WALLET));
        self::assertSame([[
            ['text' => self::text(BotText::WalletSend), 'callback_data' => WalletTransferHandler::PREFIX],
            ['text' => self::text(BotText::WalletTopup), 'callback_data' => TopUpHandler::START, 'style' => 'success'],
        ]], $this->inlineKeyboard(0), 'one row, the top-up first: on the right, as Telegram lays a row out left to right');
    }

    public function testTheIdAndTheAmountTypedAreConfirmedAndTheMoneySent(): void
    {
        $this->walletTransfersOn();

        $this->send($this->tap(WalletTransferHandler::PREFIX));
        self::assertSame(['editMessageText', 'answerCallbackQuery'], $this->calls(), 'the question in place of the screen');
        self::assertSame([self::text(BotText::WalletSendAsk, ['min' => '۱۰٬۰۰۰ تومان', 'sendable' => '۱۰۰٬۰۰۰ تومان'])], $this->said());
        self::assertSame([MainMenu::WALLET], $this->callbacks(0), '«بازگشت» to the wallet');

        $this->send($this->message('۷۰۷۰'));
        self::assertSame([self::text(BotText::WalletSendAmount, ['name' => "Sara Ahmadi (\u{200E}@sara)", 'min' => '۱۰٬۰۰۰ تومان', 'sendable' => '۱۰۰٬۰۰۰ تومان', 'balance' => '۱۰۰٬۰۰۰ تومان'])], $this->said());
        self::assertSame([MainMenu::WALLET], $this->callbacks(0));

        $this->send($this->message('۳۰٬۰۰۰ تومان'));
        self::assertSame([self::text(BotText::WalletSendConfirm, ['amount' => '۳۰٬۰۰۰ تومان', 'name' => "Sara Ahmadi (\u{200E}@sara)", 'balance' => '۷۰٬۰۰۰ تومان'])], $this->said());
        $yes = WalletTransferHandler::PREFIX . $this->sara->id . ':30000';
        self::assertSame([[
            ['text' => self::text(BotText::Cancel), 'callback_data' => MainMenu::WALLET],
            ['text' => self::text(BotText::WalletSendYes), 'callback_data' => $yes, 'style' => 'danger'],
        ]], $this->inlineKeyboard(0));
        self::assertSame('0.00', $this->sara->balance(), 'nothing sent yet');

        $this->send($this->tap($yes));
        self::assertSame(['70000.00', '30000.00'], [$this->ali->balance(), $this->sara->balance()]);
        $edit = (int) array_search('editMessageText', $this->calls(), true);
        self::assertSame(self::text(BotText::WalletSendDone, ['amount' => '۳۰٬۰۰۰ تومان', 'name' => "Sara Ahmadi (\u{200E}@sara)", 'balance' => '۷۰٬۰۰۰ تومان']), $this->params($edit)['text'], 'said in place of the question');
        self::assertSame([MainMenu::WALLET], $this->callbacks($edit), '«بازگشت» to the wallet');
        self::assertSame([self::text(BotText::WalletReceived, ['amount' => '۳۰٬۰۰۰ تومان', 'name' => 'Ali', 'balance' => '۳۰٬۰۰۰ تومان'])], $this->telegram()->sentTo(self::SARA), 'the recipient told, the sender named as their Telegram account shows them');
        self::assertSame([], $this->telegram()->sentTo(self::CHAT), 'the sender is answered in place, no notice beside it');

        $this->send($this->tap($yes));
        self::assertSame(self::text(BotText::WalletSendExpired), $this->popup(), 'a second tap sends nothing');
        self::assertSame(1, WalletTransfer::query()->count());
    }

    public function testARefusedRecipientIsSaidAndAnotherIdAwaited(): void
    {
        $this->walletTransfersOn();
        $this->customer(['telegram_id' => 7171, 'status' => UserStatus::Banned]);
        $this->send($this->tap(WalletTransferHandler::PREFIX));

        foreach (['sara' => CustomerLookup::NOT_AN_ID, '9999' => CustomerLookup::UNKNOWN, (string) self::CHAT => WalletTransfers::TO_THEMSELVES, '7171' => WalletTransfers::BANNED] as $typed => $reason) {
            $this->send($this->message((string) $typed));
            self::assertSame([self::text(BotText::WalletSendRecipientRefused, ['reason' => $reason])], $this->said(), (string) $typed);
            self::assertSame([MainMenu::WALLET], $this->callbacks(0));
        }

        $this->send($this->message((string) self::SARA));
        self::assertStringStartsWith("💸 انتقال موجودی به Sara Ahmadi (\u{200E}@sara)", $this->said()[0], 'still awaited');
    }

    public function testAnAmountOutOfRangeIsSaidAndAnotherAwaited(): void
    {
        $this->walletTransfersOn();
        $this->send($this->tap(WalletTransferHandler::PREFIX));
        $this->send($this->message((string) self::SARA));

        $invalid = self::text(BotText::WalletSendAmountInvalid, ['min' => '۱۰٬۰۰۰ تومان', 'sendable' => '۱۰۰٬۰۰۰ تومان']);
        foreach (['سی هزار', '5000', '100001'] as $typed) {
            $this->send($this->message($typed));
            self::assertSame([$invalid], $this->said(), $typed);
            self::assertSame([MainMenu::WALLET], $this->callbacks(0));
        }

        $this->send($this->message('100000'));
        self::assertSame(WalletTransferHandler::PREFIX . $this->sara->id . ':100000', $this->callbacks(0)[1], 'the whole balance may go');
    }

    public function testAnotherAmountTypedAtTheQuestionAsksAboutThatOneInstead(): void
    {
        $this->walletTransfersOn();
        $this->send($this->tap(WalletTransferHandler::PREFIX));
        $this->send($this->message((string) self::SARA));
        $this->send($this->message('30000'));

        $this->send($this->message('20000'));
        self::assertSame([self::text(BotText::WalletSendConfirm, ['amount' => '۲۰٬۰۰۰ تومان', 'name' => "Sara Ahmadi (\u{200E}@sara)", 'balance' => '۸۰٬۰۰۰ تومان'])], $this->said());

        $this->send($this->tap(WalletTransferHandler::PREFIX . $this->sara->id . ':20000'));
        self::assertSame(['80000.00', '20000.00'], [$this->ali->balance(), $this->sara->balance()]);
    }

    public function testAQuestionTappedOutOfTurnEndsTheStep(): void
    {
        $this->walletTransfersOn();
        $this->send($this->tap(WalletTransferHandler::PREFIX));
        $this->send($this->message((string) self::SARA));
        $this->send($this->message('30000'));
        $this->send($this->message('20000'));

        $this->send($this->tap(WalletTransferHandler::PREFIX . $this->sara->id . ':30000'));
        self::assertSame(self::text(BotText::WalletSendExpired), $this->popup(), 'the question about 30,000 is over');
        $this->send($this->tap(WalletTransferHandler::PREFIX . $this->sara->id . ':20000'));
        self::assertSame(self::text(BotText::WalletSendExpired), $this->popup(), 'and the step with it');
        self::assertSame(['100000.00', '0.00'], [$this->ali->balance(), $this->sara->balance()]);
    }

    public function testCancellingShowsTheWalletAgainAndSendsNothing(): void
    {
        $this->walletTransfersOn();
        $this->send($this->tap(WalletTransferHandler::PREFIX));
        $this->send($this->message((string) self::SARA));
        $this->send($this->message('30000'));

        $this->send($this->tap(MainMenu::WALLET));
        self::assertStringStartsWith('💳 موجودی کیف پول', $this->said()[0], "the wallet's screen");

        $this->send($this->tap(WalletTransferHandler::PREFIX . $this->sara->id . ':30000'));
        self::assertSame(self::text(BotText::WalletSendExpired), $this->popup());
        $this->send($this->message('30000'));
        self::assertSame(['100000.00', '0.00', 0], [$this->ali->balance(), $this->sara->balance(), WalletTransfer::query()->count()], 'no amount awaited any more');
    }

    public function testACustomerWhoMaySendNothingIsToldAtOnce(): void
    {
        $this->walletTransfersOn();
        $this->wallet($this->ali, '5000');

        $this->send($this->tap(WalletTransferHandler::PREFIX));

        self::assertSame([self::text(BotText::WalletSendRefused, ['reason' => 'برای انتقال، دست‌کم ۱۰٬۰۰۰ تومان موجودی در کیف پول لازم است.'])], $this->said());
        self::assertSame([MainMenu::WALLET], $this->callbacks(0));
        $this->send($this->message((string) self::SARA));
        self::assertSame(0, WalletTransfer::query()->count(), 'no id awaited');
    }

    /** The website's door's rule: one who may send nothing learns no customer's name, nor whether an id is anyone's. */
    public function testAnIdTypedOnceNothingMayGoSaysWhyAndEndsTheStep(): void
    {
        $this->walletTransfersOn();
        $this->send($this->tap(WalletTransferHandler::PREFIX));
        $this->wallet($this->ali, '5000');

        $this->send($this->message('9999'));

        self::assertSame([self::text(BotText::WalletSendRefused, ['reason' => 'برای انتقال، دست‌کم ۱۰٬۰۰۰ تومان موجودی در کیف پول لازم است.'])], $this->said(), 'why — not that no customer has the id');
        self::assertSame([MainMenu::WALLET], $this->callbacks(0));
        self::assertNull(TelegramSession::query()->where('chat_id', self::CHAT)->value('state'), 'no id awaited any more');
    }

    public function testAnAmountTypedOnceNothingMayGoSaysWhyAndEndsTheStep(): void
    {
        $this->walletTransfersOn();
        $this->send($this->tap(WalletTransferHandler::PREFIX));
        $this->send($this->message((string) self::SARA));
        $this->wallet($this->ali, '5000');

        $this->send($this->message('5000'));

        self::assertSame([self::text(BotText::WalletSendRefused, ['reason' => 'برای انتقال، دست‌کم ۱۰٬۰۰۰ تومان موجودی در کیف پول لازم است.'])], $this->said(), 'why, not a range from 10,000 to 5,000');
        self::assertSame([MainMenu::WALLET], $this->callbacks(0));
        self::assertNull(TelegramSession::query()->where('chat_id', self::CHAT)->value('state'), 'no amount awaited any more');

        $this->wallet($this->ali, '100000');
        $this->send($this->tap(WalletTransferHandler::PREFIX));
        $this->send($this->message((string) self::SARA));
        $this->walletTransfersOn(['wallet_transfer_enabled' => false]);
        $this->send($this->message('30000'));
        self::assertSame([self::text(BotText::WalletSendRefused, ['reason' => WalletTransfers::OFF])], $this->said(), 'switched off meanwhile: no question asked');
    }

    /**
     * An agent's shop whose selling the main shop suspended still lets its customers send each other money — no selling in
     * it —, while a top-up, an order, is refused.
     */
    public function testASuspendedAgentsShopStillSendsMoneyButTakesNoTopUp(): void
    {
        $bot = $this->agentBot();
        $this->service(AgencyStanding::class)->suspendByHand($bot, null);
        $bot->refresh();
        [$ali, $sara] = CurrentBot::run($bot, function (): array {
            $this->walletTransfersOn();

            return [$this->wallet($this->customer(), '100000'), $this->customer(['telegram_id' => self::SARA, 'first_name' => 'Sara'])];
        });

        $this->sendTo($bot, $this->tap(MainMenu::WALLET));
        self::assertSame([WalletTransferHandler::PREFIX], $this->callbacks(0), 'the transfer on the wallet, no top-up');
        $this->sendTo($bot, $this->tap(WalletTransferHandler::PREFIX));
        $this->sendTo($bot, $this->message((string) self::SARA));
        $this->sendTo($bot, $this->message('30000'));
        $this->sendTo($bot, $this->tap(WalletTransferHandler::PREFIX . $sara->id . ':30000'));

        self::assertSame(['70000.00', '30000.00'], [$ali->balance(), $sara->balance()]);
        $this->expectException(SellingSuspendedException::class);
        CurrentBot::run($bot, fn() => $this->service(OrderService::class)->openTopUp($ali, 50_000));
    }

    public function testTheShopSwitchingItOffMeanwhileSendsNothing(): void
    {
        $this->walletTransfersOn();
        $this->send($this->tap(WalletTransferHandler::PREFIX));
        $this->send($this->message((string) self::SARA));
        $this->send($this->message('30000'));
        $this->walletTransfersOn(['wallet_transfer_enabled' => false]);

        $this->send($this->tap(WalletTransferHandler::PREFIX . $this->sara->id . ':30000'));

        self::assertSame([self::text(BotText::WalletSendRefused, ['reason' => WalletTransfers::OFF])], $this->said());
        self::assertSame([MainMenu::WALLET], $this->callbacks(0));
        self::assertSame('0.00', $this->sara->balance());
    }

    public function testMoneySpentMeanwhileIsSaidAtTheConfirmation(): void
    {
        $this->walletTransfersOn();
        $this->send($this->tap(WalletTransferHandler::PREFIX));
        $this->send($this->message((string) self::SARA));
        $this->send($this->message('80000'));
        $this->wallet($this->ali, '50000');

        $this->send($this->tap(WalletTransferHandler::PREFIX . $this->sara->id . ':80000'));

        self::assertSame([self::text(BotText::WalletSendRefused, ['reason' => 'موجودی کیف پول شما (۵۰٬۰۰۰ تومان) برای این انتقال کافی نیست.'])], $this->said());
        self::assertSame('0.00', $this->sara->balance());
    }

    public function testTooManyLookupsEndTheStepWithTheWait(): void
    {
        $this->walletTransfersOn();
        $this->send($this->tap(WalletTransferHandler::PREFIX));
        for ($i = 0; $i < CustomerLookup::LOOKUPS; $i++) {
            $this->send($this->message('9999'));
        }

        $this->send($this->message((string) self::SARA));

        self::assertStringStartsWith('⚠️ انتقال موجودی الان ممکن نیست: شناسه‌های زیادی را بررسی کرده‌اید؛ ', $this->said()[0]);
        $this->send($this->message((string) self::SARA));
        self::assertNotContains(self::text(BotText::WalletSendAmount, ['name' => "Sara Ahmadi (\u{200E}@sara)", 'min' => '۱۰٬۰۰۰ تومان', 'sendable' => '۱۰۰٬۰۰۰ تومان', 'balance' => '۱۰۰٬۰۰۰ تومان']), $this->said(), 'the step is over');
    }

    public function testEveryButtonFitsTelegramsCallbackData(): void
    {
        $this->walletTransfersOn(['wallet_transfer_max' => 500_000_000, 'wallet_transfer_daily' => 0]);
        $big = $this->customer(['telegram_id' => 9_007_199_254_740_991, 'first_name' => 'Big']);
        $this->wallet($this->ali, '500000000');
        $big->forceFill(['id' => 999_999_999_999])->save();

        $this->send($this->tap(WalletTransferHandler::PREFIX));
        $this->send($this->message('9007199254740991'));
        $this->send($this->message('500000000'));

        $data = $this->callbacks(0)[1];
        self::assertLessThanOrEqual(CallbackData::MAX_BYTES, strlen($data));
        self::assertSame(WalletTransferHandler::PREFIX . '999999999999:500000000', $data);
    }
}
