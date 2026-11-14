<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Enums\ExtraKind;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Models\TestAccount;
use App\Modules\Telegram\BotSettings;
use App\Modules\Telegram\Gates\PhoneMoment;
use App\Modules\Telegram\Handlers\ExtrasHandler;
use App\Modules\Telegram\Handlers\RenewalHandler;
use App\Modules\Telegram\Handlers\SubscriptionHandler;
use App\Modules\Telegram\Handlers\TestAccountHandler;
use App\Modules\Telegram\Handlers\TopUpHandler;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Session\SessionStore;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\User;
use App\Support\Money;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\BotTestCase;

/**
 * The phone rule asked only at the moments the shop chose: the bot is open to everyone, and the number is asked — in
 * place of the checkout, nothing ordered — when a purchase, a renewal or an extra, a free test or a card-to-card payment
 * that is switched on is about to happen; the customer's own card is taken whenever it comes, and they start again from
 * the menu. Asked before everything, the bot is held back as it always was. A customer support excused, a bot admin
 * and a customer with a number are never asked.
 */
final class BotPhoneMomentsTest extends BotTestCase
{
    private User $ali;
    private Server $server;
    private Plan $plan;
    private PaymentMethod $wallet;
    private PaymentMethod $card;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-19 12:00:00');
        $this->withoutQr();
        $this->server = $this->sellingServer('آلمان', ['test_enabled' => true, 'test_traffic_bytes' => 500 * Traffic::MEGABYTE, 'test_duration_hours' => 24, 'test_on_hold' => true]);
        $this->plan = $this->plan(on: $this->server);
        $this->ali = $this->wallet($this->customer(), '500000.00');
        $this->wallet = $this->walletMethod();
        $this->card = $this->cardMethod();
    }

    public function testAskedBeforeEverythingTheBotIsHeldBackAsItWasWhateverTheMoments(): void
    {
        $this->phoneRule(BotSettings::PHONE_EVERYWHERE);

        $this->send($this->message('/start'));
        self::assertSame([self::text(BotText::PhonePrompt)], $this->said());
        self::assertSame([[['text' => self::text(BotText::PhoneButton), 'style' => 'primary', 'request_contact' => true]]], $this->markup(0)['keyboard'] ?? null);

        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->card)));
        self::assertSame([self::text(BotText::PhonePrompt)], $this->said(), 'the gate asks, as it always did');
        self::assertSame(0, Order::query()->count());
    }

    public function testAskedAtMomentsTheMenuAndTheScreensAreOpen(): void
    {
        $this->phoneRule(BotSettings::PHONE_MOMENTS, PhoneMoment::cases());

        $this->send($this->message('/start'));
        self::assertSame(self::text(BotText::Welcome, ['name' => 'Ali']), $this->said()[0]);
        self::assertNotSame([], $this->replyKeyboard(0), 'the menu, not the number\'s button');

        $this->send($this->message(Messages::MENU_BUY));
        self::assertNotContains(self::text(BotText::PhonePrompt), $this->said());
        $this->send($this->tap($this->checkoutOf($this->plan, $this->server)));
        self::assertSame([self::text(BotText::Checkout, ['plan' => 'یک‌ماهه', 'server' => 'آلمان', 'amount' => Money::format('120000')])], $this->said(), 'the checkout is shown: the number is asked as it is paid');
        $this->send($this->message('/free'));
        self::assertNotContains(self::text(BotText::PhonePrompt), $this->said(), 'nor the test screen');
    }

    public function testAskedAtMomentsTheCustomersOwnCardIsTakenWheneverItComes(): void
    {
        // The rule on, no moment chosen: the mini app's «ثبت شماره تلفن» still records the number.
        $this->phoneRule(BotSettings::PHONE_MOMENTS);
        $this->send($this->message('/start'));

        $this->send($this->contact('+989120000000', userId: 777));
        self::assertSame([self::text(BotText::PhoneNotYours)], $this->said());
        self::assertArrayHasKey('request_contact', $this->markup(0)['keyboard'][0][0] ?? []);
        self::assertNull($this->ali->refresh()->phone, 'someone else\'s number proves nothing');

        $this->send($this->contact('98 912-000 0000', userId: self::CHAT));
        self::assertSame([self::text(BotText::PhoneVerified), self::text(BotText::Welcome, ['name' => 'Ali'])], $this->said());
        self::assertNotSame([], $this->replyKeyboard(1), 'the menu replaces the button');
        self::assertSame('+989120000000', $this->ali->refresh()->phone);
    }

    public function testAPurchaseAsksInPlaceOfTheCheckoutAndOrdersNothingThenGoesOnceTheNumberIsIn(): void
    {
        $this->phoneRule(BotSettings::PHONE_MOMENTS, [PhoneMoment::Purchase]);

        $this->send($this->tap($this->checkoutOf($this->plan, $this->server)));
        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->wallet)));

        $this->assertAsked();
        self::assertSame(0, Order::query()->count(), 'nothing ordered');
        self::assertSame('500000.00', $this->ali->balance(), 'nothing paid');
        self::assertNull($this->service(SessionStore::class)->load(self::CHAT)->state(), 'the checkout is left');

        $this->send($this->contact('+989120000000', userId: self::CHAT));
        self::assertSame([self::text(BotText::PhoneVerified), self::text(BotText::Welcome, ['name' => 'Ali'])], $this->said());

        $this->send($this->tap($this->checkoutOf($this->plan, $this->server)));
        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->wallet)));
        self::assertSame(OrderStatus::Fulfilled, Order::query()->sole()->status, 'tried again, it is bought');
    }

    public function testAPurchaseIsNotAskedWhileItsMomentIsOff(): void
    {
        $this->phoneRule(BotSettings::PHONE_MOMENTS, [PhoneMoment::Renewal, PhoneMoment::Test, PhoneMoment::Card]);

        $this->send($this->tap($this->checkoutOf($this->plan, $this->server)));
        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->wallet)));

        self::assertNotContains(self::text(BotText::PhoneNeeded), $this->said());
        self::assertSame(OrderStatus::Fulfilled, Order::query()->sole()->status);
        self::assertNull($this->ali->refresh()->phone);
    }

    public function testACardToCardPaymentAsksWhileItsMomentIsOnAndTheWalletDoesNot(): void
    {
        $this->phoneRule(BotSettings::PHONE_MOMENTS, [PhoneMoment::Card]);

        $this->send($this->tap($this->checkoutOf($this->plan, $this->server)));
        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->card)));
        $this->assertAsked();
        self::assertSame([0, 0], [Order::query()->count(), Payment::query()->count()], 'no order, no card shown');

        // A wallet top-up paid by card is that moment too.
        $this->send($this->tap(TopUpHandler::checkoutCallback(100000)));
        $this->send($this->tap(CallbackData::build(TopUpHandler::checkoutCallback(100000), $this->card->id)));
        $this->assertAsked();
        self::assertSame(0, Order::query()->count());

        $this->send($this->tap($this->checkoutOf($this->plan, $this->server)));
        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->wallet)));
        self::assertNotContains(self::text(BotText::PhoneNeeded), $this->said());
        self::assertSame(OrderStatus::Fulfilled, Order::query()->sole()->status, 'the wallet is no card payment');
    }

    public function testARenewalAndAnExtraAskWhileTheRenewalsMomentIsOn(): void
    {
        $this->phoneRule(BotSettings::PHONE_MOMENTS, [PhoneMoment::Renewal]);
        $this->extrasOnSale();
        $service = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1'));

        $this->send($this->tap(SubscriptionHandler::serviceCallback($service->id, 'renew')));
        $this->send($this->tap(CallbackData::build(RenewalHandler::checkoutCallback($service->id), $this->wallet->id)));
        $this->assertAsked();

        $extra = ExtrasHandler::checkoutCallback($service->id, ExtraKind::Traffic, 10);
        $this->send($this->tap($extra));
        $this->send($this->tap(CallbackData::build($extra, $this->wallet->id)));
        $this->assertAsked();
        self::assertSame(0, Order::query()->count(), 'neither ordered');
        self::assertSame('500000.00', $this->ali->balance());

        // A purchase is no renewal.
        $this->send($this->tap($this->checkoutOf($this->plan, $this->server)));
        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->wallet)));
        self::assertSame(OrderStatus::Fulfilled, Order::query()->sole()->status);
    }

    public function testAFreeTestAsksWhileItsMomentIsOn(): void
    {
        $this->testsOn();
        $this->phoneRule(BotSettings::PHONE_MOMENTS, [PhoneMoment::Test]);

        $this->send($this->tap(TestAccountHandler::PREFIX . $this->server->id));
        $this->assertAsked();
        self::assertSame([0, 0], [Subscription::query()->count(), TestAccount::query()->count()], 'no test made, none counted');

        $this->phoneRule(BotSettings::PHONE_MOMENTS, [PhoneMoment::Purchase, PhoneMoment::Renewal, PhoneMoment::Card]);
        $this->send($this->tap(TestAccountHandler::PREFIX . $this->server->id));
        self::assertTrue(Subscription::query()->sole()->is_test, 'its moment off, the test is made');
    }

    /** @return array<string, array{string, string}> */
    public static function neverAsked(): array
    {
        return [
            'an excused customer, asked before everything' => ['excused', BotSettings::PHONE_EVERYWHERE],
            'an excused customer, asked at moments' => ['excused', BotSettings::PHONE_MOMENTS],
            'a bot admin, asked before everything' => ['admin', BotSettings::PHONE_EVERYWHERE],
            'a bot admin, asked at moments' => ['admin', BotSettings::PHONE_MOMENTS],
            'a customer with a number, asked before everything' => ['verified', BotSettings::PHONE_EVERYWHERE],
            'a customer with a number, asked at moments' => ['verified', BotSettings::PHONE_MOMENTS],
        ];
    }

    #[DataProvider('neverAsked')]
    public function testSomeAreNeverAskedAtAll(string $who, string $scope): void
    {
        $this->ali->forceFill(match ($who) {
            'excused' => ['phone_exempt' => true],
            'admin' => ['role' => UserRole::Admin],
            default => ['phone' => '+989350000000'],
        })->save();
        $this->testsOn();
        $this->phoneRule($scope, PhoneMoment::cases());

        $this->send($this->message('/start'));
        self::assertSame(self::text(BotText::Welcome, ['name' => 'Ali']), $this->said()[0]);

        $this->send($this->tap($this->checkoutOf($this->plan, $this->server)));
        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->card)));
        self::assertSame([$this->cardInstructions('120000')], $this->said(), 'the card, no ask');

        $this->send($this->tap(TestAccountHandler::PREFIX . $this->server->id));
        self::assertTrue(Subscription::query()->sole()->is_test, 'the test made');
        self::assertNotContains(self::text(BotText::PhoneNeeded), $this->said());
    }

    public function testIranianNumbersAloneRefuseAnotherCountrysWithTheButtonAgain(): void
    {
        $this->phoneRule(BotSettings::PHONE_EVERYWHERE, iranOnly: true);
        $this->send($this->message('/start'));

        $this->send($this->contact('+49 170 1234567', userId: self::CHAT));
        self::assertSame([self::text(BotText::PhoneNotIranian)], $this->said());
        self::assertArrayHasKey('request_contact', $this->markup(0)['keyboard'][0][0] ?? []);
        self::assertNull($this->ali->refresh()->phone, 'kept nowhere');

        $this->send($this->contact('989121234567', userId: self::CHAT));
        self::assertSame(self::text(BotText::PhoneVerified), $this->said()[0]);
        self::assertSame('+989121234567', $this->ali->refresh()->phone);
    }

    public function testANumberOfAnotherCountryOnFileIsAskedAgainOnceIranianOnesAloneCount(): void
    {
        $this->ali->verifyPhone('+491701234567');

        $this->phoneRule(BotSettings::PHONE_EVERYWHERE);
        $this->send($this->message('/start'));
        self::assertSame(self::text(BotText::Welcome, ['name' => 'Ali']), $this->said()[0], 'any number counts while the switch is off');

        $this->phoneRule(BotSettings::PHONE_EVERYWHERE, iranOnly: true);
        $this->send($this->message('/start'));
        self::assertSame([self::text(BotText::PhoneNotIranian)], $this->said(), 'its number said to be the trouble');

        // At a moment, the same.
        $this->phoneRule(BotSettings::PHONE_MOMENTS, [PhoneMoment::Purchase], iranOnly: true);
        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->wallet)));
        self::assertSame([self::text(BotText::PhoneNotIranian)], $this->said());
        self::assertSame(0, Order::query()->count());

        // Excused, they pass whatever their number.
        $this->ali->forceFill(['phone_exempt' => true])->save();
        $this->phoneRule(BotSettings::PHONE_EVERYWHERE, iranOnly: true);
        $this->send($this->message('/start'));
        self::assertSame(self::text(BotText::Welcome, ['name' => 'Ali']), $this->said()[0]);
    }

    /** The number asked in place of the screen tapped: that message goes, the ask comes with the button that answers it. */
    private function assertAsked(): void
    {
        self::assertSame(['deleteMessage', 'sendMessage', 'answerCallbackQuery'], $this->calls(), 'a reply keyboard comes only with a message sent');
        self::assertSame([self::text(BotText::PhoneNeeded)], $this->said());
        self::assertSame([[['text' => self::text(BotText::PhoneButton), 'style' => 'primary', 'request_contact' => true]]], $this->markup(1)['keyboard'] ?? null, 'one button that shares the number');
    }

    /**
     * The phone rule on, as the bot settings save it: asked before everything (`$scope` everywhere) or at `$moments` —
     * Iranian numbers alone counting when `$iranOnly`.
     *
     * @param list<PhoneMoment> $moments
     */
    private function phoneRule(string $scope, array $moments = [], bool $iranOnly = false): void
    {
        $values = ['enabled' => true, 'phone_required' => true, 'phone_scope' => $scope, 'phone_iran_only' => $iranOnly, 'support_contact' => ''];
        foreach (PhoneMoment::cases() as $moment) {
            $values['phone_on_' . $moment->value] = in_array($moment, $moments, true);
        }
        $this->botSettings('general', $values);
    }

    /** Free test accounts switched on: one a customer, for good, no cap a day, for everyone. */
    private function testsOn(): void
    {
        $this->botSettings('test_accounts', [
            'test_enabled' => true,
            'test_per_user' => 1,
            'test_period_days' => 0,
            'test_daily_limit' => 0,
            'test_newcomers_only' => false,
            'test_cleanup' => true,
            'test_cleanup_hours' => 24,
            'test_unused_days' => 7,
        ]);
    }
}
