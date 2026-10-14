<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentActions;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Enums\RenewalMethod;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Telegram\Handlers\CustomServiceHandler;
use App\Modules\Telegram\Handlers\DiscountCodeHandler;
use App\Modules\Telegram\Handlers\PurchaseHandler;
use App\Modules\Telegram\Handlers\RenewalHandler;
use App\Modules\Telegram\Handlers\SubscriptionHandler;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\Topic;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Users\Models\User;
use App\Support\Money;
use App\Support\Persian;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Tests\BotTestCase;
use Tests\Fakes\FakeProvider;

/**
 * «🛠 تمدید با سرویس دلخواه» in the bot: a service without a plan renewed with a package its customer builds — the button
 * under the plans of its renewal (alone when none is on sale on its server), the builder's GB and days (buttons, or typed
 * and refused outside the bounds), then the renewal's own checkout: its window, its methods, a discount code. Paid, the
 * renewal is an order without a plan carrying its GB, its days and the builder's devices, delivered and reported as
 * every renewal and refunded as one; the service keeps having no plan, so «تمدید خودکار» is never offered for it.
 */
final class BotCustomRenewalTest extends BotTestCase
{
    /** The service's deadline as the fixture makes it — 30 days from the test's now — and its renewal's, 30 days on. */
    private const DEADLINE = '2026-10-19 12:00:00';
    private const RENEWED_DEADLINE = '2026-11-18 12:00:00';

    private User $ali;
    private Server $germany;
    private Subscription $built;
    private PaymentMethod $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-19 12:00:00');
        $this->withoutQr();
        $this->germany = $this->sellingServer('آلمان');
        $this->customServicesOn([$this->germany], ['custom_devices' => 3]);
        $this->ali = $this->wallet($this->customer(), '500000.00');
        $this->wallet = $this->walletMethod();
        // A service ali built: 50 GB for 30 days on 2 devices, 18 GB of it used.
        $this->built = $this->mirrored($this->subscription($this->ali, $this->plan(), $this->germany, 'ali_1', [
            'plan_id' => null,
            'traffic_limit_bytes' => Traffic::bytesOfGb(50),
            'download_bytes' => Traffic::bytesOfGb(18),
            'ip_limit' => 2,
        ]));
    }

    public function testItsRenewalOffersTheBuilderUnderThePlansAndAloneWhenNoneIsOnSale(): void
    {
        $this->send($this->tap(SubscriptionHandler::serviceCallback($this->built->id, 'renew')));
        self::assertSame([self::text(BotText::RenewPickPlan, ['client' => 'ali_1'])], $this->said());
        self::assertSame([[$this->build()], [SubscriptionHandler::serviceCallback($this->built->id)]], $this->rows(), 'no plan on sale on its server: the builder alone');
        self::assertSame(['text' => self::text(BotText::CustomRenewButton), 'callback_data' => $this->build(), 'style' => 'primary'], $this->inlineKeyboard(0)[0][0]);

        $plan = $this->plan([], $this->germany);
        $this->send($this->tap(RenewalHandler::checkoutCallback($this->built->id, 1)));
        self::assertSame([RenewalHandler::checkoutCallback($this->built->id, 1, $plan->id), CustomServiceHandler::renewalCallback($this->built->id, 1)], array_slice($this->callbacks(0), 0, 2), "under the plans; from the menu's list, back there");

        $this->customServicesOn([$this->germany], ['custom_enabled' => false]);
        $this->send($this->tap(RenewalHandler::checkoutCallback($this->built->id)));
        self::assertSame([[RenewalHandler::checkoutCallback($this->built->id, 0, $plan->id)], [SubscriptionHandler::serviceCallback($this->built->id)]], $this->rows(), 'the builder off: the plans alone');

        $plan->forceFill(['is_active' => false])->save();
        $this->send($this->tap(RenewalHandler::checkoutCallback($this->built->id)));
        self::assertSame([self::text(BotText::RenewNoPlans), 'true'], [$this->popup(), $this->params(0)['show_alert'] ?? null], 'nothing to pick');
    }

    public function testTheGbAndTheDaysLeadToTheRenewalsCheckoutOfThePackage(): void
    {
        $this->send($this->tap($this->build()));
        self::assertSame([$this->trafficScreen()], $this->said());
        self::assertSame([[$this->days(10), $this->days(50)], [$this->days(100)], [RenewalHandler::checkoutCallback($this->built->id)]], $this->rows(), "the builder's amounts, and back to the renewal");

        $this->send($this->tap($this->days(50)));
        self::assertSame([$this->daysScreen(50)], $this->said());
        self::assertSame([[$this->checkout(50, 30), $this->checkout(50, 60)], [$this->build()]], $this->rows(), 'no server to pick: a renewal makes no new client');

        $this->send($this->tap($this->checkout(50, 30)));
        self::assertSame([$this->checkoutText()], $this->said(), '50 GB at 2,500 and 30 days at 1,500, on top of what it has — the 32 GB left going when this period ends');
        self::assertSame([$this->pay($this->wallet), $this->days(50)], $this->callbacks(0), 'a button per way to pay, and back to the days');
        self::assertSame(0, Order::query()->count(), 'nothing is ordered before a way to pay is picked');
    }

    public function testAmountsMayBeTypedAndOneOutsideTheBoundsIsRefused(): void
    {
        $this->send($this->tap($this->build()));

        $this->send($this->message('۶۰۰ گیگ'));
        self::assertSame([self::text(BotText::CustomTrafficInvalid, ['least' => '۱', 'most' => '۵۰۰'])], $this->said());
        self::assertSame([$this->build()], $this->callbacks(0));

        $this->send($this->message('۵۰ گیگ'));
        self::assertSame([$this->daysScreen(50)], $this->said(), 'the days as a new message');

        $this->send($this->message('100'));
        self::assertSame([self::text(BotText::CustomDaysInvalid, ['least' => '۱', 'most' => '۹۰'])], $this->said());
        self::assertSame([$this->days(50)], $this->callbacks(0));

        $this->send($this->message('30'));
        self::assertSame([$this->checkoutText()], $this->said(), "the renewal's checkout");
    }

    public function testTheWalletRenewsItWithThePackageWhichItsRefundTakesBack(): void
    {
        $this->reportGroup();
        $this->send($this->tap($this->checkout(50, 30)));

        $this->send($this->tap($this->pay($this->wallet)));

        self::assertSame(['deleteMessage', 'sendMessage', 'answerCallbackQuery'], $this->calls(), 'what it renewed arrives as a new message');
        self::assertSame([self::text(BotText::Renewed, [
            'client' => 'ali_1',
            'plan' => self::text(BotText::CustomPlanName),
            'expires' => $this->renewedExpiry(),
            'remaining' => Messages::bytes(Traffic::bytesOfGb(82)),
            'leftover' => $this->periodNote(),
        ])], $this->said(), 'the numbers the checkout showed');
        $order = Order::query()->sole();
        self::assertSame(
            [OrderType::Renewal, OrderStatus::Fulfilled, $this->built->id, null, Traffic::bytesOfGb(50), 30, 3, RenewalMethod::Extend, '170000.00'],
            [$order->type, $order->status, $order->subscription_id, $order->plan_id, $order->traffic_bytes, $order->days, $order->devices, $order->renewal_method, $order->amount],
            'a renewal without a plan, carrying its package',
        );
        self::assertSame('330000.00', $this->ali->balance());
        $service = $this->built->refresh();
        self::assertSame([null, Traffic::bytesOfGb(100), self::RENEWED_DEADLINE, 60 * 24, 2, 3, Traffic::bytesOfGb(50)], [
            $service->plan_id,
            $service->traffic_limit_bytes,
            $service->expires_at?->format('Y-m-d H:i:s'),
            $service->duration_hours,
            $service->ip_limit,
            $service->next_period_ip_limit,
            $service->next_period_bytes,
        ], "its term and traffic on top, the builder's devices once the period in use ends, and still no plan");
        self::assertSame([Traffic::bytesOfGb(100), 2], [FakeProvider::lastUpdate('ali_1')->totalBytes, FakeProvider::lastUpdate('ali_1')->ipLimit], 'renewed on its panel');
        self::assertStringContainsString('🔖 سرویس: <code>ali_1</code> · سرویس دلخواه · ۵۰ گیگ · ۳۰ روز · آلمان', ReportMessage::query()->where('topic', Topic::Renewals->value)->sole()->text);

        $this->send($this->tap(SubscriptionHandler::serviceCallback($this->built->id)));
        self::assertNotContains(SubscriptionHandler::serviceCallback($this->built->id, 'autorenew'), $this->callbacks(0), '«تمدید خودکار»: no plan to renew it on');

        $this->service(PaymentActions::class)->refund($order->payments()->firstOrFail(), $this->panelActor(), null);
        $service->refresh();
        self::assertSame([OrderStatus::Refunded, '500000.00'], [$order->refresh()->status, $this->ali->balance()]);
        self::assertSame([Traffic::bytesOfGb(50), 30 * 24, self::DEADLINE, null], [$service->traffic_limit_bytes, $service->duration_hours, $service->expires_at?->format('Y-m-d H:i:s'), $service->plan_id], 'what the renewal gave, taken back');
        self::assertNotNull($order->taken_back_at);
    }

    public function testItsRenewalSaysTheTrafficLeftWentAlongWhenItWasCarried(): void
    {
        $this->botSettings('renewal', ['carry_traffic' => true, 'renew_reset_time' => true]);
        $carried = self::text(BotText::RenewalLeftoverCarried);

        $this->send($this->tap($this->checkout(50, 30)));
        $this->send($this->tap($this->pay($this->wallet)));
        self::assertSame([self::text(BotText::Renewed, [
            'client' => 'ali_1',
            'plan' => self::text(BotText::CustomPlanName),
            'expires' => $this->renewedExpiry(),
            'remaining' => Messages::bytes(Traffic::bytesOfGb(82)),
            'leftover' => $carried,
        ])], $this->said(), 'by an extension, as the shop carries it: its 32 GB left on top of the 50 the package gave');

        $this->send($this->tap($this->checkout(50, 30, RenewalMethod::ResetTime)));
        $this->send($this->tap($this->pay($this->wallet, RenewalMethod::ResetTime)));
        self::assertSame(RenewalMethod::ResetTime, Order::query()->latest('id')->firstOrFail()->renewal_method);
        self::assertStringContainsString($carried, $this->said()[0], 'by a reset of the time, which always carries it');
    }

    public function testBeforeItsWindowItSaysFromWhenAndOrdersNothing(): void
    {
        $this->botSettings('renewal', ['renew_window_days' => 7]);
        $opens = 'از ' . Persian::date(Carbon::parse('2026-10-12 12:00:00'), withTime: true);

        foreach (['its renewal' => RenewalHandler::checkoutCallback($this->built->id), 'the checkout of a package' => $this->checkout(50, 30), 'a pay button' => $this->pay($this->wallet)] as $case => $tap) {
            $this->send($this->tap($tap));

            self::assertSame(['answerCallbackQuery'], $this->calls(), "{$case}: the screen stays");
            self::assertSame([self::text(BotText::RenewNotYet, ['opens' => $opens]), 'true'], [$this->popup(), $this->params(0)['show_alert'] ?? null], "{$case}: from when");
        }
        self::assertSame(0, Order::query()->count());
    }

    public function testDaysTypedForARenewalRefusedMeanwhileAreAnsweredWithAMessage(): void
    {
        $this->send($this->tap($this->build()));
        $this->send($this->message('۵۰ گیگ'));

        // Its window closed since the builder was opened: a popup would never show for a message, so a message says it.
        $this->botSettings('renewal', ['renew_window_days' => 7]);
        $this->send($this->message('30'));
        self::assertSame(['sendMessage'], $this->calls());
        self::assertSame([self::text(BotText::RenewNotYet, ['opens' => 'از ' . Persian::date(Carbon::parse('2026-10-12 12:00:00'), withTime: true)])], $this->said());
        self::assertSame([SubscriptionHandler::serviceCallback($this->built->id)], $this->callbacks(0), 'back where its renewal was opened');

        // A renewal of it under way meanwhile: the same.
        $this->botSettings('renewal', ['renew_window_days' => 0]);
        $this->send($this->tap($this->days(50)));
        $this->service(OrderService::class)->openCustomRenewal($this->ali, $this->built, 50, 30, RenewalMethod::Extend)->forceFill(['status' => OrderStatus::Failed])->save();
        $this->send($this->message('30'));
        self::assertSame([self::text(BotText::RenewUnderWay)], $this->said());
        self::assertSame([SubscriptionHandler::serviceCallback($this->built->id)], $this->callbacks(0));

        $this->send($this->message('30'));
        self::assertNotContains(self::text(BotText::RenewUnderWay), $this->said(), 'the step is over: a number typed now is no answer to it');
    }

    public function testWithSeveralMethodsItsCheckoutOffersEachAndPaysByTheOneShown(): void
    {
        $this->botSettings('renewal', ['renew_extend' => true, 'renew_reset_traffic' => true, 'renew_reset_time' => true]);

        $this->send($this->tap($this->checkout(50, 30)));
        self::assertSame([$this->checkoutText()], $this->said(), 'drawn by the first offered');
        self::assertSame([
            $this->pay($this->wallet),
            $this->checkout(50, 30),
            $this->checkout(50, 30, RenewalMethod::ResetTraffic),
            $this->checkout(50, 30, RenewalMethod::ResetTime),
            $this->days(50),
        ], $this->callbacks(0));

        $this->send($this->tap($this->checkout(50, 30, RenewalMethod::ResetTraffic)));
        self::assertSame(self::text(BotText::RenewMethodChosen, ['method' => RenewalMethod::ResetTraffic->label()]), $this->inlineKeyboard(0)[1][1]['text']);
        self::assertSame($this->pay($this->wallet, RenewalMethod::ResetTraffic), $this->callbacks(0)[0], 'its pay button names the method');

        $this->send($this->tap($this->pay($this->wallet, RenewalMethod::ResetTraffic)));
        $order = Order::query()->sole();
        self::assertSame([OrderStatus::Fulfilled, RenewalMethod::ResetTraffic, null], [$order->status, $order->renewal_method, $order->plan_id]);
        $service = $this->built->refresh();
        self::assertSame([Traffic::bytesOfGb(50), 0, self::RENEWED_DEADLINE], [$service->traffic_limit_bytes, $service->usedBytes(), $service->expires_at?->format('Y-m-d H:i:s')], "the days on top, the package's traffic counted afresh");
    }

    public function testADiscountCodeComesOffItsCheckout(): void
    {
        $this->discountCode('NOWRUZ', 10);
        $checkout = $this->checkout(50, 30);

        $this->send($this->tap($checkout));
        self::assertSame([$this->pay($this->wallet), DiscountCodeHandler::askCallback($checkout), $this->days(50)], $this->callbacks(0));
        $this->send($this->tap(DiscountCodeHandler::askCallback($checkout)));
        $this->send($this->message('nowruz'));
        self::assertStringContainsString(self::text(BotText::CheckoutDiscount, ['discount' => 'کد NOWRUZ', 'amount' => Money::format('17000')]), $this->said()[0]);
        self::assertStringContainsString(Money::format('153000'), $this->said()[0]);

        $this->send($this->tap($this->pay($this->wallet)));
        $order = Order::query()->sole();
        self::assertSame([OrderStatus::Fulfilled, '153000.00', '17000.00'], [$order->status, $order->amount, $order->discount_amount]);
    }

    public function testAStepThatLeftTheBoundsTheBuilderSwitchedOffAndAPlanGivenSinceAreEachShown(): void
    {
        $this->send($this->tap($this->checkout(600, 30)));
        self::assertSame([$this->trafficScreen()], $this->said(), 'the GB to choose again');
        $this->send($this->tap($this->pay($this->wallet, gb: 50, days: 91)));
        self::assertSame([$this->daysScreen(50)], $this->said(), 'the days to choose again');

        $this->customServicesOn([$this->germany], ['custom_enabled' => false]);
        $this->send($this->tap($this->days(50)));
        self::assertSame([self::text(BotText::CustomUnavailable)], $this->said());
        self::assertSame([RenewalHandler::checkoutCallback($this->built->id)], $this->callbacks(0), 'back to its renewal');

        $this->customServicesOn([$this->germany]);
        $this->built->forceFill(['plan_id' => $this->plan([], $this->germany)->id])->save();
        $this->send($this->tap($this->pay($this->wallet)));
        self::assertSame([CallbackData::build(RenewalHandler::checkoutCallback($this->built->id), $this->wallet->id), SubscriptionHandler::serviceCallback($this->built->id)], $this->callbacks(0), 'a plan since: its checkout on it, to be seen first');
        self::assertSame(0, Order::query()->count());
    }

    public function testPayAgainOpensTheRenewalsCheckoutOfWhatItsOrderBuilt(): void
    {
        $order = $this->service(OrderService::class)->openCustomRenewal($this->ali, $this->built, 50, 30, RenewalMethod::Extend);

        $this->send($this->tap(PurchaseHandler::reopenCallback($order->id)));
        self::assertSame([$this->checkoutText()], $this->said(), "the renewal's checkout, not a purchase's");

        $this->send($this->tap($this->pay($this->wallet)));
        self::assertSame(OrderStatus::Fulfilled, $order->refresh()->status, 'paying it found that very order');
        self::assertSame(1, Order::query()->count());
    }

    /** The GB step, renewing ali_1 as the builder of customServicesOn() words it. */
    private function trafficScreen(): string
    {
        return self::text(BotText::CustomRenewTraffic, [
            'client' => 'ali_1',
            'least' => '۱',
            'most' => '۵۰۰',
            'tiers' => implode("\n", [
                self::text(BotText::CustomTier, ['from' => Messages::traffic(Traffic::bytesOfGb(1)), 'unit_price' => Money::format('3000')]),
                self::text(BotText::CustomTier, ['from' => Messages::traffic(Traffic::bytesOfGb(50)), 'unit_price' => Money::format('2500')]),
            ]),
        ]);
    }

    /** The days step after `$gb` GB. */
    private function daysScreen(int $gb): string
    {
        return self::text(BotText::CustomRenewDays, [
            'client' => 'ali_1',
            'traffic' => Messages::traffic(Traffic::bytesOfGb($gb)),
            'least' => '۱',
            'most' => '۹۰',
            'tiers' => implode("\n", [
                self::text(BotText::CustomTier, ['from' => Messages::duration(24), 'unit_price' => Money::format('2000')]),
                self::text(BotText::CustomTier, ['from' => Messages::duration(30 * 24), 'unit_price' => Money::format('1500')]),
            ]),
        ]);
    }

    /**
     * ali_1's renewal with 50 GB for 30 days: on top of its deadline and quota, its 32 GB left going on its deadline —
     * and the builder's 3 devices from then on, its 2 until then.
     */
    private function checkoutText(): string
    {
        return self::text(BotText::RenewCheckout, [
            'client' => 'ali_1',
            'plan' => Order::builtName(50, 30),
            'duration' => Messages::duration(30 * 24),
            'traffic' => Messages::traffic(Traffic::bytesOfGb(50)),
            'devices' => Messages::devices(3),
            'devices_from' => self::text(BotText::RenewalDevicesFrom, ['date' => Persian::date(Carbon::parse(self::DEADLINE))]),
            'amount' => Money::format('170000'),
            'expires' => $this->renewedExpiry(),
            'remaining' => Messages::bytes(Traffic::bytesOfGb(82)),
            'leftover' => $this->periodNote(),
        ]);
    }

    /** The renewed deadline, and the 60 days to it. */
    private function renewedExpiry(): string
    {
        return Messages::expiry(Carbon::parse(self::RENEWED_DEADLINE), 60 * 24);
    }

    /** The 32 GB left of the period in use, usable until its deadline; the package's 50 GB from then on. */
    private function periodNote(): string
    {
        return self::text(BotText::RenewalLeftoverUntil, [
            'expiring' => Messages::bytes(Traffic::bytesOfGb(32)),
            'date' => Persian::date(Carbon::parse(self::DEADLINE)),
            'next' => Messages::bytes(Traffic::bytesOfGb(50)),
        ]);
    }

    /** «🛠 تمدید با سرویس دلخواه» from ali_1's screen. */
    private function build(): string
    {
        return CustomServiceHandler::renewalCallback($this->built->id, 0);
    }

    private function days(int $gb): string
    {
        return CustomServiceHandler::renewalDaysCallback($this->built->id, 0, $gb);
    }

    private function checkout(int $gb, int $days, ?RenewalMethod $method = null): string
    {
        return CustomServiceHandler::renewalCheckoutCallback($this->built->id, 0, $gb, $days, $method);
    }

    private function pay(PaymentMethod $way, RenewalMethod $method = RenewalMethod::Extend, int $gb = 50, int $days = 30): string
    {
        return CustomServiceHandler::renewalPayCallback($this->built->id, 0, $gb, $days, $method, $way->id);
    }

    /** @return list<list<string>> The callback data of the first call's buttons, row by row. */
    private function rows(): array
    {
        return array_map(static fn(array $row): array => array_column($row, 'callback_data'), $this->inlineKeyboard(0));
    }
}
