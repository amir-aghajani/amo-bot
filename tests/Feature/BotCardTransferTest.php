<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Notifications\Enums\Delivery;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\ReceiptInReviewException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentSettings;
use App\Modules\Payments\Services\ReceiptsInReview;
use App\Modules\Providers\Models\Server;
use App\Modules\Telegram\Handlers\PurchaseHandler;
use App\Modules\Telegram\Handlers\ReceiptHandler;
use App\Modules\Telegram\Handlers\TopUpHandler;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Users\Models\User;
use App\Support\Money;
use Tests\BotTestCase;
use Tests\Support\FakeTelegram;

/**
 * The card-to-card message in the bot: the card's number (its bank when named) and the amount each under a button that
 * copies it (the amount as a banking app takes it: Latin digits, no separators), sent protected — no forwarding, no
 * saving — as a message of its own in place of the checkout while the shop asks so (its default; a checkout too old to
 * delete loses its buttons), edited in otherwise. While the shop takes card payments in support's private chat, no card
 * at all — none picked —: the order and its amount, the way to support (a button while the contact is a link), the
 * method's note and no receipt awaited here — the reminder sends them there too —, unless the contact was emptied since. One receipt at a time: while a
 * receipt of theirs is in review, a customer starts no other card payment and sends no other receipt — told so, and
 * asked again as a receipt is taken —, and the wallet still pays.
 */
final class BotCardTransferTest extends BotTestCase
{
    private Server $server;
    private Plan $plan;
    private User $ali;
    private PaymentMethod $card;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = $this->sellingServer();
        $this->plan = $this->plan(on: $this->server);
        $this->ali = $this->wallet($this->customer(), '500000.00');
        $this->card = $this->cardMethod();
    }

    public function testTheCardComesProtectedInPlaceOfTheCheckoutWithButtonsThatCopyItsNumberAndTheAmount(): void
    {
        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->card)));

        self::assertSame(['deleteMessage', 'sendMessage'], array_slice($this->calls(), 0, 2), 'a protected message is sent, never edited in');
        self::assertSame('true', $this->params(1)['protect_content'] ?? null);
        self::assertSame([$this->cardInstructions('120000')], $this->said());
        self::assertStringContainsString('<code>1200000</code> ریال', $this->said()[0], 'the amount copied by a tap on it too: in Rial, as the banks take it');
        self::assertStringContainsString('۱۲۰٬۰۰۰ تومان', $this->said()[0], 'the price read in Toman');
        self::assertSame([
            [
                ['text' => self::text(BotText::CardCopyAmount), 'copy_text' => ['text' => '1200000']],
                ['text' => self::text(BotText::CardCopyNumber), 'copy_text' => ['text' => '6037997700001119']],
            ],
            [['text' => self::text(BotText::Back), 'callback_data' => $this->checkoutOf($this->plan, $this->server)]],
        ], $this->inlineKeyboard(1), 'the card number first in reading order, then the amount; «بازگشت» to the checkout');

        // The chat waits for the receipt, as ever.
        $this->send($this->message(null, ['photo' => [['file_id' => 'receipt-1']]]));
        self::assertSame(PaymentStatus::AwaitingReview, Payment::query()->sole()->status);
    }

    public function testTheAmountCopiedIsWhatThePaymentAsksATopUpsRandomSumIncluded(): void
    {
        $this->botSettings('wallet', ['topup_unique_max' => 500]);

        $this->send($this->tap(CallbackData::build(TopUpHandler::checkoutCallback(100000), $this->card->id)));

        $asked = Payment::query()->sole()->amount;
        self::assertSame(1, Money::compare($asked, '100000'), 'a few Toman more than the top-up');
        $rials = Money::rials($asked);
        self::assertSame(bcmul(Money::digits($asked), '10', 0), $rials, 'ten Rial to the Toman');
        self::assertSame($rials, $this->inlineKeyboard(1)[0][0]['copy_text']['text']);
        self::assertStringContainsString("<code>{$rials}</code> ریال", $this->said()[0]);
        self::assertStringContainsString(Money::format($asked), $this->said()[0]);
    }

    public function testTheCardsBankComesUnderItsHolderWhenTheOwnerNamedIt(): void
    {
        $this->card->cards()->update(['bank' => 'ملت']);

        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->card)));

        self::assertStringContainsString("👤 به نام <b>AmoBot</b>\n🏦 بانک: ملت\n💰 مبلغ: <code>1200000</code> ریال", $this->said()[0]);
    }

    public function testACheckoutPastTelegramsTimeForDeletingLosesItsButtonsAndTheCardStillComesProtected(): void
    {
        $this->telegram()->on('deleteMessage', static fn(): mixed => FakeTelegram::error(400, 'Bad Request: message can\'t be deleted for everyone'));

        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->card)));

        self::assertSame(['deleteMessage', 'editMessageReplyMarkup', 'sendMessage'], array_slice($this->calls(), 0, 3));
        self::assertSame([], $this->inlineKeyboard(1), 'nothing left on the old checkout to tap');
        self::assertSame('true', $this->params(2)['protect_content'] ?? null);
        self::assertStringContainsString('6037997700001119', $this->said()[0]);
    }

    public function testUnprotectedTheCardIsEditedInAsBefore(): void
    {
        $this->botSettings('payments', ['card_protect' => false]);

        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->card)));

        self::assertSame('editMessageText', $this->calls()[0]);
        self::assertArrayNotHasKey('protect_content', $this->params(0));
        self::assertSame(['1200000', '6037997700001119'], array_map(static fn(array $button): string => $button['copy_text']['text'], $this->inlineKeyboard(0)[0]), 'the copy buttons all the same');
    }

    public function testInSupportsPrivateChatNoCardIsShownButTheOrderAndTheWayToSupportAndNoReceiptIsAwaited(): void
    {
        $this->botSettings('general', ['support_contact' => '@amo_support']);
        $this->botSettings('payments', ['card_to_support' => true]);

        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->card)));

        $order = Order::query()->sole();
        self::assertSame([trim(self::text(BotText::CardToSupport, [
            'order' => $order->id,
            'amount' => Money::format('120000'),
            'contact' => "\u{200E}@amo_support",
            'outcome' => self::text(BotText::ReceiptOutcomeSubscription),
            'instructions' => '',
        ]))], $this->said());
        self::assertStringNotContainsString('6037997700001119', $this->said()[0], 'no card at all');
        self::assertNull(Payment::query()->sole()->card_id, 'nor one picked for it');
        self::assertSame('editMessageText', $this->calls()[0]);
        self::assertSame([
            [['text' => self::text(BotText::CardToSupportButton), 'url' => 'https://t.me/amo_support']],
            [['text' => self::text(BotText::Back), 'callback_data' => $this->checkoutOf($this->plan, $this->server)]],
        ], $this->inlineKeyboard(0));

        // Support approves it in the panels: a picture sent here is no receipt.
        $this->send($this->message(null, ['photo' => [['file_id' => 'receipt-1']]]));
        $payment = Payment::query()->sole();
        self::assertSame([PaymentStatus::Pending, null], [$payment->status, $payment->receipt_file_id]);
    }

    public function testInSupportsPrivateChatTheMethodsNoteComesUnderTheWayThereAsOnTheWebsite(): void
    {
        $this->card->forceFill(['config' => ['instructions' => 'فقط از کارت خودتان <واریز> کنید'] + $this->card->config])->save();
        $this->botSettings('general', ['support_contact' => '@amo_support']);
        $this->botSettings('payments', ['card_to_support' => true]);

        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->card)));

        self::assertStringEndsWith("\n\nفقط از کارت خودتان &lt;واریز&gt; کنید", $this->said()[0], 'the note last, as written');
    }

    public function testASupportContactThatIsNoLinkGetsNoButton(): void
    {
        $this->botSettings('general', ['support_contact' => '+98 912 000 0000']);
        $this->botSettings('payments', ['card_to_support' => true]);

        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->card)));

        self::assertStringContainsString('+98 912 000 0000', $this->said()[0]);
        self::assertSame([[['text' => self::text(BotText::Back), 'callback_data' => $this->checkoutOf($this->plan, $this->server)]]], $this->inlineKeyboard(0), 'a phone number is no button Telegram takes');
    }

    public function testASupportContactEmptiedSinceShowsTheCardAgain(): void
    {
        $this->botSettings('general', ['support_contact' => '@amo_support']);
        $this->botSettings('payments', ['card_to_support' => true]);
        $this->botSettings('general', ['support_contact' => '']);

        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->card)));

        self::assertStringContainsString('<code>6037997700001119</code>', $this->said()[0], 'no dead end: the card as usual');
        $this->send($this->message(null, ['photo' => [['file_id' => 'receipt-1']]]));
        self::assertSame(PaymentStatus::AwaitingReview, Payment::query()->sole()->status, 'and its receipt is taken here');
    }

    public function testInSupportsPrivateChatTheReminderSendsTheCustomerThereInsteadOfAskingForTheReceiptHere(): void
    {
        $this->botSettings('general', ['support_contact' => '@amo_support']);
        $this->botSettings('payments', ['card_to_support' => true]);
        $payment = $this->cardPayment($this->purchaseOrder($this->ali, $this->plan, $this->server), $this->card, ['card_id' => null]);

        self::assertSame(Delivery::Told, $this->service(CustomerNotifier::class)->paymentReminder($payment));

        self::assertSame([self::text(BotText::PaymentReminderToSupport, [
            'order' => $payment->order_id,
            'amount' => Money::format('120000'),
            'contact' => "\u{200E}@amo_support",
        ])], $this->said());
        self::assertSame([
            [['text' => self::text(BotText::CardToSupportButton), 'url' => 'https://t.me/amo_support']],
            [['text' => self::text(BotText::PayAgain), 'callback_data' => PurchaseHandler::reopenCallback($payment->order_id)]],
        ], $this->inlineKeyboard(0), 'no «ارسال رسید» in the bot');

        // A phone number is no button: the words alone say where to go.
        $this->telegram()->reset();
        $this->botSettings('general', ['support_contact' => '+98 912 000 0000']);
        $this->service(CustomerNotifier::class)->paymentReminder($payment);
        self::assertSame([[['text' => self::text(BotText::PayAgain), 'callback_data' => PurchaseHandler::reopenCallback($payment->order_id)]]], $this->inlineKeyboard(0));
    }

    public function testTheReminderFollowsWhatThePaymentWasShownNotWhatTheShopDoesNow(): void
    {
        $this->botSettings('general', ['support_contact' => '@amo_support']);
        $shown = $this->cardPayment($this->purchaseOrder($this->ali, $this->plan, $this->server), $this->card);
        $this->botSettings('payments', ['card_to_support' => true]);

        $this->service(CustomerNotifier::class)->paymentReminder($shown);
        self::assertSame([self::text(BotText::PaymentReminder, ['order' => $shown->order_id, 'amount' => Money::format('120000')])], $this->said(), 'shown a card before the shop sent payments to its chat: its receipt is still taken here');
        self::assertSame(ReceiptHandler::stateFor($shown), $this->inlineKeyboard(0)[0][0]['callback_data'] ?? null);

        $this->telegram()->reset();
        $toSupport = $this->cardPayment($this->purchaseOrder($this->ali, $this->plan, $this->server), $this->card, ['card_id' => null]);
        $this->botSettings('payments', ['card_to_support' => false]);
        $this->service(CustomerNotifier::class)->paymentReminder($toSupport);
        self::assertSame([self::text(BotText::PaymentReminderToSupport, ['order' => $toSupport->order_id, 'amount' => Money::format('120000'), 'contact' => "\u{200E}@amo_support"])], $this->said(), 'taken in support\'s chat before the shop showed cards again: still there');
        self::assertSame('https://t.me/amo_support', $this->inlineKeyboard(0)[0][0]['url'] ?? null);
    }

    public function testAnAgentsShopSendsItsCustomersToItsOwnSupport(): void
    {
        $this->botSettings('general', ['support_contact' => '@amo_support']);
        $bot = $this->agentBot();
        $payment = CurrentBot::run($bot, function (): Payment {
            $this->botSettings('general', ['support_contact' => '@agent_support']);
            $this->botSettings('payments', ['card_to_support' => true]);

            return $this->cardPayment($this->topUpOrder($this->customer(['telegram_id' => 3001]), '50000.00'), $this->cardMethod(), ['card_id' => null]);
        });

        $this->service(CustomerNotifier::class)->paymentReminder($payment);

        self::assertStringContainsString('@agent_support', $this->said()[0]);
        self::assertSame('https://t.me/agent_support', $this->inlineKeyboard(0)[0][0]['url'] ?? null);
        self::assertFalse($this->service(PaymentSettings::class)->sendsToSupport(), 'the main shop shows its cards still');
    }

    public function testWhileAReceiptIsInReviewNoOtherCardPaymentStartsButTheWalletPays(): void
    {
        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->card)));
        $first = $this->receipt(Payment::query()->sole());

        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->card)));

        self::assertSame([self::text(BotText::ReceiptInReview, ['order' => $first->order_id])], $this->said());
        self::assertSame([$this->checkoutOf($this->plan, $this->server)], $this->callbacks(0), 'back to the checkout, to pay another way');
        self::assertSame([1, 1], [Order::query()->count(), Payment::query()->count()], 'nothing ordered');

        $this->send($this->tap($this->checkoutOf($this->plan, $this->server)));
        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->walletMethod())));
        self::assertSame(2, Order::query()->count(), 'the wallet still pays');

        // While the shop takes card payments in support's private chat, no receipt is awaited here: the card is not held back.
        $this->botSettings('general', ['support_contact' => '@amo_support']);
        $this->botSettings('payments', ['card_to_support' => true]);
        $other = $this->plan(['name' => 'دوماهه', 'price' => '200000.00'], on: $this->server);
        $this->send($this->tap($this->payButton($other, $this->server, $this->card)));
        self::assertStringContainsString('@amo_support', $this->said()[0]);
    }

    public function testWhileAReceiptIsInReviewAnotherPaymentsReceiptIsRefusedUntilTheShopTakesSeveral(): void
    {
        $inReview = $this->receipt($this->cardPayment($this->purchaseOrder($this->ali, $this->plan, $this->server), $this->card));
        $other = $this->cardPayment($this->purchaseOrder($this->ali, $this->plan, $this->server, ['amount' => '90000.00']), $this->card);

        // A reminder's «ارسال رسید» for the other payment.
        $this->send($this->tap(ReceiptHandler::stateFor($other)));
        self::assertSame([self::text(BotText::ReceiptInReview, ['order' => $inReview->order_id])], $this->said(), 'told plainly, the flow over');
        $this->send($this->message(null, ['photo' => [['file_id' => 'receipt-2']]]));
        self::assertSame(PaymentStatus::Pending, $other->refresh()->status, 'no receipt taken');

        $this->botSettings('payments', ['receipt_single' => false]);
        $this->send($this->tap(ReceiptHandler::stateFor($other)));
        $this->send($this->message(null, ['photo' => [['file_id' => 'receipt-2']]]));
        self::assertSame(PaymentStatus::AwaitingReview, $other->refresh()->status, 'several at once, once the shop takes them');
    }

    public function testOneReceiptAtATimeIsAskedAgainAsTheReceiptIsTaken(): void
    {
        $other = $this->cardPayment($this->purchaseOrder($this->ali, $this->plan, $this->server, ['amount' => '90000.00']), $this->card);
        $this->send($this->tap(ReceiptHandler::stateFor($other)));
        // Asked for this one's receipt, the customer sends another payment's from the website meanwhile.
        $inReview = $this->receipt($this->cardPayment($this->purchaseOrder($this->ali, $this->plan, $this->server), $this->card));
        $receipts = $this->service(ReceiptsInReview::class);

        try {
            $receipts->submit($this->ali, $other, static fn(): bool => self::fail('Taken while another receipt is in review.'));
            self::fail('No refusal.');
        } catch (ReceiptInReviewException $e) {
            self::assertSame($inReview->id, $e->inReview->id);
        }
        self::assertTrue($receipts->submit($this->ali, $inReview, static fn(): bool => true), 'its own receipt is no other');

        $this->send($this->message(null, ['photo' => [['file_id' => 'receipt-2']]]));
        self::assertSame([PaymentStatus::Pending, [self::text(BotText::ReceiptInReview, ['order' => $inReview->order_id])]], [$other->refresh()->status, $this->said()]);
    }

    public function testTheSettingsAreTheShopsDefaultsUntilChanged(): void
    {
        $settings = $this->service(PaymentSettings::class);

        self::assertSame([true, false, true], [$settings->protectsCard(), $settings->sendsToSupport(), $settings->oneReceiptAtATime()]);
    }
}
