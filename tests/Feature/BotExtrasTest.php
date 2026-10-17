<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Agency\Models\TrafficTransaction;
use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentActions;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Enums\ExtraKind;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ServiceTerms;
use App\Modules\Subscriptions\Services\ServiceTransfers;
use App\Modules\Telegram\Handlers\ExtrasHandler;
use App\Modules\Telegram\Handlers\MenuHandler;
use App\Modules\Telegram\Handlers\PurchaseHandler;
use App\Modules\Telegram\Handlers\RenewalHandler;
use App\Modules\Telegram\Handlers\SubscriptionHandler;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Users\Models\User;
use App\Support\Money;
use App\Support\Persian;
use App\Support\Traffic;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\BotTestCase;
use Tests\Fakes\FakeProvider;

/**
 * «حجم اضافه», «زمان اضافه» and «کاربر اضافه» in the bot: a service's screen offers each while the shop sells it for
 * the service; the kind's screen offers its amounts with their prices (devices for the days the service has left) and
 * takes one typed; the checkout says what it costs and the service as it would leave it; a way to pay picked, the extra
 * is ordered (or the open one found) and paid — the wallet adds it at once, a card once support approved its receipt. A
 * service that cannot take it gets a popup saying so and nothing is ordered; in an agent's bot their traffic holds what
 * is sold, and the extra draws it.
 */
final class BotExtrasTest extends BotTestCase
{
    /** The service's deadline as the fixture makes it — 30 days from the test's now — and 30 days on. */
    private const DEADLINE = '2026-10-19 12:00:00';
    private const LATER_DEADLINE = '2026-11-18 12:00:00';

    private User $ali;
    private Server $server;
    private Plan $plan;
    private Subscription $subscription;
    private PaymentMethod $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-19 12:00:00');
        $this->withoutQr();

        $this->server = $this->sellingServer();
        $this->plan = $this->plan(on: $this->server);
        $this->ali = $this->wallet($this->customer(['username' => 'ali']), '500000.00');
        $this->wallet = $this->walletMethod();
        // 18 GB of its 30 used, 12 left.
        $this->subscription = $this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['download_bytes' => Traffic::bytesOfGb(18)]);
        FakeProvider::mirror($this->subscription);
        $this->extrasOnSale();
    }

    public function testTheServiceScreenOffersBothRightUnderItsRenewal(): void
    {
        $this->send($this->tap($this->serviceScreen()));

        $keyboard = $this->inlineKeyboard(0);
        self::assertSame([self::text(BotText::ServiceLink), self::text(BotText::ServiceRenew)], array_column($keyboard[1], 'text'));
        self::assertSame([
            ['text' => self::text(BotText::ServiceExtraTime), 'callback_data' => $this->screen(ExtraKind::Time)],
            ['text' => self::text(BotText::ServiceExtraTraffic), 'callback_data' => $this->screen(ExtraKind::Traffic)],
        ], $keyboard[2], '«حجم اضافه» on the right, read first');

        $this->extrasOnSale(['extras_enabled' => false]);
        $this->send($this->tap($this->serviceScreen()));

        self::assertSame([], $this->extraButtons(), 'the shop sells none: nothing offered, whatever its prices');
    }

    /** @return iterable<string, array{\Closure(Subscription): void, list<ExtraKind>}> */
    public static function servicesAndWhatTheyMayTake(): iterable
    {
        yield 'running' => [static function (Subscription $service): void {}, [ExtraKind::Time, ExtraKind::Traffic]];
        yield 'its traffic unlimited' => [static function (Subscription $service): void {
            $service->forceFill(['traffic_limit_bytes' => 0])->save();
        }, [ExtraKind::Time]];
        yield 'never ending' => [static function (Subscription $service): void {
            $service->forceFill(['duration_hours' => 0, 'starts_at' => null, 'expires_at' => null])->save();
        }, [ExtraKind::Traffic]];
        yield 'its traffic used up' => [static function (Subscription $service): void {
            $service->forceFill(['download_bytes' => Traffic::bytesOfGb(30), 'status' => SubscriptionStatus::Expired])->save();
        }, [ExtraKind::Traffic]];
        yield 'its time over' => [static function (Subscription $service): void {
            $service->forceFill(['starts_at' => '2026-08-10 12:00:00', 'expires_at' => '2026-09-09 12:00:00', 'status' => SubscriptionStatus::Expired])->save();
        }, [ExtraKind::Time]];
        yield 'ended on both counts: a renewal\'s' => [static function (Subscription $service): void {
            $service->forceFill(['expires_at' => '2026-09-09 12:00:00', 'download_bytes' => Traffic::bytesOfGb(30), 'status' => SubscriptionStatus::Expired])->save();
        }, []];
        yield 'switched off by support' => [static function (Subscription $service): void {
            $service->forceFill(['status' => SubscriptionStatus::Disabled])->save();
        }, []];
    }

    /**
     * @param \Closure(Subscription): void $make
     * @param list<ExtraKind> $takes
     */
    #[DataProvider('servicesAndWhatTheyMayTake')]
    public function testEachIsOfferedWhileTheServiceMayTakeIt(\Closure $make, array $takes): void
    {
        $make($this->subscription);
        FakeProvider::mirror($this->subscription, enabled: $this->subscription->status === SubscriptionStatus::Active);

        $this->send($this->tap($this->serviceScreen()));
        self::assertSame(array_map(fn(ExtraKind $kind): string => $this->screen($kind), $takes), $this->extraButtons());

        foreach (array_filter(ExtraKind::cases(), static fn(ExtraKind $kind): bool => !in_array($kind, $takes, true)) as $kind) {
            $this->send($this->tap($this->screen($kind)));
            self::assertSame(['answerCallbackQuery'], $this->calls(), "{$kind->value}: a button kept from before changes nothing");
            self::assertSame([self::text(BotText::ExtraUnavailable), 'true'], [$this->popup(), $this->params(0)['show_alert'] ?? null]);
        }
    }

    public function testTheTrafficScreenOffersItsAmountsAndAwaitsAnyTyped(): void
    {
        $this->send($this->tap($this->screen(ExtraKind::Traffic)));

        self::assertSame(['editMessageText', 'answerCallbackQuery'], $this->calls(), 'in place of the service screen');
        self::assertSame([self::text(BotText::ExtraTrafficMenu, [
            'client' => 'ali_1',
            'remaining' => Messages::bytes(Traffic::bytesOfGb(12)),
            'expires' => $this->expiry(),
            'typed' => self::text(BotText::ExtraTrafficTyped, ['least' => Persian::number(1), 'most' => Persian::number(100), 'unit_price' => Money::format('2000')]),
        ])], $this->said());
        self::assertSame([
            [$this->option(ExtraKind::Traffic, 50, '100000'), $this->option(ExtraKind::Traffic, 10, '18000')],
            [['text' => self::text(BotText::Back), 'callback_data' => $this->serviceScreen()]],
        ], $this->inlineKeyboard(0), 'its amounts two a row, the first on the right, each at its price — 50 GB at the price of a GB —, and back to the service');
    }

    public function testASaleTakesOffTheAmountsAsTheirCheckoutCharges(): void
    {
        $this->sale(percent: 20);

        $this->send($this->tap($this->screen(ExtraKind::Traffic)));
        self::assertSame([$this->option(ExtraKind::Traffic, 50, '80000'), $this->option(ExtraKind::Traffic, 10, '14400')], $this->inlineKeyboard(0)[0], '20% off each, by the one rule of an extra\'s price');
        self::assertStringContainsString(self::text(BotText::ExtraTrafficTyped, ['least' => Persian::number(1), 'most' => Persian::number(100), 'unit_price' => Money::format('1600')]), $this->said()[0], 'and off the price of a GB typed, as beside it');

        $this->send($this->tap($this->checkout(ExtraKind::Traffic, 10)));
        self::assertStringStartsWith($this->trafficCheckout(10, '14400', 22), $this->said()[0], 'what its button said');
    }

    public function testATypedAmountOpensItsCheckoutAsANewMessage(): void
    {
        $this->send($this->tap($this->screen(ExtraKind::Traffic)));

        $this->send($this->message('۱۵ گیگ'));

        self::assertSame(['sendMessage'], $this->calls(), 'under what the customer typed');
        self::assertSame([$this->trafficCheckout(15, '30000', 27)], $this->said(), '15 GB at 2,000 a GB, on top of the 12 GB left');
        self::assertSame([$this->pay(ExtraKind::Traffic, 15, $this->wallet), $this->screen(ExtraKind::Traffic)], $this->callbacks(0), 'a button per way to pay, and back to the amounts');
        self::assertSame(0, Order::query()->count(), 'nothing is ordered before a way to pay is picked');
    }

    public function testAnAmountTypedThatIsNotSoldIsSaidSoAndAnotherAwaited(): void
    {
        $this->send($this->tap($this->screen(ExtraKind::Traffic)));

        foreach (['۵۰۰', 'سلام', '0', '۵۰ روز', '۵۰ کاربر'] as $typed) {
            $this->send($this->message($typed));
            self::assertSame([self::text(BotText::ExtraInvalid, ['least' => Persian::number(1), 'most' => Persian::number(100)])], $this->said(), $typed . ' (GB are awaited, in no other unit)');
            self::assertSame([$this->screen(ExtraKind::Traffic)], $this->callbacks(0), 'back to the amounts');
        }
        self::assertSame(0, Order::query()->count());

        $this->send($this->message('50'));
        self::assertSame([$this->trafficCheckout(50, '100000', 62)], $this->said(), 'an amount offered, typed, is that amount');
    }

    public function testBackOnTheServiceScreenNoAmountIsAwaited(): void
    {
        $this->send($this->tap($this->screen(ExtraKind::Traffic)));
        $this->send($this->tap($this->serviceScreen()));

        $this->send($this->message('20'));

        self::assertSame([self::text(BotText::Unknown)], $this->said());
        self::assertSame(0, Order::query()->count());
    }

    public function testTheWalletAddsTheTrafficAtOnce(): void
    {
        $this->send($this->tap($this->checkout(ExtraKind::Traffic, 10)));
        self::assertSame([$this->trafficCheckout(10, '18000', 22)], $this->said(), 'the 10 GB at the price of their own');

        $this->send($this->tap($this->pay(ExtraKind::Traffic, 10, $this->wallet)));

        self::assertSame(['deleteMessage', 'sendMessage', 'answerCallbackQuery'], $this->calls(), 'the checkout goes; what it added arrives as a new message');
        self::assertSame([self::text(BotText::ExtraTrafficAdded, [
            'extra_traffic' => ExtraKind::Traffic->words(10),
            'client' => 'ali_1',
            'remaining' => Messages::bytes(Traffic::bytesOfGb(22)),
            'expires' => $this->expiry(),
        ])], $this->said(), 'the numbers the checkout showed');
        self::assertSame([[['text' => self::text(BotText::ReminderOpenService), 'callback_data' => $this->serviceScreen()]]], $this->inlineKeyboard(1), 'the way to the service');

        $order = Order::query()->sole();
        self::assertSame([OrderType::ExtraTraffic, OrderStatus::Fulfilled, $this->subscription->id, Traffic::bytesOfGb(10), null, '18000.00'], [$order->type, $order->status, $order->subscription_id, $order->traffic_bytes, $order->days, $order->amount]);
        self::assertSame('482000.00', $this->ali->balance());
        $spec = FakeProvider::lastUpdate('ali_1');
        self::assertSame([Traffic::bytesOfGb(40), self::DEADLINE], [$spec->totalBytes, $spec->expiry->deadline()?->format('Y-m-d H:i:s')], 'on its panel: 10 GB on top of its 30, its end as it was');
        self::assertSame(Traffic::bytesOfGb(40), $this->subscription->refresh()->traffic_limit_bytes);
    }

    public function testTheSameWalletButtonTwiceAddsOnce(): void
    {
        $this->send($this->tap($this->checkout(ExtraKind::Traffic, 10)));
        $this->send($this->tap($this->pay(ExtraKind::Traffic, 10, $this->wallet)));

        $this->send($this->tap($this->pay(ExtraKind::Traffic, 10, $this->wallet)));

        self::assertSame([self::text(BotText::OrderNotPending)], $this->said(), 'the checkout was paid by the first tap');
        self::assertSame([$this->checkout(ExtraKind::Traffic, 10)], $this->callbacks(0), 'with the way back to a fresh checkout');
        self::assertSame(1, Order::query()->count());
        self::assertSame(['ali_1'], FakeProvider::updatedNames(), 'added once');
        self::assertSame('482000.00', $this->ali->balance());
    }

    public function testAServiceWhoseTrafficRanOutRunsAgainWithIt(): void
    {
        $this->subscription->forceFill(['download_bytes' => Traffic::bytesOfGb(30), 'status' => SubscriptionStatus::Expired])->save();
        FakeProvider::mirror($this->subscription, enabled: false);

        $this->send($this->tap($this->checkout(ExtraKind::Traffic, 10)));
        self::assertSame([$this->trafficCheckout(10, '18000', 10)], $this->said(), 'counted from what it used: the 10 GB bought is what it will have');

        $this->send($this->tap($this->pay(ExtraKind::Traffic, 10, $this->wallet)));

        $subscription = $this->subscription->refresh();
        self::assertSame([SubscriptionStatus::Active, Traffic::bytesOfGb(40), Traffic::bytesOfGb(10)], [$subscription->status, $subscription->traffic_limit_bytes, $subscription->remainingBytes()], 'running again');
        self::assertTrue(FakeProvider::$clients[$this->server->id]['ali_1']->enabled, 'its client switched on again');
    }

    public function testAServiceWhoseTrafficRanOutIsOfferedItsExtraOnTheRenewalTheMenusListOpens(): void
    {
        $this->subscription->forceFill(['download_bytes' => Traffic::bytesOfGb(30), 'status' => SubscriptionStatus::Expired])->save();
        FakeProvider::mirror($this->subscription, enabled: false);

        // «سرویس‌های من» lists the running services alone: «♻️ تمدید سرویس»'s list is the way to an ended one.
        $this->send($this->tap(RenewalHandler::checkoutCallback($this->subscription->id, 1)));

        self::assertSame([$this->screen(ExtraKind::Traffic)], $this->extraButtons(), 'its traffic ran out: more traffic keeps it running, instead of a renewal');
        self::assertSame(MenuHandler::renewalsCallback(1), $this->callbacks(0)[array_key_last($this->callbacks(0))], '«بازگشت» to the list, last');

        $this->send($this->tap(RenewalHandler::checkoutCallback($this->subscription->id)));
        self::assertSame([], $this->extraButtons(), "from the service's own screen, which offers them itself");
    }

    public function testTimeForAServiceWhoseTimeRanOutCountsFromItsNextConnection(): void
    {
        $ended = $this->subscription($this->ali, $this->plan, $this->server, 'ali_2', [
            'status' => SubscriptionStatus::Expired,
            'starts_at' => '2026-08-10 12:00:00',
            'expires_at' => '2026-09-09 12:00:00',
            'download_bytes' => Traffic::bytesOfGb(5),
        ]);
        FakeProvider::mirror($ended, enabled: false);

        $this->send($this->tap(ExtrasHandler::screenCallback($ended->id, ExtraKind::Time)));
        self::assertSame([self::text(BotText::ExtraTimeMenu, [
            'client' => 'ali_2',
            'expires' => Messages::expiry(Carbon::parse('2026-09-09 12:00:00'), 30 * 24),
            'remaining' => Messages::bytes(Traffic::bytesOfGb(25)),
            'restart' => self::text(BotText::ExtraTimeRestart),
            'typed' => self::text(BotText::ExtraTimeTyped, ['least' => Persian::number(1), 'most' => Persian::number(60), 'unit_price' => Money::format('1000')]),
        ])], $this->said(), 'its days will count from its next connection');
        self::assertSame([
            ExtrasHandler::checkoutCallback($ended->id, ExtraKind::Time, 30),
            ExtrasHandler::checkoutCallback($ended->id, ExtraKind::Time, 7),
            SubscriptionHandler::serviceCallback($ended->id),
        ], $this->callbacks(0));

        $checkout = ExtrasHandler::checkoutCallback($ended->id, ExtraKind::Time, 7);
        $this->send($this->tap($checkout));
        self::assertSame([self::text(BotText::ExtraTimeCheckout, [
            'client' => 'ali_2',
            'extra_time' => ExtraKind::Time->words(7),
            'amount' => Money::format('7000'),
            'expires' => Messages::expiry(null, 7 * 24),
            'remaining' => Messages::bytes(Traffic::bytesOfGb(25)),
        ])], $this->said(), '7 days at the price of a day, from its next connection');

        $this->send($this->tap(CallbackData::build($checkout, $this->wallet->id)));

        self::assertSame([self::text(BotText::ExtraTimeAdded, [
            'extra_time' => ExtraKind::Time->words(7),
            'client' => 'ali_2',
            'expires' => Messages::expiry(null, 7 * 24),
            'remaining' => Messages::bytes(Traffic::bytesOfGb(25)),
        ])], $this->said());
        $ended->refresh();
        self::assertSame([SubscriptionStatus::Active, null, 7 * 24], [$ended->status, $ended->expires_at, $ended->duration_hours], 'running, its clock waiting for its next connection');
        self::assertSame(7 * ServiceTerms::DAY, FakeProvider::lastUpdate('ali_2')->expiry->pendingSeconds());
        self::assertSame('493000.00', $this->ali->balance());
    }

    public function testACardWaitsForItsReceiptAndSupportsApprovalAddsTheTime(): void
    {
        $card = $this->cardMethod();
        $this->send($this->tap($this->checkout(ExtraKind::Time, 30)));
        self::assertSame([self::text(BotText::ExtraTimeCheckout, [
            'client' => 'ali_1',
            'extra_time' => ExtraKind::Time->words(30),
            'amount' => Money::format('25000'),
            'expires' => Messages::expiry(Carbon::parse(self::LATER_DEADLINE), 60 * 24),
            'remaining' => Messages::bytes(Traffic::bytesOfGb(12)),
        ])], $this->said(), 'the 30 days on top of its end, at the price of their own');
        self::assertSame([$this->pay(ExtraKind::Time, 30, $this->wallet), $this->pay(ExtraKind::Time, 30, $card), $this->screen(ExtraKind::Time)], $this->callbacks(0));

        $this->send($this->tap($this->pay(ExtraKind::Time, 30, $card)));
        self::assertSame([$this->cardInstructions('25000')], $this->said());
        $order = Order::query()->sole();
        self::assertSame([OrderType::ExtraTime, OrderStatus::Pending, 30], [$order->type, $order->status, $order->days]);

        $this->send($this->message(null, ['message_id' => 4321, 'photo' => [['file_id' => 'receipt-1']]]));
        self::assertSame([self::text(BotText::ReceiptReceived, ['outcome' => self::text(BotText::ReceiptOutcomeExtraTime)])], $this->said(), 'the receipt brings time for the service');

        $this->telegram()->reset();
        $this->service(PaymentActions::class)->approve(Payment::query()->sole(), $this->panelActor());

        self::assertSame(OrderStatus::Fulfilled, $order->refresh()->status);
        self::assertSame([self::text(BotText::ExtraTimeAdded, [
            'extra_time' => ExtraKind::Time->words(30),
            'client' => 'ali_1',
            'expires' => Messages::expiry(Carbon::parse(self::LATER_DEADLINE), 60 * 24),
            'remaining' => Messages::bytes(Traffic::bytesOfGb(12)),
        ])], $this->telegram()->sentTo(self::CHAT), "support's approval brings it");
        self::assertSame(4321, $this->telegram()->replyTarget(0), 'as a reply to the receipt');
        self::assertSame(self::LATER_DEADLINE, $this->subscription->refresh()->expires_at?->format('Y-m-d H:i:s'));
        self::assertSame('500000.00', $this->ali->balance(), 'the wallet untouched');
    }

    public function testAButtonKeptFromBeforeOfAServiceThatCannotTakeItAnyMoreIsSaidSoInAPopup(): void
    {
        $this->send($this->tap($this->checkout(ExtraKind::Traffic, 10)));
        $this->subscription->forceFill(['status' => SubscriptionStatus::Disabled])->save();

        foreach ([$this->pay(ExtraKind::Traffic, 10, $this->wallet), $this->checkout(ExtraKind::Traffic, 10), $this->screen(ExtraKind::Traffic)] as $button) {
            $this->send($this->tap($button));

            self::assertSame(['answerCallbackQuery'], $this->calls(), "{$button}: the message stays as it is");
            self::assertSame([self::text(BotText::ExtraUnavailable), 'true'], [$this->popup(), $this->params(0)['show_alert'] ?? null]);
        }
        self::assertSame([0, '500000.00'], [Order::query()->count(), $this->ali->balance()], 'nothing ordered, nothing charged');
    }

    public function testAnAmountTypedForAServiceThatCannotTakeItAnyMoreIsSaidSo(): void
    {
        $this->send($this->tap($this->screen(ExtraKind::Traffic)));
        $this->subscription->forceFill(['status' => SubscriptionStatus::Disabled])->save();

        $this->send($this->message('20'));

        self::assertSame([self::text(BotText::ExtraUnavailable)], $this->said(), 'no button to answer: in a message');
        self::assertSame([$this->serviceScreen()], $this->callbacks(0), 'with the way back to the service');

        $this->send($this->message('20'));
        self::assertSame([self::text(BotText::Unknown)], $this->said(), 'no amount awaited any more');
    }

    public function testAnAmountNoLongerSoldShowsTheAmountsAsTheyStand(): void
    {
        $this->send($this->tap($this->checkout(ExtraKind::Traffic, 50)));
        $this->extrasOnSale(['extra_traffic_price' => null, 'extra_traffic_options' => [['quantity' => 10, 'price' => '18000']]]);

        $this->send($this->tap($this->pay(ExtraKind::Traffic, 50, $this->wallet)));

        self::assertSame(['editMessageText', 'answerCallbackQuery'], $this->calls(), 'in place of the checkout');
        self::assertSame([$this->checkout(ExtraKind::Traffic, 10), $this->serviceScreen()], $this->callbacks(0), 'the 10 GB the shop still sells');
        self::assertSame(0, Order::query()->count());
    }

    public function testAPlansOwnPricesStandInForTheShops(): void
    {
        $this->plan->forceFill(['extras' => ['traffic_options' => [['quantity' => 5, 'price' => '9000']]]])->save();

        $this->send($this->tap($this->serviceScreen()));
        self::assertSame([$this->screen(ExtraKind::Traffic)], $this->extraButtons(), 'its plan prices no time: none is offered, whatever the shop\'s prices');

        $this->send($this->tap($this->screen(ExtraKind::Traffic)));
        self::assertSame([self::text(BotText::ExtraTrafficMenu, [
            'client' => 'ali_1',
            'remaining' => Messages::bytes(Traffic::bytesOfGb(12)),
            'expires' => $this->expiry(),
            'typed' => '',
        ])], $this->said(), 'no price for a GB: no amount to type');
        self::assertSame([[$this->option(ExtraKind::Traffic, 5, '9000')], [['text' => self::text(BotText::Back), 'callback_data' => $this->serviceScreen()]]], $this->inlineKeyboard(0));

        $this->send($this->message('5'));
        self::assertSame([self::text(BotText::Unknown)], $this->said(), 'nothing typed is awaited');
    }

    public function testInAnAgentsBotNoMoreTrafficIsSoldThanTheirsAndTheExtraDrawsIt(): void
    {
        $bot = $this->agentBot(traffic: 15);
        [$service, $wallet] = CurrentBot::run($bot, function (): array {
            $this->withoutQr();
            $this->extrasOnSale();
            $plan = $this->plan(['price' => '90000'], $this->server);
            $service = $this->subscription($this->wallet($this->customer(), '200000.00'), $plan, $this->server, 'reza_1', ['download_bytes' => Traffic::bytesOfGb(18)]);
            FakeProvider::mirror($service);

            return [$service, $this->walletMethod()];
        });

        $this->sendTo($bot, $this->tap(ExtrasHandler::screenCallback($service->id, ExtraKind::Traffic)));
        self::assertSame([self::text(BotText::ExtraTrafficMenu, [
            'client' => 'reza_1',
            'remaining' => Messages::bytes(Traffic::bytesOfGb(12)),
            'expires' => $this->expiry(),
            'typed' => self::text(BotText::ExtraTrafficTyped, ['least' => Persian::number(1), 'most' => Persian::number(15), 'unit_price' => Money::format('2000')]),
        ])], $this->said(), 'no more typed than the 15 GB the agent has');
        self::assertSame([ExtrasHandler::checkoutCallback($service->id, ExtraKind::Traffic, 10), SubscriptionHandler::serviceCallback($service->id)], $this->callbacks(0), 'nor the 50 GB offered');

        $this->sendTo($bot, $this->message('۱۶'));
        self::assertSame([self::text(BotText::ExtraInvalid, ['least' => Persian::number(1), 'most' => Persian::number(15)])], $this->said());

        $checkout = ExtrasHandler::checkoutCallback($service->id, ExtraKind::Traffic, 10);
        $this->sendTo($bot, $this->tap($checkout));
        $this->sendTo($bot, $this->tap(CallbackData::build($checkout, $wallet->id)));

        $order = CurrentBot::run($bot, static fn(): Order => Order::query()->sole());
        self::assertSame(OrderStatus::Fulfilled, $order->status);
        self::assertSame(Traffic::bytesOfGb(5), $bot->trafficBalance(), 'its 10 GB out of the 15');
        self::assertSame(-Traffic::bytesOfGb(10), TrafficTransaction::query()->where('order_id', $order->id)->sole()->bytes);

        // Nothing left to sell: no «حجم اضافه» at all — days cost the agent nothing, and still sell.
        $this->traffic($bot, 0);
        $this->sendTo($bot, $this->tap(SubscriptionHandler::serviceCallback($service->id)));
        self::assertSame([ExtrasHandler::screenCallback($service->id, ExtraKind::Time)], $this->extraButtons());
    }

    public function testTheDevicesScreenPricesThemForTheDaysLeftAndTheWalletAddsThemAtOnce(): void
    {
        $this->extraDevicesOnSale();
        // Half its term left: 15 of 30 days.
        $this->subscription->forceFill(['starts_at' => now()->subDays(15), 'expires_at' => now()->addDays(15)])->save();
        FakeProvider::mirror($this->subscription);
        $expires = Messages::expiry(now()->addDays(15), 30 * 24);

        $this->send($this->tap($this->serviceScreen()));
        self::assertSame([$this->screen(ExtraKind::Devices), $this->screen(ExtraKind::Time), $this->screen(ExtraKind::Traffic)], $this->extraButtons(), '«کاربر اضافه» beside the two, «حجم اضافه» still on the right');

        $this->send($this->tap($this->screen(ExtraKind::Devices)));
        self::assertSame([self::text(BotText::ExtraDevicesMenu, [
            'client' => 'ali_1',
            'devices' => ExtraKind::Devices->words(1),
            'expires' => $expires,
            'days_left' => ExtraKind::Time->words(15),
            'typed' => self::text(BotText::ExtraDevicesTyped, ['least' => Persian::number(1), 'most' => Persian::number(5), 'unit_price' => Money::format('15000')]),
        ])], $this->said());
        self::assertSame([
            [$this->option(ExtraKind::Devices, 2, '25000'), $this->option(ExtraKind::Devices, 1, '15000')],
            [['text' => self::text(BotText::Back), 'callback_data' => $this->serviceScreen()]],
        ], $this->inlineKeyboard(0), "half the 30,000 of a device, half the two's own 50,000");

        $this->send($this->message('۳ کاربر'));
        self::assertSame([self::text(BotText::ExtraDevicesCheckout, [
            'client' => 'ali_1',
            'extra_devices' => ExtraKind::Devices->words(3),
            'amount' => Money::format('45000'),
            'days_left' => ExtraKind::Time->words(15),
            'devices' => ExtraKind::Devices->words(4),
            'expires' => $expires,
        ])], $this->said(), 'three typed: 90,000 for 30 days, half of it');
        self::assertSame([$this->pay(ExtraKind::Devices, 3, $this->wallet), $this->screen(ExtraKind::Devices)], $this->callbacks(0));

        $this->send($this->tap($this->pay(ExtraKind::Devices, 3, $this->wallet)));

        self::assertSame([self::text(BotText::ExtraDevicesAdded, [
            'extra_devices' => ExtraKind::Devices->words(3),
            'client' => 'ali_1',
            'devices' => ExtraKind::Devices->words(4),
            'expires' => $expires,
        ])], $this->said(), 'the numbers the checkout showed');
        $order = Order::query()->sole();
        self::assertSame([OrderType::ExtraDevices, OrderStatus::Fulfilled, 3, null, null, '45000.00'], [$order->type, $order->status, $order->devices, $order->traffic_bytes, $order->days, $order->amount]);
        self::assertSame([4, 4], [FakeProvider::lastUpdate('ali_1')->ipLimit, $this->subscription->refresh()->ip_limit], 'on its panel and its row');
        self::assertSame('455000.00', $this->ali->balance());
    }

    public function testACardsReceiptForDevicesSaysWhatItBrings(): void
    {
        $this->extraDevicesOnSale();
        $card = $this->cardMethod();

        $this->send($this->tap($this->checkout(ExtraKind::Devices, 2)));
        $this->send($this->tap($this->pay(ExtraKind::Devices, 2, $card)));
        $this->send($this->message(null, ['message_id' => 4321, 'photo' => [['file_id' => 'receipt-1']]]));

        self::assertSame([self::text(BotText::ReceiptReceived, ['outcome' => self::text(BotText::ReceiptOutcomeExtraDevices)])], $this->said());
        self::assertSame([OrderType::ExtraDevices, OrderStatus::Pending, '50000.00'], [Order::query()->sole()->type, Order::query()->sole()->status, Order::query()->sole()->amount], 'all 30 days left: the full price');
    }

    public function testAServiceOfUnlimitedDevicesIsOfferedNone(): void
    {
        $this->extraDevicesOnSale();
        $this->subscription->forceFill(['ip_limit' => 0])->save();
        $this->mirrored($this->subscription);

        $this->send($this->tap($this->serviceScreen()));
        self::assertSame([$this->screen(ExtraKind::Time), $this->screen(ExtraKind::Traffic)], $this->extraButtons());

        $this->send($this->tap($this->screen(ExtraKind::Devices)));
        self::assertSame([self::text(BotText::ExtraUnavailable), 'true'], [$this->popup(), $this->params(0)['show_alert'] ?? null], 'a button kept from before says so');
        self::assertSame(0, Order::query()->count());
    }

    public function testTheRemindersPayAgainOfAnExtraOpensItsCheckout(): void
    {
        $order = $this->service(OrderService::class)->openExtra($this->ali, $this->subscription, ExtraKind::Traffic, 10, '18000');
        $this->cardPayment($order, $this->cardMethod());

        $this->send($this->tap(PurchaseHandler::reopenCallback($order->id)));
        self::assertSame([$this->trafficCheckout(10, '18000', 22)], $this->said(), "the extra's checkout, as much again");

        $this->send($this->tap($this->pay(ExtraKind::Traffic, 10, $this->wallet)));
        self::assertSame(OrderStatus::Fulfilled, $order->refresh()->status, 'paying it found that very order');
        self::assertSame(1, Order::query()->count());
    }

    public function testTheRemindersPayAgainOfAnExtraWhoseServiceWentSaysSo(): void
    {
        $order = $this->service(OrderService::class)->openExtra($this->ali, $this->subscription, ExtraKind::Time, 7, '7000');
        $this->subscription->forceFill(['status' => SubscriptionStatus::Deleted])->save();

        $this->send($this->tap(PurchaseHandler::reopenCallback($order->id)));

        self::assertSame([self::text(BotText::ServiceNotFound)], $this->said());
        self::assertSame([MenuHandler::subscriptionsCallback(1)], $this->callbacks(0));
    }

    public function testAServiceHandedOnBetweenItsCheckoutAndTheTapIsSaidSoAndNothingIsOrdered(): void
    {
        $this->send($this->tap($this->checkout(ExtraKind::Traffic, 10)));
        $sara = $this->customer(['telegram_id' => 9999, 'first_name' => 'Sara']);

        $this->sendWhileTheServiceMovesOn($this->tap($this->pay(ExtraKind::Traffic, 10, $this->wallet)), $sara);

        self::assertSame([ServiceTransfers::MOVED_ON], $this->said(), 'not the error every failure gets');
        self::assertSame([MenuHandler::subscriptionsCallback(1)], $this->callbacks(0));
        self::assertSame([0, '500000.00'], [Order::query()->count(), $this->ali->balance()], 'nothing ordered, nothing charged');
    }

    public function testSomeoneElsesServiceIsNotFound(): void
    {
        $theirs = $this->subscription($this->customer(['telegram_id' => 9999]), $this->plan, $this->server, 'reza_1');

        $this->send($this->tap(ExtrasHandler::screenCallback($theirs->id, ExtraKind::Traffic)));

        self::assertSame([self::text(BotText::ServiceNotFound)], $this->said());
        self::assertSame([MenuHandler::subscriptionsCallback(1)], $this->callbacks(0));
    }

    public function testTheServiceScreenAsksTheDatabaseNothingMoreForItsExtras(): void
    {
        $this->extrasOnSale(['extras_enabled' => false]);
        $this->send($this->tap($this->serviceScreen()));
        $without = $this->queriesOf(fn() => $this->send($this->tap($this->serviceScreen())));

        $this->extrasOnSale();
        $with = $this->queriesOf(fn() => $this->send($this->tap($this->serviceScreen())));

        self::assertNotSame([], $this->extraButtons());
        self::assertCount(count($without), $with, "the shop's prices are settings, read once a process; the plan is the screen's own:\n" . implode("\n", $with));
    }

    /** The service screen of ali_1. */
    private function serviceScreen(): string
    {
        return SubscriptionHandler::serviceCallback($this->subscription->id);
    }

    /** The kind's screen of ali_1. */
    private function screen(ExtraKind $kind): string
    {
        return ExtrasHandler::screenCallback($this->subscription->id, $kind);
    }

    /** The checkout of that much of the kind for ali_1. */
    private function checkout(ExtraKind $kind, int $quantity): string
    {
        return ExtrasHandler::checkoutCallback($this->subscription->id, $kind, $quantity);
    }

    /** That checkout's button that pays with the method. */
    private function pay(ExtraKind $kind, int $quantity, PaymentMethod $method): string
    {
        return CallbackData::build($this->checkout($kind, $quantity), $method->id);
    }

    /**
     * An amount's button on the kind's screen of ali_1.
     *
     * @return array<string, string>
     */
    private function option(ExtraKind $kind, int $quantity, string $price): array
    {
        return ['text' => self::text(BotText::ExtraOption, ['extra' => $kind->words($quantity), 'amount' => Money::format($price)]), 'callback_data' => $this->checkout($kind, $quantity)];
    }

    /** The checkout of `$gb` GB for ali_1 at `$price`, the service left with `$remaining` GB — its end as it is. */
    private function trafficCheckout(int $gb, string $price, int $remaining): string
    {
        return self::text(BotText::ExtraTrafficCheckout, [
            'client' => 'ali_1',
            'extra_traffic' => ExtraKind::Traffic->words($gb),
            'amount' => Money::format($price),
            'remaining' => Messages::bytes(Traffic::bytesOfGb($remaining)),
            'expires' => $this->expiry(),
        ]);
    }

    /** The end of a service the fixture made today, as the bot says it: 30 days to its deadline. */
    private function expiry(): string
    {
        return Messages::expiry(Carbon::parse(self::DEADLINE), 30 * 24);
    }

    /** @return list<string> The extras' buttons of the screen last drawn, in their row's order. */
    private function extraButtons(): array
    {
        return array_values(array_filter($this->callbacks(0), static fn(string $data): bool => str_starts_with($data, ExtrasHandler::PREFIX)));
    }

    /**
     * @param \Closure(): mixed $work
     * @return list<string> The statements `$work` ran
     */
    private function queriesOf(\Closure $work): array
    {
        // The change feed counts what came before at the next statement: that one is not the work's.
        $this->db()->select('select 1');
        $queries = [];
        $this->whileListening(QueryExecuted::class, static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        }, $work);

        return $queries;
    }
}
