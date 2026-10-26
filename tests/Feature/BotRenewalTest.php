<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Agency\Models\TrafficTransaction;
use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Discounts\Services\DiscountCodes;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\Pricing;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentActions;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Enums\RenewalMethod;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Exceptions\TermTooLongException;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ServiceTransfers;
use App\Modules\Telegram\Handlers\DiscountCodeHandler;
use App\Modules\Telegram\Handlers\MenuHandler;
use App\Modules\Telegram\Handlers\PurchaseHandler;
use App\Modules\Telegram\Handlers\RenewalHandler;
use App\Modules\Telegram\Handlers\SubscriptionHandler;
use App\Modules\Telegram\Handlers\TopUpHandler;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Users\Models\User;
use App\Support\LocalTime;
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
 * support approved its receipt. A service without a plan (claimed by its link) first lists the plans on sale on its
 * server to renew it on — by category, as «خرید اشتراک» files them, when they are in several —, and the one picked — a
 * discount code keeps it — becomes its plan. A service its customer may not renew, one whose renewal is under way, or
 * one without a plan and none to pick, gets a popup saying so and nothing is ordered; in an agent's bot their traffic
 * must cover the plan. One the shop holds back until it nears its end gets a popup saying from when. While the shop
 * offers more than one renewal method, the checkout has a button per method and is drawn by the one picked, which its
 * pay buttons renew by.
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
        $this->subscription = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['download_bytes' => Traffic::bytesOfGb(18)]));
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
        self::assertSame([$this->cardInstructions('120000')], $this->said());
        self::assertSame([$this->checkout()], $this->callbacks(1), 'back to the checkout to pick another way');
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
        yield 'a free test' => [static function (self $test): void {
            $test->subscription->forceFill(['plan_id' => null, 'is_test' => true])->save();
        }];
        yield 'a plan that renews nothing' => [static function (self $test): void {
            $test->plan->forceFill(['duration_hours' => 0, 'traffic_gb' => 0])->save();
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

    public function testARenewalThatWouldEndPastTheLastDayTheShopKeepsIsSaidSoAndNothingOrdered(): void
    {
        // 30 days left and the plan's 30 on top would pass the TIMESTAMP columns' last moment, 40 days away.
        Carbon::setTestNow(LocalTime::last()->subDays(40));
        $this->subscription->forceFill(['expires_at' => now()->addDays(30)])->save();

        $this->send($this->tap($this->renewButton()));

        self::assertSame(['answerCallbackQuery'], $this->calls());
        self::assertSame([TermTooLongException::message(), 'true'], [$this->popup(), $this->params(0)['show_alert'] ?? null]);
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
        self::assertSame([$this->checkoutText($ended, Messages::expiry(null, 30 * 24), Messages::bytes(Traffic::bytesOfGb(30)))], $this->said(), "a fresh term from its next connection, and the plan's traffic");
        self::assertSame(MenuHandler::renewalsCallback(1), $this->callbacks(0)[1], 'back to the list');

        $this->send($this->tap(CallbackData::build(RenewalHandler::checkoutCallback($ended->id, 1), $this->wallet->id)));
        $ended->refresh();
        self::assertSame([SubscriptionStatus::Active, null, 30 * 24, Traffic::bytesOfGb(30), 0], [$ended->status, $ended->expires_at, $ended->duration_hours, $ended->traffic_limit_bytes, $ended->usedBytes()], 'running again, its clock waiting for the next connection');
        self::assertSame([self::text(BotText::Renewed, [
            'client' => 'ali_2',
            'plan' => 'یک‌ماهه',
            'expires' => Messages::expiry(null, 30 * 24),
            'remaining' => Messages::bytes(Traffic::bytesOfGb(30)),
            'leftover' => '',
        ])], $this->said());
    }

    public function testTheMenusListHoldsWhatTheCustomerMayRenewAPageAtATime(): void
    {
        $ended = $this->endedService('ali_2');
        $this->subscription($this->ali, $this->plan, $this->server, 'ali_3', ['status' => SubscriptionStatus::Disabled]);
        $this->subscription($this->ali, $this->plan, $this->server, 'ali_4', ['status' => SubscriptionStatus::Deleted]);
        $planless = $this->subscription($this->ali, $this->plan, $this->server, 'ali_5', ['plan_id' => null]);
        $this->subscription($this->ali, $this->plan, $this->server, 'test_1', ['plan_id' => null, 'is_test' => true]);
        $this->subscription($this->customer(['telegram_id' => 9999]), $this->plan, $this->server, 'reza_1');

        $this->send($this->tap(MainMenu::RENEW));

        $services = array_values(array_filter($this->callbacks(0), static fn(string $data): bool => str_starts_with($data, RenewalHandler::PREFIX)));
        self::assertSame(
            [RenewalHandler::checkoutCallback($planless->id, 1), RenewalHandler::checkoutCallback($ended->id, 1), RenewalHandler::checkoutCallback($this->subscription->id, 1)],
            $services,
            'one without a plan renews on a plan picked; not one switched off, gone, a free test or someone else\'s',
        );

        // Six to renew: two pages, and the checkout's way back to the page it came from.
        $more = [];
        foreach (range(6, 8) as $n) {
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
        self::assertCount(3, $more);
    }

    public function testAServiceWithoutAPlanRenewsOnAPlanOfItsServerPickedWhichBecomesItsOwn(): void
    {
        $claimed = $this->claimedService('manual_7');
        $quarter = $this->quarterPlan();
        $this->plan(['name' => 'هلند'], $this->sellingServer('هلند'));
        $this->plan(['name' => 'قدیمی', 'is_active' => false], $this->server);

        $this->send($this->tap(SubscriptionHandler::serviceCallback($claimed->id)));
        self::assertContains(SubscriptionHandler::serviceCallback($claimed->id, 'renew'), $this->callbacks(0), 'renewable on its screen though it has no plan');

        $this->send($this->tap(SubscriptionHandler::serviceCallback($claimed->id, 'renew')));
        self::assertSame([self::text(BotText::RenewPickPlan, ['client' => 'manual_7'])], $this->said());
        self::assertSame([
            RenewalHandler::checkoutCallback($claimed->id, 0, $this->plan->id),
            RenewalHandler::checkoutCallback($claimed->id, 0, $quarter->id),
            SubscriptionHandler::serviceCallback($claimed->id),
        ], $this->callbacks(0), 'the plans on sale on its server alone, and back to the service');
        self::assertSame(self::text(BotText::PlanButton, [
            'plan' => 'یک‌ماهه',
            'traffic' => Messages::traffic(Traffic::bytesOfGb(30)),
            'duration' => Messages::duration(30 * 24),
            'price' => Money::format('120000'),
        ]), $this->inlineKeyboard(0)[0][0]['text'], 'each at its renewal price');

        $this->send($this->tap(RenewalHandler::checkoutCallback($claimed->id, 0, $this->plan->id)));
        self::assertSame([$this->checkoutText($claimed, $this->renewedExpiry(), Messages::bytes(Traffic::bytesOfGb(42)), $this->periodNote())], $this->said(), 'the checkout on the plan picked');
        $pay = CallbackData::build(RenewalHandler::checkoutCallback($claimed->id), $this->wallet->id, $this->plan->id);
        self::assertSame([$pay, RenewalHandler::checkoutCallback($claimed->id)], $this->callbacks(0), 'paid on that plan, or back to the plans');
        self::assertSame(0, Order::query()->count());

        $this->send($this->tap($pay));
        $order = Order::query()->sole();
        self::assertSame([OrderType::Renewal, OrderStatus::Fulfilled, $claimed->id, $this->plan->id, '120000.00'], [$order->type, $order->status, $order->subscription_id, $order->plan_id, $order->amount]);
        self::assertSame($this->plan->id, $claimed->refresh()->plan_id, 'the plan picked is its own now');
        self::assertSame(self::RENEWED_DEADLINE, $claimed->expires_at?->format('Y-m-d H:i:s'));
        self::assertSame('380000.00', $this->ali->balance());

        $this->send($this->tap(SubscriptionHandler::serviceCallback($claimed->id, 'renew')));
        self::assertSame([CallbackData::build(RenewalHandler::checkoutCallback($claimed->id), $this->wallet->id), SubscriptionHandler::serviceCallback($claimed->id)], $this->callbacks(0), 'the next renewal goes straight to its checkout');
    }

    public function testAServiceWithoutAPlanPicksACategoryFirstWhenItsPlansAreInSeveral(): void
    {
        $claimed = $this->claimedService('manual_7');
        $monthly = $this->category(['name' => 'ماهانه']);
        $quarterly = $this->category(['name' => 'سه‌ماهه']);
        $off = $this->category(['name' => 'خاموش', 'is_active' => false]);
        $dutch = $this->category(['name' => 'هلند']);
        $this->category(['name' => 'سازمانی']);
        $this->plan->forceFill(['category_id' => $monthly->id])->save();
        $quarter = $this->quarterPlan();
        $quarter->forceFill(['category_id' => $quarterly->id])->save();
        $loose = $this->plan(['name' => 'بی دسته'], $this->server);
        $offPlan = $this->plan(['name' => 'دسته خاموش', 'category_id' => $off->id], $this->server);
        $this->plan(['name' => 'هلند', 'category_id' => $dutch->id], $this->sellingServer('هلند'));
        $categories = RenewalHandler::checkoutCallback($claimed->id);

        $this->send($this->tap(SubscriptionHandler::serviceCallback($claimed->id, 'renew')));
        self::assertSame([self::text(BotText::RenewPickPlan, ['client' => 'manual_7'])], $this->said());
        self::assertSame([
            RenewalHandler::categoryCallback($claimed->id, 0, $monthly->id),
            RenewalHandler::categoryCallback($claimed->id, 0, $quarterly->id),
            RenewalHandler::categoryCallback($claimed->id, 0, 0),
            SubscriptionHandler::serviceCallback($claimed->id),
        ], $this->callbacks(0), "the categories its plans are in, in order — not Holland's, sold on another server, nor an empty one —, then «سایر پلن‌ها»; and back to the service");
        self::assertSame(['ماهانه', 'سه‌ماهه', self::text(BotText::CategoryOther)], array_map(static fn(array $row): string => $row[0]['text'], array_slice($this->inlineKeyboard(0), 0, 3)), 'one a row');

        $this->send($this->tap(RenewalHandler::categoryCallback($claimed->id, 0, $quarterly->id)));
        self::assertSame([self::text(BotText::RenewCategoryPlans, ['client' => 'manual_7', 'category' => 'سه‌ماهه'])], $this->said());
        self::assertSame([RenewalHandler::checkoutCallback($claimed->id, 0, $quarter->id), $categories], $this->callbacks(0), 'its plans, and back to the categories');
        self::assertSame(self::text(BotText::PlanButton, [
            'plan' => 'سه‌ماهه',
            'traffic' => Messages::traffic(Traffic::bytesOfGb(90)),
            'duration' => Messages::duration(90 * 24),
            'price' => Money::format('300000'),
        ]), $this->inlineKeyboard(0)[0][0]['text'], 'at its renewal price');

        $this->send($this->tap(RenewalHandler::categoryCallback($claimed->id, 0, 0)));
        self::assertSame([self::text(BotText::RenewCategoryPlans, ['client' => 'manual_7', 'category' => self::text(BotText::CategoryOther)])], $this->said());
        self::assertSame([RenewalHandler::checkoutCallback($claimed->id, 0, $loose->id), RenewalHandler::checkoutCallback($claimed->id, 0, $offPlan->id), $categories], $this->callbacks(0), 'a plan whose category is switched off is renewed on too, under «سایر پلن‌ها»');

        $monthly->forceFill(['is_active' => false])->save();
        $this->send($this->tap(RenewalHandler::categoryCallback($claimed->id, 0, $monthly->id)));
        self::assertSame([self::text(BotText::RenewPickPlan, ['client' => 'manual_7'])], $this->said(), 'a category switched off since: the categories as they are now');
        self::assertSame([RenewalHandler::categoryCallback($claimed->id, 0, $quarterly->id), RenewalHandler::categoryCallback($claimed->id, 0, 0), SubscriptionHandler::serviceCallback($claimed->id)], $this->callbacks(0));

        $this->send($this->tap(RenewalHandler::checkoutCallback($claimed->id, 0, $offPlan->id)));
        self::assertSame(RenewalHandler::categoryCallback($claimed->id, 0, 0), $this->callbacks(0)[1], 'back from a plan whose category is switched off lands on «سایر پلن‌ها»');

        $this->send($this->tap(RenewalHandler::checkoutCallback($claimed->id, 0, $quarter->id)));
        $pay = CallbackData::build($categories, $this->wallet->id, $quarter->id);
        self::assertSame([$pay, RenewalHandler::categoryCallback($claimed->id, 0, $quarterly->id)], $this->callbacks(0), "back from the checkout lands on the plan's category");

        $this->send($this->tap($pay));
        self::assertSame([OrderStatus::Fulfilled, $quarter->id], [Order::query()->sole()->status, $claimed->refresh()->plan_id], 'renewed on the plan picked, its own now');
    }

    public function testAServiceWhosePlansAreInOneCategoryListsThemAtOnce(): void
    {
        $claimed = $this->claimedService('manual_7');
        $monthly = $this->category(['name' => 'ماهانه']);
        $this->category(['name' => 'سازمانی']);
        $this->plan->forceFill(['category_id' => $monthly->id])->save();
        $quarter = $this->quarterPlan();
        $quarter->forceFill(['category_id' => $monthly->id])->save();
        $plans = [RenewalHandler::checkoutCallback($claimed->id, 0, $this->plan->id), RenewalHandler::checkoutCallback($claimed->id, 0, $quarter->id), SubscriptionHandler::serviceCallback($claimed->id)];

        $this->send($this->tap(SubscriptionHandler::serviceCallback($claimed->id, 'renew')));
        self::assertSame([self::text(BotText::RenewPickPlan, ['client' => 'manual_7'])], $this->said());
        self::assertSame($plans, $this->callbacks(0), 'one category is no choice: its plans at once');

        $this->send($this->tap(RenewalHandler::checkoutCallback($claimed->id, 0, $quarter->id)));
        $back = RenewalHandler::categoryCallback($claimed->id, 0, $monthly->id);
        self::assertSame($back, $this->callbacks(0)[1]);

        $this->send($this->tap($back));
        self::assertSame([self::text(BotText::RenewPickPlan, ['client' => 'manual_7'])], $this->said(), 'back to the plans as they were listed');
        self::assertSame($plans, $this->callbacks(0));
    }

    public function testAPayButtonOfAPickedPlanForAServiceThatHasAPlanSinceShowsItsCheckoutFirst(): void
    {
        $claimed = $this->claimedService('manual_7');
        $quarter = $this->quarterPlan();
        foreach (['that very plan' => $this->plan->id, 'another plan' => $quarter->id] as $case => $named) {
            // Renewed meanwhile elsewhere (the website): the plan it has now is the month's.
            $claimed->forceFill(['plan_id' => $this->plan->id])->save();

            $this->send($this->tap(CallbackData::build(RenewalHandler::checkoutCallback($claimed->id), $this->wallet->id, $named)));

            self::assertSame([$this->checkoutText($claimed, $this->renewedExpiry(), Messages::bytes(Traffic::bytesOfGb(42)), $this->periodNote())], $this->said(), "{$case}: its checkout as it is now");
            self::assertSame(0, Order::query()->count(), "{$case}: nothing paid unseen");
            self::assertSame('500000.00', $this->ali->balance(), $case);
        }
    }

    public function testADiscountCodeRedrawsTheCheckoutOnThePlanPicked(): void
    {
        $claimed = $this->claimedService('manual_7');
        $quarter = $this->quarterPlan();
        $this->discountCode('KEEP', 10, ['order_types' => ['renewal']]);
        $checkout = RenewalHandler::checkoutCallback($claimed->id, 0, $quarter->id);

        $this->send($this->tap($checkout));
        self::assertContains(DiscountCodeHandler::askCallback($checkout), $this->callbacks(0));
        $this->send($this->tap(DiscountCodeHandler::askCallback($checkout)));
        $this->send($this->message('KEEP'));

        [$text] = $this->said();
        self::assertStringContainsString('سه‌ماهه', $text, 'still the plan picked');
        self::assertStringContainsString(self::text(BotText::CheckoutDiscount, ['discount' => 'کد KEEP', 'amount' => Money::format('30000')]), $text);
        $pay = CallbackData::build(RenewalHandler::checkoutCallback($claimed->id), $this->wallet->id, $quarter->id);
        self::assertSame([$pay, DiscountCodeHandler::removeCallback($checkout), RenewalHandler::checkoutCallback($claimed->id)], $this->callbacks(0));

        $this->send($this->tap($pay));
        $order = Order::query()->sole();
        self::assertSame([OrderStatus::Fulfilled, $quarter->id, '270000.00'], [$order->status, $order->plan_id, $order->amount]);
        self::assertSame($quarter->id, $claimed->refresh()->plan_id);
    }

    public function testAPlanTakenOffSaleSinceItWasPickedShowsThePlansAsTheyAreNow(): void
    {
        $claimed = $this->claimedService('manual_7');
        $quarter = $this->quarterPlan();
        $this->send($this->tap(RenewalHandler::checkoutCallback($claimed->id, 0, $quarter->id)));
        $quarter->forceFill(['is_active' => false])->save();

        $this->send($this->tap(CallbackData::build(RenewalHandler::checkoutCallback($claimed->id), $this->wallet->id, $quarter->id)));

        self::assertSame([self::text(BotText::RenewPickPlan, ['client' => 'manual_7'])], $this->said());
        self::assertSame([RenewalHandler::checkoutCallback($claimed->id, 0, $this->plan->id), SubscriptionHandler::serviceCallback($claimed->id)], $this->callbacks(0));
        self::assertSame(0, Order::query()->count());
    }

    public function testAServiceWithoutAPlanAndNoneOnSaleOnItsServerIsSaidSo(): void
    {
        $claimed = $this->claimedService('manual_7', $this->sellingServer('هلند'));

        $this->send($this->tap(SubscriptionHandler::serviceCallback($claimed->id, 'renew')));

        self::assertSame(['answerCallbackQuery'], $this->calls(), 'the screen stays');
        self::assertSame([self::text(BotText::RenewNoPlans), 'true'], [$this->popup(), $this->params(0)['show_alert'] ?? null]);
        self::assertSame(0, Order::query()->count());
    }

    public function testWithNothingToRenewTheMenuSaysSo(): void
    {
        $this->subscription->forceFill(['status' => SubscriptionStatus::Disabled])->save();

        $this->send($this->tap(MainMenu::RENEW));

        self::assertSame(self::text(BotText::RenewNothing), $this->params(0)['text']);
    }

    public function testTheRemindersPayAgainOfARenewalOpensItsCheckout(): void
    {
        $order = $this->service(OrderService::class)->openRenewal($this->ali, $this->subscription, $this->plan, RenewalMethod::Extend);
        $this->cardPayment($order, $this->cardMethod());

        $this->send($this->tap(PurchaseHandler::reopenCallback($order->id)));
        self::assertSame([$this->runningCheckout()], $this->said(), "the renewal's checkout, not a purchase's");

        $this->send($this->tap($this->pay($this->wallet)));
        self::assertSame(OrderStatus::Fulfilled, $order->refresh()->status, 'paying it found that very order');
        self::assertSame(1, Order::query()->count());
    }

    public function testAServiceHandedOnBetweenItsCheckoutAndTheTapIsSaidSoAndNothingIsOrdered(): void
    {
        // From the menu's list of renewals: «بازگشت» leads back to it.
        $this->send($this->tap(RenewalHandler::checkoutCallback($this->subscription->id, 1)));
        $sara = $this->customer(['telegram_id' => 9999, 'first_name' => 'Sara']);

        $this->sendWhileTheServiceMovesOn($this->tap(CallbackData::build(RenewalHandler::PREFIX, $this->subscription->id, 1, $this->wallet->id)), $sara);

        self::assertSame([ServiceTransfers::MOVED_ON], $this->said(), 'not the error every failure gets');
        self::assertSame([MenuHandler::renewalsCallback(1)], $this->callbacks(0));
        self::assertSame([0, '500000.00'], [Order::query()->count(), $this->ali->balance()], 'nothing ordered, nothing charged');
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
            $service = $this->mirrored($this->subscription($this->wallet($this->customer(), '200000.00'), $plan, $this->server, 'reza_1'));

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

    public function testWithSeveralMethodsTheCheckoutOffersEachAndPaysByTheOneShown(): void
    {
        $this->methodsOn();

        $this->send($this->tap($this->renewButton()));
        self::assertSame([$this->runningCheckout()], $this->said(), 'drawn by the first offered: the numbers of an extension');
        self::assertSame([
            self::text(BotText::RenewMethodButton, ['method' => 'ریست زمان + انتقال حجم']),
            self::text(BotText::RenewMethodButton, ['method' => 'ریست حجم + افزایش روز']),
            self::text(BotText::RenewMethodChosen, ['method' => 'تمدید پلن فعلی']),
        ], array_column($this->inlineKeyboard(0)[1], 'text'), 'a row of the methods under the ways to pay, the first on the right, the one shown marked');
        self::assertSame([$this->pay($this->wallet), $this->checkoutBy(RenewalMethod::ResetTime), $this->checkoutBy(RenewalMethod::ResetTraffic), $this->checkout(), SubscriptionHandler::serviceCallback($this->subscription->id)], $this->callbacks(0));

        $this->send($this->tap($this->checkoutBy(RenewalMethod::ResetTraffic)));
        self::assertSame([$this->checkoutText($this->subscription, $this->renewedExpiry(), Messages::bytes(Traffic::bytesOfGb(30)))], $this->said(), 'the days left carry, the traffic starts afresh: nothing of this period queued');
        self::assertSame(self::text(BotText::RenewMethodChosen, ['method' => 'ریست حجم + افزایش روز']), $this->inlineKeyboard(0)[1][1]['text']);
        self::assertSame($this->payBy($this->wallet, RenewalMethod::ResetTraffic), $this->callbacks(0)[0], 'its pay button names the method');

        $this->send($this->tap($this->checkoutBy(RenewalMethod::ResetTime)));
        self::assertSame([$this->checkoutText($this->subscription, Messages::expiry(Carbon::parse('2026-10-19 12:00:00'), 30 * 24), Messages::bytes(Traffic::bytesOfGb(42)))], $this->said(), 'thirty days from now, the 12 GB left carried');

        // The wallet button of the checkout by another method — no longer the one on screen — draws that one again first.
        $this->send($this->tap($this->payBy($this->wallet, RenewalMethod::ResetTraffic)));
        self::assertSame([$this->checkoutText($this->subscription, $this->renewedExpiry(), Messages::bytes(Traffic::bytesOfGb(30)))], $this->said(), 'its checkout, as it renews now');
        self::assertSame(0, Order::query()->count(), 'nothing paid for a checkout not on screen');

        $this->send($this->tap($this->payBy($this->wallet, RenewalMethod::ResetTraffic)));
        $order = Order::query()->sole();
        self::assertSame([OrderStatus::Fulfilled, RenewalMethod::ResetTraffic], [$order->status, $order->renewal_method], 'the method its button named');
        $subscription = $this->subscription->refresh();
        self::assertSame([Traffic::bytesOfGb(30), 0, self::RENEWED_DEADLINE, null], [$subscription->traffic_limit_bytes, $subscription->usedBytes(), $subscription->expires_at?->format('Y-m-d H:i:s'), $subscription->next_period_bytes], 'renewed as that checkout said');
        self::assertSame([self::text(BotText::Renewed, [
            'client' => 'ali_1',
            'plan' => 'یک‌ماهه',
            'expires' => $this->renewedExpiry(),
            'remaining' => Messages::bytes(Traffic::bytesOfGb(30)),
            'leftover' => '',
        ])], $this->said());
    }

    public function testAOneMethodShopShowsNoChoiceAndRenewsByThatMethod(): void
    {
        $this->botSettings('renewal', ['renew_extend' => false, 'renew_reset_time' => true, 'renew_default_method' => 'reset_time']);

        $this->send($this->tap($this->renewButton()));
        self::assertSame([$this->payBy($this->wallet, RenewalMethod::ResetTime), SubscriptionHandler::serviceCallback($this->subscription->id)], $this->callbacks(0), 'no method to pick');

        $this->send($this->tap($this->pay($this->wallet)));
        self::assertSame(0, Order::query()->count(), 'a button naming no method renews by an extension, which is not offered: nothing paid unseen');
        self::assertSame([$this->payBy($this->wallet, RenewalMethod::ResetTime), SubscriptionHandler::serviceCallback($this->subscription->id)], $this->callbacks(0), 'the checkout as it is now instead');

        $this->send($this->tap($this->payBy($this->wallet, RenewalMethod::ResetTime)));
        self::assertSame([OrderStatus::Fulfilled, RenewalMethod::ResetTime], [Order::query()->sole()->status, Order::query()->sole()->renewal_method]);
    }

    public function testAPayButtonOfAMethodSwitchedOffSinceShowsTheCheckoutFirst(): void
    {
        $this->methodsOn();
        $this->send($this->tap($this->checkoutBy(RenewalMethod::ResetTime)));
        $this->botSettings('renewal', ['renew_reset_time' => false]);

        $this->send($this->tap($this->payBy($this->wallet, RenewalMethod::ResetTime)));

        self::assertSame([$this->runningCheckout()], $this->said(), 'by the first offered now');
        self::assertSame([0, '500000.00'], [Order::query()->count(), $this->ali->balance()]);
    }

    public function testADiscountCodeRedrawKeepsTheMethodAndSwitchingTheMethodKeepsTheCode(): void
    {
        $this->methodsOn();
        $this->discountCode('KEEP', 10, ['order_types' => ['renewal']]);
        $resetTime = $this->checkoutBy(RenewalMethod::ResetTime);
        $discount = self::text(BotText::CheckoutDiscount, ['discount' => 'کد KEEP', 'amount' => Money::format('12000')]);

        $this->send($this->tap($resetTime));
        $this->send($this->tap(DiscountCodeHandler::askCallback($resetTime)));
        $this->send($this->message('KEEP'));
        [$text] = $this->said();
        self::assertStringContainsString($discount, $text);
        self::assertStringContainsString(Messages::bytes(Traffic::bytesOfGb(42)), $text, 'still by a reset of the time');
        self::assertContains($this->payBy($this->wallet, RenewalMethod::ResetTime), $this->callbacks(0));

        $this->send($this->tap($this->checkout()));
        [$text] = $this->said();
        self::assertStringContainsString($discount, $text, 'the code kept by an extension too');

        $this->send($this->tap($this->pay($this->wallet)));
        $order = Order::query()->sole();
        self::assertSame([RenewalMethod::Extend, '108000.00'], [$order->renewal_method, $order->amount]);
    }

    public function testACodeLostAsAMethodsCheckoutIsPaidLeadsBackToThatMethodsCheckout(): void
    {
        $this->methodsOn();
        $this->discountCode('LAST', 10, ['max_uses' => 1]);
        $resetTraffic = $this->checkoutBy(RenewalMethod::ResetTraffic);
        $this->send($this->tap($resetTraffic));
        $this->send($this->tap(DiscountCodeHandler::askCallback($resetTraffic)));
        $this->send($this->message('LAST'));

        // Its last use taken by someone else's order meanwhile.
        $sara = $this->customer(['telegram_id' => 7001]);
        $this->service(OrderService::class)->openPurchase($sara, $this->plan, $this->server, quote: $this->service(Pricing::class)->purchase($sara, $this->plan, $this->server, 'LAST'));
        $this->send($this->tap($this->payBy($this->wallet, RenewalMethod::ResetTraffic)));

        self::assertSame([self::text(BotText::DiscountCodeLost, ['reason' => DiscountCodes::USED_UP])], $this->said());
        self::assertSame([$resetTraffic], $this->callbacks(0), '«بازگشت» to the checkout by the method it showed');
        self::assertSame(0, Order::query()->where('user_id', $this->ali->id)->count(), 'nothing ordered');

        $this->send($this->tap($resetTraffic));
        self::assertSame($this->payBy($this->wallet, RenewalMethod::ResetTraffic), $this->callbacks(0)[0], 'drawn by it again, without the code');
        self::assertStringNotContainsString('LAST', $this->said()[0]);
    }

    public function testARenewalBeforeItsWindowSaysFromWhenAndOrdersNothing(): void
    {
        $this->botSettings('renewal', ['renew_window_days' => 7]);
        $opens = 'از ' . Persian::date(Carbon::parse('2026-10-12 12:00:00'), withTime: true);
        $claimed = $this->claimedService('manual_7');

        foreach (['its screen' => $this->renewButton(), 'the menu\'s list' => RenewalHandler::checkoutCallback($this->subscription->id, 1), 'a pay button' => $this->pay($this->wallet), 'a service without a plan' => SubscriptionHandler::serviceCallback($claimed->id, 'renew'), 'its plan picked' => RenewalHandler::checkoutCallback($claimed->id, 0, $this->plan->id)] as $case => $tap) {
            $this->send($this->tap($tap));

            self::assertSame(['answerCallbackQuery'], $this->calls(), "{$case}: the screen stays");
            self::assertSame([self::text(BotText::RenewNotYet, ['opens' => $opens]), 'true'], [$this->popup(), $this->params(0)['show_alert'] ?? null], "{$case}: from when");
        }
        self::assertSame(0, Order::query()->count());

        $this->send($this->tap(MainMenu::RENEW));
        self::assertContains(RenewalHandler::checkoutCallback($this->subscription->id, 1), $this->callbacks(0), 'still listed: the tap explains');

        Carbon::setTestNow('2026-10-12 12:00:00');
        $this->send($this->tap($this->renewButton()));
        self::assertContains($this->pay($this->wallet), $this->callbacks(0), 'open from then');
    }

    public function testThePayAgainOfARenewalOpensItsCheckoutByItsMethod(): void
    {
        $this->methodsOn();
        $order = $this->service(OrderService::class)->openRenewal($this->ali, $this->subscription, $this->plan, RenewalMethod::ResetTraffic);
        $this->cardPayment($order, $this->cardMethod());

        $this->send($this->tap(PurchaseHandler::reopenCallback($order->id)));
        // The card's way to pay and the wallet's, then the methods.
        self::assertSame(self::text(BotText::RenewMethodChosen, ['method' => 'ریست حجم + افزایش روز']), $this->inlineKeyboard(0)[2][1]['text']);

        $this->send($this->tap($this->payBy($this->wallet, RenewalMethod::ResetTraffic)));
        self::assertSame([OrderStatus::Fulfilled, 1], [$order->refresh()->status, Order::query()->count()], 'paying it found that very order');
    }

    /** Every renewal method on. */
    private function methodsOn(): void
    {
        $this->botSettings('renewal', ['renew_extend' => true, 'renew_reset_traffic' => true, 'renew_reset_time' => true]);
    }

    /** ali_1's checkout by the method. */
    private function checkoutBy(RenewalMethod $method): string
    {
        return RenewalHandler::checkoutCallback($this->subscription->id, 0, 0, $method);
    }

    /** That checkout's button that pays with the way to pay: the method its fifth argument, by its place among the cases. */
    private function payBy(PaymentMethod $way, RenewalMethod $method): string
    {
        return CallbackData::build(RenewalHandler::PREFIX, $this->subscription->id, 0, $way->id, 0, (int) array_search($method, RenewalMethod::cases(), true) + 1);
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
            'duration' => Messages::duration(30 * 24),
            'traffic' => Messages::traffic(Traffic::bytesOfGb(30)),
            'devices' => Messages::devices(1),
            'devices_from' => '',
            'amount' => Money::format('120000'),
            'expires' => $expires,
            'remaining' => $remaining,
            'leftover' => $leftover,
        ]);
    }

    /** The renewed deadline, and the 60 days to it. */
    private function renewedExpiry(): string
    {
        return Messages::expiry(Carbon::parse(self::RENEWED_DEADLINE), 60 * 24);
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

    /** A service support made by hand that ali claimed by its link (no plan), as ali_1 is otherwise: 18 GB of 30 used. */
    private function claimedService(string $name, ?Server $server = null): Subscription
    {
        return $this->mirrored($this->claimedSubscription($this->ali, $server ?? $this->server, $name, ['download_bytes' => Traffic::bytesOfGb(18)]));
    }

    /** A three-month plan (300,000 Toman, 90 days, 90 GB) on sale on ali_1's server, after the one-month one. */
    private function quarterPlan(): Plan
    {
        return $this->plan(['name' => 'سه‌ماهه', 'price' => '300000.00', 'duration_hours' => 90 * 24, 'traffic_gb' => 90], $this->server);
    }

    /** A service of ali's that ended ten days ago with 25 GB of its 30 left, its client switched off on its panel. */
    private function endedService(string $name): Subscription
    {
        return $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, $name, [
            'status' => SubscriptionStatus::Expired,
            'starts_at' => '2026-08-10 12:00:00',
            'expires_at' => '2026-09-09 12:00:00',
            'download_bytes' => Traffic::bytesOfGb(5),
        ]), enabled: false);
    }
}
