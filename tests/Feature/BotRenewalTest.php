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
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Telegram\Handlers\MenuHandler;
use App\Modules\Telegram\Handlers\PurchaseHandler;
use App\Modules\Telegram\Handlers\RenewalHandler;
use App\Modules\Telegram\Handlers\SubscriptionHandler;
use App\Modules\Telegram\Handlers\TopUpHandler;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Users\Models\User;
use App\Support\Money;
use App\Support\Persian;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\BotTestCase;
use Tests\Fakes\FakeProvider;

/**
 * «♻️ تمدید سرویس» in the bot — from a service's screen, or from the menu, which also lists the services that ended: the
 * renewal's checkout says what the plan renews it with, for how much, and the service as the renewal would leave it; a
 * way to pay picked, the renewal is ordered (or the open one found) and paid — the wallet renews it at once, a card once
 * support approved its receipt. A service its customer may not renew, or one whose renewal is under way, is said so in a
 * popup and nothing is ordered; in an agent's bot their traffic must cover the plan.
 */
final class BotRenewalTest extends BotTestCase
{
    /** The service's deadline as the fixture makes it — 30 days from the test's now — and its renewal's, 30 days on. */
    private const DEADLINE = '2026-10-19 12:00:00';
    private const RENEWED_DEADLINE = '2026-11-18 12:00:00';

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
        $this->inlineStartMenu();

        $this->server = $this->sellingServer();
        $this->plan = $this->plan(on: $this->server);
        $this->ali = $this->wallet($this->customer(['username' => 'ali']), '500000.00');
        $this->wallet = $this->walletMethod();
        // 18 GB of its 30 used, 12 left.
        $this->subscription = $this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['download_bytes' => Traffic::bytesOfGb(18)]);
        FakeProvider::mirror($this->subscription);
    }

    public function testTheServiceScreenOpensTheCheckoutWithTheServiceAsTheRenewalWouldLeaveIt(): void
    {
        $this->send($this->tap($this->renewButton()));

        self::assertSame(['editMessageText', 'answerCallbackQuery'], $this->calls(), 'the checkout in place of the screen');
        self::assertSame([$this->runningCheckout()], $this->said(), "the plan's 30 days on top of the deadline, its 30 GB on top of the quota — and the 12 GB left going when this period ends");
        self::assertSame([$this->pay($this->wallet), SubscriptionHandler::serviceCallback($this->subscription->id)], $this->callbacks(0), 'a button per way to pay, and back to the service');
        self::assertSame(0, Order::query()->count(), 'nothing is ordered before a way to pay is picked');
    }

    public function testTheWalletRenewsAtOnceAsTheCheckoutSaid(): void
    {
        $this->send($this->tap($this->renewButton()));

        $this->send($this->tap($this->pay($this->wallet)));

        self::assertSame(['deleteMessage', 'sendMessage', 'answerCallbackQuery'], $this->calls(), 'the checkout goes; what it renewed arrives as a new message');
        self::assertSame([self::text(BotText::Renewed, [
            'client' => 'ali_1',
            'plan' => 'یک‌ماهه',
            'expires' => $this->renewedExpiry(),
            'remaining' => Messages::bytes(Traffic::bytesOfGb(42)),
            'leftover' => $this->periodNote(),
        ])], $this->said(), 'the numbers the checkout showed');
        self::assertSame([[['text' => self::text(BotText::ReminderOpenService), 'callback_data' => SubscriptionHandler::serviceCallback($this->subscription->id)]]], $this->inlineKeyboard(1), 'the way to the service');

        $order = Order::query()->sole();
        self::assertSame([OrderType::Renewal, OrderStatus::Fulfilled, $this->subscription->id, $this->plan->id, '120000.00'], [$order->type, $order->status, $order->subscription_id, $order->plan_id, $order->amount]);
        self::assertSame('380000.00', $this->ali->balance());
        $spec = FakeProvider::lastUpdate('ali_1');
        self::assertSame([Traffic::bytesOfGb(60), self::RENEWED_DEADLINE], [$spec->totalBytes, $spec->expiry->deadline()?->format('Y-m-d H:i:s')], 'renewed on its panel');
        $subscription = $this->subscription->refresh();
        self::assertSame([Traffic::bytesOfGb(60), self::RENEWED_DEADLINE, self::DEADLINE, Traffic::bytesOfGb(30)], [
            $subscription->traffic_limit_bytes,
            $subscription->expires_at?->format('Y-m-d H:i:s'),
            $subscription->period_ends_at?->format('Y-m-d H:i:s'),
            $subscription->next_period_bytes,
        ], 'the period in use queued ahead of the renewed one');
    }

    public function testTheSameWalletButtonTwiceRenewsOnce(): void
    {
        $this->send($this->tap($this->renewButton()));
        $this->send($this->tap($this->pay($this->wallet)));

        $this->send($this->tap($this->pay($this->wallet)));

        self::assertSame([self::text(BotText::OrderNotPending)], $this->said(), 'the checkout was paid by the first tap');
        self::assertSame([$this->checkout()], $this->callbacks(0), 'with the way back to a fresh checkout');
        self::assertSame(1, Order::query()->count());
        self::assertSame(['ali_1'], FakeProvider::updatedNames(), 'renewed once');
        self::assertSame('380000.00', $this->ali->balance());
    }

    public function testAShortWalletIsExplainedBeforeAnythingIsOrdered(): void
    {
        $this->wallet($this->ali, '50000.00');
        $this->send($this->tap($this->renewButton()));

        $this->send($this->tap($this->pay($this->wallet)));

        self::assertSame([self::text(BotText::PayInsufficient, [
            'balance' => Messages::balance('50000.00'),
            'amount' => Money::format('120000'),
            'missing' => Money::format('70000'),
        ])], $this->said());
        self::assertSame([TopUpHandler::START, $this->checkout()], $this->callbacks(0), 'top up, or back to the checkout');
        self::assertSame([0, 0], [Order::query()->count(), Payment::query()->count()]);
    }

    public function testACardWaitsForItsReceiptAndTheRenewalComesOnceSupportApprovesIt(): void
    {
        $card = $this->cardMethod();
        $this->send($this->tap($this->renewButton()));
        self::assertSame([$this->pay($this->wallet), $this->pay($card), SubscriptionHandler::serviceCallback($this->subscription->id)], $this->callbacks(0));

        $this->send($this->tap($this->pay($card)));
        self::assertSame([BotTexts::paragraphs(trim(self::text(BotText::CardInstructions, [
            'amount' => Money::format('120000'),
            'card' => '6037997700001119',
            'holder' => 'AmoBot',
            'instructions' => '',
        ])), self::text(BotText::PayInstructionsSuffix))], $this->said());
        self::assertSame([$this->checkout()], $this->callbacks(0), 'back to the checkout to pick another way');
        $order = Order::query()->sole();
        self::assertSame([OrderType::Renewal, OrderStatus::Pending], [$order->type, $order->status]);

        // The same card again finds the same order and payment.
        $this->send($this->tap($this->renewButton()));
        $this->send($this->tap($this->pay($card)));
        self::assertSame([1, 1], [Order::query()->count(), Payment::query()->count()]);

        $this->send($this->message(null, ['message_id' => 4321, 'photo' => [['file_id' => 'receipt-1']]]));
        self::assertSame([self::text(BotText::ReceiptReceived, ['outcome' => self::text(BotText::ReceiptOutcomeRenewal)])], $this->said(), 'the receipt brings a renewal, not a new service');

        // With its receipt at support, no second renewal is started.
        $this->send($this->tap($this->renewButton()));
        self::assertSame([self::text(BotText::RenewUnderWay), 'true'], [$this->popup(), $this->params(0)['show_alert'] ?? null]);
        self::assertSame(1, Order::query()->count());

        $this->telegram()->reset();
        $this->service(PaymentActions::class)->approve(Payment::query()->sole(), $this->panelActor());

        self::assertSame(OrderStatus::Fulfilled, $order->refresh()->status);
        self::assertSame([self::text(BotText::Renewed, [
            'client' => 'ali_1',
            'plan' => 'یک‌ماهه',
            'expires' => $this->renewedExpiry(),
            'remaining' => Messages::bytes(Traffic::bytesOfGb(42)),
            'leftover' => $this->periodNote(),
        ])], $this->telegram()->sentTo(self::CHAT), "support's approval brings the renewal");
        self::assertSame(4321, $this->telegram()->replyTarget(0), 'as a reply to the receipt');
        self::assertSame('500000.00', $this->ali->balance(), 'the wallet untouched');
    }

    public function testARenewalPaidButNotDeliveredIsUnderWayAndNotChargedAgain(): void
    {
        $failed = $this->service(OrderService::class)->createRenewal($this->ali, $this->subscription, $this->plan);
        $failed->forceFill(['status' => OrderStatus::Failed])->save();

        $this->send($this->tap($this->renewButton()));

        self::assertSame(['answerCallbackQuery'], $this->calls(), 'the screen stays');
        self::assertSame([self::text(BotText::RenewUnderWay), 'true'], [$this->popup(), $this->params(0)['show_alert'] ?? null]);

        // A checkout shown before is refused the same way.
        $this->send($this->tap($this->pay($this->wallet)));
        self::assertSame(self::text(BotText::RenewUnderWay), $this->popup());
        self::assertSame([$failed->id], Order::query()->pluck('id')->all());
        self::assertSame('500000.00', $this->ali->balance());
    }

    /** @return iterable<string, array{\Closure(self): void}> */
    public static function servicesTheirCustomerMayNotRenew(): iterable
    {
        yield 'switched off by support' => [static function (self $test): void {
            $test->subscription->forceFill(['status' => SubscriptionStatus::Disabled])->save();
        }];
        yield 'its plan gone' => [static function (self $test): void {
            $test->subscription->forceFill(['plan_id' => null])->save();
        }];
        yield 'a plan that renews nothing' => [static function (self $test): void {
            $test->plan->forceFill(['duration_days' => 0, 'traffic_gb' => 0])->save();
        }];
    }

    /** @param \Closure(self): void $make */
    #[DataProvider('servicesTheirCustomerMayNotRenew')]
    public function testAServiceItsCustomerMayNotRenewIsSaidSo(\Closure $make): void
    {
        $make($this);

        $this->send($this->tap($this->renewButton()));

        self::assertSame(['answerCallbackQuery'], $this->calls());
        self::assertSame([self::text(BotText::RenewUnavailable), 'true'], [$this->popup(), $this->params(0)['show_alert'] ?? null]);
        self::assertSame(0, Order::query()->count());
    }

    public function testAnEndedServiceIsRenewedFromTheMenuAndStartsAfresh(): void
    {
        $ended = $this->endedService('ali_2');

        $this->send($this->tap(MainMenu::SUBSCRIPTIONS));
        self::assertNotContains(SubscriptionHandler::serviceCallback($ended->id), $this->callbacks(0), '«سرویس‌های من» lists the running ones');

        $this->send($this->tap(MainMenu::RENEW));
        self::assertSame(self::text(BotText::RenewChoose), $this->params(0)['text']);
        self::assertSame([RenewalHandler::checkoutCallback($ended->id, 1), RenewalHandler::checkoutCallback($this->subscription->id, 1)], array_slice($this->callbacks(0), 0, 2), 'the ended one too, newest first');

        $this->send($this->tap(RenewalHandler::checkoutCallback($ended->id, 1)));
        self::assertSame([$this->checkoutText($ended, Messages::expiry(null, 30), Messages::bytes(Traffic::bytesOfGb(30)))], $this->said(), "a fresh term from its next connection, and the plan's traffic");
        self::assertSame(MenuHandler::renewalsCallback(1), $this->callbacks(0)[1], 'back to the list');

        $this->send($this->tap(CallbackData::build(RenewalHandler::checkoutCallback($ended->id, 1), $this->wallet->id)));
        $ended->refresh();
        self::assertSame([SubscriptionStatus::Active, null, 30, Traffic::bytesOfGb(30), 0], [$ended->status, $ended->expires_at, $ended->duration_days, $ended->traffic_limit_bytes, $ended->usedBytes()], 'running again, its clock waiting for the next connection');
        self::assertSame([self::text(BotText::Renewed, [
            'client' => 'ali_2',
            'plan' => 'یک‌ماهه',
            'expires' => Messages::expiry(null, 30),
            'remaining' => Messages::bytes(Traffic::bytesOfGb(30)),
            'leftover' => '',
        ])], $this->said());
    }

    public function testTheMenusListHoldsWhatTheCustomerMayRenewAPageAtATime(): void
    {
        $ended = $this->endedService('ali_2');
        $this->subscription($this->ali, $this->plan, $this->server, 'ali_3', ['status' => SubscriptionStatus::Disabled]);
        $this->subscription($this->ali, $this->plan, $this->server, 'ali_4', ['status' => SubscriptionStatus::Deleted]);
        $this->subscription($this->ali, $this->plan, $this->server, 'ali_5', ['plan_id' => null]);
        $this->subscription($this->customer(['telegram_id' => 9999]), $this->plan, $this->server, 'reza_1');

        $this->send($this->tap(MainMenu::RENEW));

        $services = array_values(array_filter($this->callbacks(0), static fn(string $data): bool => str_starts_with($data, RenewalHandler::PREFIX)));
        self::assertSame([RenewalHandler::checkoutCallback($ended->id, 1), RenewalHandler::checkoutCallback($this->subscription->id, 1)], $services, 'not one switched off, gone, without a plan or someone else\'s');

        // Six to renew: two pages, and the checkout's way back to the page it came from.
        $more = [];
        foreach (range(6, 9) as $n) {
            $more[] = $this->subscription($this->ali, $this->plan, $this->server, "ali_{$n}");
        }
        $this->send($this->tap(MainMenu::RENEW));
        self::assertContains(MenuHandler::renewalsCallback(2), $this->callbacks(0));
        self::assertStringEndsWith(trim(self::text(BotText::SubscriptionsPage, ['page' => Persian::digits(1), 'pages' => Persian::digits(2)])), $this->params(0)['text']);

        $this->send($this->tap(MenuHandler::renewalsCallback(2)));
        self::assertSame([RenewalHandler::checkoutCallback($this->subscription->id, 2)], array_values(array_filter($this->callbacks(0), static fn(string $data): bool => str_starts_with($data, RenewalHandler::PREFIX))), 'the oldest on the second page');
        self::assertContains(MenuHandler::renewalsCallback(1), $this->callbacks(0));

        $this->send($this->tap(RenewalHandler::checkoutCallback($this->subscription->id, 2)));
        self::assertSame(MenuHandler::renewalsCallback(2), $this->callbacks(0)[1]);
        self::assertCount(4, $more);
    }

    public function testWithNothingToRenewTheMenuSaysSo(): void
    {
        $this->subscription->forceFill(['status' => SubscriptionStatus::Disabled])->save();

        $this->send($this->tap(MainMenu::RENEW));

        self::assertSame(self::text(BotText::RenewNothing), $this->params(0)['text']);
    }

    public function testTheRemindersPayAgainOfARenewalOpensItsCheckout(): void
    {
        $order = $this->service(OrderService::class)->openRenewal($this->ali, $this->subscription, $this->plan);
        $this->cardPayment($order, $this->cardMethod());

        $this->send($this->tap(PurchaseHandler::reopenCallback($order->id)));
        self::assertSame([$this->runningCheckout()], $this->said(), "the renewal's checkout, not a purchase's");

        $this->send($this->tap($this->pay($this->wallet)));
        self::assertSame(OrderStatus::Fulfilled, $order->refresh()->status, 'paying it found that very order');
        self::assertSame(1, Order::query()->count());
    }

    public function testSomeoneElsesServiceIsNotFound(): void
    {
        $theirs = $this->subscription($this->customer(['telegram_id' => 9999]), $this->plan, $this->server, 'reza_1');

        $this->send($this->tap(RenewalHandler::checkoutCallback($theirs->id)));

        self::assertSame([self::text(BotText::ServiceNotFound)], $this->said());
        self::assertSame([MenuHandler::subscriptionsCallback(1)], $this->callbacks(0));
    }

    public function testInAnAgentsBotTheirTrafficMustCoverTheRenewalWhichDrawsIt(): void
    {
        $bot = $this->agentBot(traffic: 10);
        CurrentBot::run($bot, fn() => $this->withoutQr());
        [$service, $wallet] = CurrentBot::run($bot, function (): array {
            $plan = $this->plan(['traffic_gb' => 30, 'price' => '90000'], $this->server);
            $service = $this->subscription($this->wallet($this->customer(), '200000.00'), $plan, $this->server, 'reza_1');
            FakeProvider::mirror($service);

            return [$service, $this->walletMethod()];
        });

        $this->sendTo($bot, $this->tap(SubscriptionHandler::serviceCallback($service->id, 'renew')));
        self::assertSame(self::text(BotText::RenewUnavailable), $this->popup(), '10 GB cannot cover the plan\'s 30');

        $this->traffic($bot, 100);
        $this->sendTo($bot, $this->tap(SubscriptionHandler::serviceCallback($service->id, 'renew')));
        self::assertContains(CallbackData::build(RenewalHandler::checkoutCallback($service->id), $wallet->id), $this->callbacks(0));

        $this->sendTo($bot, $this->tap(CallbackData::build(RenewalHandler::checkoutCallback($service->id), $wallet->id)));
        $order = CurrentBot::run($bot, static fn(): Order => Order::query()->sole());
        self::assertSame(OrderStatus::Fulfilled, $order->status);
        self::assertSame(Traffic::bytesOfGb(70), $bot->trafficBalance(), "the plan's 30 GB out of the 100");
        self::assertSame(-Traffic::bytesOfGb(30), TrafficTransaction::query()->where('order_id', $order->id)->sole()->bytes);
    }

    /** The service screen's «تمدید سرویس». */
    private function renewButton(): string
    {
        return SubscriptionHandler::serviceCallback($this->subscription->id, 'renew');
    }

    /** The checkout of ali_1's renewal as the service screen opens it. */
    private function checkout(): string
    {
        return RenewalHandler::checkoutCallback($this->subscription->id);
    }

    /** That checkout's button that pays with the method. */
    private function pay(PaymentMethod $method): string
    {
        return CallbackData::build($this->checkout(), $method->id);
    }

    /** ali_1's checkout: 30 days on top of its deadline, 30 GB on top of its quota, its 12 GB left going on its deadline. */
    private function runningCheckout(): string
    {
        return $this->checkoutText($this->subscription, $this->renewedExpiry(), Messages::bytes(Traffic::bytesOfGb(42)), $this->periodNote());
    }

    private function checkoutText(Subscription $subscription, string $expires, string $remaining, string $leftover = ''): string
    {
        return self::text(BotText::RenewCheckout, [
            'client' => $subscription->remote_name,
            'plan' => 'یک‌ماهه',
            'duration' => Messages::duration(30),
            'traffic' => Messages::traffic(Traffic::bytesOfGb(30)),
            'amount' => Money::format('120000'),
            'expires' => $expires,
            'remaining' => $remaining,
            'leftover' => $leftover,
        ]);
    }

    /** The renewed deadline, and the 60 days to it. */
    private function renewedExpiry(): string
    {
        return Messages::expiry(Carbon::parse(self::RENEWED_DEADLINE), 60);
    }

    /** The 12 GB left of the period in use, usable until its deadline; the renewed period's 30 GB from then on. */
    private function periodNote(): string
    {
        return self::text(BotText::RenewalLeftoverUntil, [
            'expiring' => Messages::bytes(Traffic::bytesOfGb(12)),
            'date' => Persian::date(Carbon::parse(self::DEADLINE)),
            'next' => Messages::bytes(Traffic::bytesOfGb(30)),
        ]);
    }

    /** A service of ali's that ended ten days ago with 25 GB of its 30 left, its client switched off on its panel. */
    private function endedService(string $name): Subscription
    {
        $ended = $this->subscription($this->ali, $this->plan, $this->server, $name, [
            'status' => SubscriptionStatus::Expired,
            'starts_at' => '2026-08-10 12:00:00',
            'expires_at' => '2026-09-09 12:00:00',
            'download_bytes' => Traffic::bytesOfGb(5),
        ]);
        FakeProvider::mirror($ended, enabled: false);

        return $ended;
    }
}
