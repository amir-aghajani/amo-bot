<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Exceptions\ValidationException;
use App\Modules\Agency\Services\AgencyStanding;
use App\Modules\Agency\Tasks\AgencyStandingTask;
use App\Modules\Auth\PrincipalKind;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Enums\BotStatus;
use App\Modules\Bots\Enums\SellingSuspension;
use App\Modules\Bots\Exceptions\SellingSuspendedException;
use App\Modules\Bots\Models\Bot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanCategory;
use App\Modules\Catalog\Services\ServerSelector;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\Models\Server;
use App\Modules\Rewards\DTO\Reward;
use App\Modules\Rewards\Exceptions\RewardUnavailableException;
use App\Modules\Rewards\Services\Rewards;
use App\Modules\Subscriptions\Enums\ExtraKind;
use App\Modules\Subscriptions\Enums\RenewalRefusal;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Enums\TestRefusal;
use App\Modules\Subscriptions\Exceptions\TestAccountRefusedException;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\AutoRenewal;
use App\Modules\Subscriptions\Services\CustomerExtras;
use App\Modules\Subscriptions\Services\CustomerRenewal;
use App\Modules\Subscriptions\Services\ServiceReminders;
use App\Modules\Subscriptions\Services\SubscriptionActions;
use App\Modules\Subscriptions\Services\TestAccounts;
use App\Modules\Telegram\Handlers\ExtrasHandler;
use App\Modules\Telegram\Handlers\MenuHandler;
use App\Modules\Telegram\Handlers\PurchaseHandler;
use App\Modules\Telegram\Handlers\RenewalHandler;
use App\Modules\Telegram\Handlers\SubscriptionHandler;
use App\Modules\Telegram\Handlers\TopUpHandler;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Models\TelegramSession;
use App\Modules\Telegram\Reports\Topic;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Users\Models\User;
use App\Support\JalaliMonth;
use App\Support\LocalTime;
use App\Support\Money;
use App\Support\Persian;
use Illuminate\Support\Carbon;
use Tests\BotTestCase;

/**
 * An agent's terms of selling (AgencyStanding): their agency's end and their monthly minimum purchase are warned of once,
 * days ahead, and then suspend their shop's SELLING — never its bot: every door that sells (a purchase, a renewal, an
 * extra, a top-up, a free test, a gift of a service, an extension by hand, automatic renewal) is shut with a polite word
 * — in the bot, the agent's own words, a message at the step tapped or typed —, while the bot, its services and the
 * delivery of what was paid run on, and the agent still buys traffic. A Jalali month
 * on the shop's clock below the minimum suspends once it is over; buying this month's minimum lifts it at once; an end
 * extended lifts its own. The agent and the owner's report group hear each step once.
 */
final class AgencySellingTest extends BotTestCase
{
    private Server $server;
    private Bot $bot;
    private User $agent;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        LocalTime::use('Asia/Tehran');
        Carbon::setTestNow('2026-09-25 12:00:00');
        $this->fakePanel();
        $this->withoutQr();
        $this->server = $this->fakeServer();
        $this->inbound($this->server, '1');
        $this->bot = $this->agentBot(traffic: 100);
        $this->agent = $this->bot->agent ?? self::fail('no agent');
        $this->plan = CurrentBot::run($this->bot, function (): Plan {
            $this->withoutQr();

            return $this->plan(['name' => 'پلن نماینده', 'traffic_gb' => 10, 'price' => '50000'], $this->server);
        });
    }

    protected function tearDown(): void
    {
        LocalTime::use('UTC');

        parent::tearDown();
    }

    public function testAnEndIsWarnedOfOnceThenStopsTheSellingNeverTheBot(): void
    {
        $this->reportGroup();
        $end = Carbon::parse('2026-10-11 12:00:00');
        $this->sellingTerms($this->bot, $end);
        $this->telegram()->reset();

        $this->judge();
        self::assertSame([], $this->toAgent(), 'sixteen days ahead: nothing yet');

        Carbon::setTestNow('2026-10-08 13:00:00');
        $this->judge();
        $this->judge();
        self::assertSame([self::text(BotText::AgencyExpiring, ['date' => Persian::date($end, withTime: true)])], $this->toAgent(), 'within the three days: warned once');

        Carbon::setTestNow($end);
        self::assertSame(SellingSuspension::Expired, $this->bot->refresh()->sellingSuspension(), 'it counts the moment it passes, before the task writes it down');
        $this->telegram()->reset();
        $this->judge();
        $this->judge();
        self::assertSame([self::text(BotText::AgencySuspended, ['reason' => SellingSuspension::Expired->words(), 'note' => ''])], $this->toAgent(), 'told once');
        $bot = $this->bot->refresh();
        self::assertSame([SellingSuspension::Expired, BotStatus::Active, true], [$bot->suspension_reason, $bot->status(), $bot->isServing()], 'the selling stops, never the bot');
        self::assertSame(1, $this->agencyReports('فروش نماینده متوقف شد'), "the owner's group hears it");
        self::assertSame(1, $this->agencyReports(SellingSuspension::Expired->words() . '؛ ' . SellingSuspension::Expired->remedy()), 'why, in the words the agent and the panels read, then what lifts it');

        $this->telegram()->reset();
        $later = $end->copy()->addMonth();
        $this->sellingTerms($this->bot, $later);
        self::assertTrue($this->bot->refresh()->sells(), 'extended: it sells again');
        self::assertSame([self::text(BotText::AgencyResumed)], $this->toAgent());
        self::assertSame(1, $this->agencyReports('فروش نماینده دوباره باز شد'));

        Carbon::setTestNow($later->copy()->subDays(2));
        $this->telegram()->reset();
        $this->judge();
        $this->judge();
        self::assertSame([self::text(BotText::AgencyExpiring, ['date' => Persian::date($later, withTime: true)])], $this->toAgent(), 'the new end is warned of once too');
    }

    public function testAJalaliMonthBelowTheMinimumStopsTheSellingOnceItIsOverAndBuyingItLiftsIt(): void
    {
        $this->wallet($this->agent, '5000000');
        // Set on 3 Mehr 1405 (Tehran): Mehr is not judged.
        $this->sellingTerms($this->bot, null, '500000');
        self::assertSame('1405-07', $this->bot->minimum_checked_month);

        Carbon::setTestNow('2026-10-21 12:00:00'); // 29 Mehr
        $this->telegram()->reset();
        $this->judge();
        self::assertSame([], $this->toAgent(), 'the month it was set in is neither warned of nor judged');

        Carbon::setTestNow('2026-11-19 12:00:00'); // 28 Aban, three days left on the shop's clock
        $this->judge();
        $this->judge();
        self::assertSame([self::text(BotText::AgencyMinimumShort, ['month' => 'آبان ۱۴۰۵', 'days' => '۳', 'purchased' => Money::format('0'), 'minimum' => Money::format('500000')])], $this->toAgent(), 'warned once');

        // 23:30 on 30 Aban in Tehran (still Aban), though in UTC it is the same evening: 100 GB at 3,000 Toman.
        Carbon::setTestNow('2026-11-21 20:00:00');
        $this->buyTraffic(100);
        self::assertSame(['1405-08', '300000.00'], [JalaliMonth::now()->key(), $this->service(AgencyStanding::class)->purchased($this->bot, JalaliMonth::now())]);

        Carbon::setTestNow('2026-11-21 21:00:00'); // 1 Azar, 00:30 in Tehran
        $this->telegram()->reset();
        $this->judge();
        $this->judge();
        self::assertSame([self::text(BotText::AgencySuspended, ['reason' => SellingSuspension::Minimum->words(), 'note' => ''])], $this->toAgent(), "Aban's 300,000 fell short: suspended, told once");
        self::assertSame([SellingSuspension::Minimum, '1405-08'], [$this->bot->refresh()->suspension_reason, $this->bot->minimum_checked_month]);
        self::assertSame(['agency:traffic'], $this->callbacksTo(self::AGENT_TELEGRAM_ID), 'with «💾 خرید حجم»');

        Carbon::setTestNow('2026-11-22 10:00:00');
        $this->telegram()->reset();
        $this->buyTraffic(100);
        self::assertSame(SellingSuspension::Minimum, $this->bot->refresh()->sellingSuspension(), "Azar's 300,000 so far: still short");
        $this->buyTraffic(100);
        self::assertNull($this->bot->refresh()->sellingSuspension(), "Azar's 600,000 reach it: it sells again at once");
        self::assertContains(self::text(BotText::AgencyResumed), $this->toAgent());
    }

    public function testAMonthThatReachedItsMinimumKeepsSelling(): void
    {
        $this->wallet($this->agent, '5000000');
        $this->sellingTerms($this->bot, null, '500000');

        Carbon::setTestNow('2026-11-01 12:00:00'); // 10 Aban
        $this->buyTraffic(200);
        Carbon::setTestNow('2026-11-19 12:00:00');
        $this->telegram()->reset();
        $this->judge();
        self::assertSame([], $this->toAgent(), 'no warning: Aban reached it');

        Carbon::setTestNow('2026-11-22 12:00:00');
        $this->judge();
        self::assertSame([null, '1405-08'], [$this->bot->refresh()->sellingSuspension(), $this->bot->minimum_checked_month], 'Aban judged, and it sells');
        self::assertSame([], $this->toAgent());
    }

    public function testAShortMonthSuspendsNothingWhenTheNextReachedTheMinimumBeforeTheTaskRan(): void
    {
        $this->wallet($this->agent, '5000000');
        $this->sellingTerms($this->bot, null, '500000');
        Carbon::setTestNow('2026-11-01 12:00:00'); // 10 Aban: 300,000 of it
        $this->buyTraffic(100);

        Carbon::setTestNow('2026-11-21 20:40:00'); // 1 Azar, 00:10 in Tehran: 600,000 before the hour's task
        $this->buyTraffic(200);
        $this->telegram()->reset();
        $this->judge();

        self::assertSame([null, '1405-08'], [$this->bot->refresh()->sellingSuspension(), $this->bot->minimum_checked_month], 'Aban judged; Azar already reached it');
        self::assertSame([], $this->toAgent(), 'no suspension told and lifted at once');
    }

    public function testEverySellingDoorIsShutWhileSuspendedAndOpensAgain(): void
    {
        $this->extrasOnSale();
        [$customer, $service, $paid] = CurrentBot::run($this->bot, function (): array {
            $this->extrasOnSale();
            $this->botSettings('test_accounts', ['test_enabled' => true, 'test_per_user' => 1, 'test_period_days' => 0, 'test_daily_limit' => 0, 'test_newcomers_only' => false]);
            $customer = $this->wallet($this->customer(), '500000');
            $service = $this->subscription($customer, $this->plan, $this->server, 'ali_1', ['expires_at' => now()->addDay(), 'auto_renew' => true, 'traffic_limit_bytes' => 10 * 1024 ** 3]);
            // Paid before the suspension: still delivered.
            $paid = $this->service(OrderService::class)->openPurchase($customer, $this->plan, $this->server);
            $this->service(OrderService::class)->markPaid($paid);

            return [$customer, $service, $paid];
        });
        $this->server->forceFill(['test_enabled' => true])->save();
        CurrentBot::run($this->bot, fn() => self::assertNotNull($this->service(CustomerExtras::class)->offer($service, ExtraKind::Traffic), 'extras on sale before'));

        $this->service(AgencyStanding::class)->suspendByHand($this->bot, 'تسویه نشده');
        self::assertSame([self::text(BotText::AgencySuspended, ['reason' => SellingSuspension::Manual->words(), 'note' => self::text(BotText::AdminNote, ['comment' => 'تسویه نشده'])])], $this->toAgent(), 'told, with the note');
        $this->bot->refresh();

        CurrentBot::run($this->bot, function () use ($customer, $service, $paid): void {
            $orders = $this->service(OrderService::class);
            foreach ([
                'a purchase' => fn() => $orders->openPurchase($customer, $this->plan, $this->server),
                'a top-up' => fn() => $orders->openTopUp($customer, 50000),
                'a gift of a service' => fn() => $orders->openGift($customer, $this->plan, $this->server),
                'an automatic renewal' => fn() => $orders->createRenewal($customer, $service, $this->plan),
            ] as $door => $open) {
                try {
                    $open();
                    self::fail("{$door} was ordered");
                } catch (SellingSuspendedException $e) {
                    self::assertSame(SellingSuspendedException::MESSAGE, $e->getMessage(), $door);
                }
            }
            self::assertSame(RenewalRefusal::SellingSuspended, $this->service(CustomerRenewal::class)->refusal($service));
            self::assertSame(['traffic' => null, 'time' => null, 'devices' => null], $this->service(CustomerExtras::class)->offersFor([$service])[$service->id]);
            self::assertSame([], $this->service(AutoRenewal::class)->due(), 'automatic renewal waits');
            try {
                $this->service(TestAccounts::class)->offer($customer);
                self::fail('a test was offered');
            } catch (TestAccountRefusedException $e) {
                self::assertSame(TestRefusal::Suspended, $e->reason);
            }
            try {
                $this->service(Rewards::class)->give($customer, Reward::plan($this->plan), 'test');
                self::fail('a service was given');
            } catch (RewardUnavailableException $e) {
                self::assertSame(RewardUnavailableException::SUSPENDED, $e->getMessage());
            }
            $this->service(Rewards::class)->give($customer, Reward::wallet(1000), 'test');
            self::assertSame('501000.00', $customer->refresh()->balance(), 'a prize in Toman is still given');
            try {
                $this->service(SubscriptionActions::class)->extend($service, $this->panelActor('@agent_shop_bot', PrincipalKind::Agent), ['days' => 1, 'traffic_gb' => 1]);
                self::fail('the agent extended a service');
            } catch (ValidationException $e) {
                self::assertSame([SellingSuspendedException::STAFF], $e->errors()['status']);
            }
            self::assertSame(SellingSuspendedException::STAFF, $this->service(ServerSelector::class)->judge(Plan::query()->get())[$this->plan->id], "the panel's reason");

            self::assertTrue($orders->deliver($paid));
            self::assertSame(OrderStatus::Fulfilled, $paid->refresh()->status, 'paid before: delivered');
        });
        self::assertSame(SubscriptionStatus::Active, $service->refresh()->status, 'its services run on');

        // The bot says so, politely, where it sells.
        $this->sendTo($this->bot, $this->tap(MainMenu::PLANS));
        self::assertSame(self::text(BotText::SellingPaused), $this->params(0)['text']);
        foreach ([PurchaseHandler::planCallback($this->plan->id), PurchaseHandler::serverCallback($this->plan->id, $this->server->id)] as $old) {
            $this->sendTo($this->bot, $this->tap($old));
            self::assertStringEndsWith(self::text(BotText::SellingPaused), $this->said()[0], "a plan's button of before: {$old}");
        }
        $this->sendTo($this->bot, $this->tap(RenewalHandler::checkoutCallback($service->id)));
        self::assertSame([self::text(BotText::SellingPaused)], $this->said(), 'a renewal');

        // The agent still buys traffic in the main shop.
        $traffic = $this->service(OrderService::class)->openTraffic($this->agent, 10);
        self::assertSame(OrderStatus::Pending, $traffic->status);

        $this->service(AgencyStanding::class)->resume($this->bot);
        $this->bot->refresh();
        CurrentBot::run($this->bot, function () use ($customer): void {
            self::assertSame(OrderStatus::Pending, $this->service(OrderService::class)->openPurchase($customer, $this->plan, $this->server)->status, 'resumed: it sells');
            self::assertCount(1, $this->service(ServerSelector::class)->offers(Plan::active()->get()));
        });
    }

    public function testTheBotSaysItsSellingIsPausedInTheAgentsOwnWordsAsTheMessageItIs(): void
    {
        $this->extrasOnSale();
        $service = CurrentBot::run($this->bot, function (): Subscription {
            $this->extrasOnSale();
            $this->botSettings('test_accounts', ['test_enabled' => true, 'test_per_user' => 1, 'test_period_days' => 0, 'test_daily_limit' => 0, 'test_newcomers_only' => false]);
            $this->service(BotTexts::class)->save(BotText::SellingPaused, '<b>فروش</b> فعلا متوقف است.');

            return $this->subscription($this->wallet($this->customer(), '500000'), $this->plan, $this->server, 'ali_1', ['expires_at' => now()->addDay(), 'traffic_limit_bytes' => 10 * 1024 ** 3]);
        });
        $this->server->forceFill(['test_enabled' => true])->save();
        // An amount of extra traffic awaited, typed once the selling stopped.
        $this->sendTo($this->bot, $this->tap(ExtrasHandler::screenCallback($service->id, ExtraKind::Traffic)));
        $this->service(AgencyStanding::class)->suspendByHand($this->bot, null);
        $this->bot->refresh();

        $toService = [SubscriptionHandler::serviceCallback($service->id)];
        foreach ([
            'extra traffic typed' => [$this->message('20'), $toService],
            'a renewal' => [$this->tap(RenewalHandler::checkoutCallback($service->id)), $toService],
            "a renewal from the menu's list" => [$this->tap(RenewalHandler::checkoutCallback($service->id, 1)), [MenuHandler::renewalsCallback(1)]],
            'extra time' => [$this->tap(ExtrasHandler::screenCallback($service->id, ExtraKind::Time)), $toService],
            'extra traffic paid from an old checkout' => [$this->tap(CallbackData::build(ExtrasHandler::checkoutCallback($service->id, ExtraKind::Traffic, 10), 1)), $toService],
            // The menu's own screen, under the menu's keyboard.
            'a free test' => [$this->tap(MainMenu::TEST), []],
        ] as $door => [$update, $back]) {
            $this->sendTo($this->bot, $update);
            self::assertSame(['<b>فروش</b> فعلا متوقف است.'], $this->said(), "{$door}: a message, its HTML as the agent wrote it");
            self::assertNull($this->popup(), "{$door}: no popup, which would show the tags or cut the words");
            self::assertSame($back, $this->callbacks(0), $door);
            self::assertNull(CurrentBot::run($this->bot, fn() => TelegramSession::query()->where('chat_id', self::CHAT)->value('state')), "{$door}: nothing awaited");
        }
    }

    public function testATopUpSaysTheSellingIsPausedAtItsFirstStep(): void
    {
        [$customer, $card] = CurrentBot::run($this->bot, fn(): array => [$this->customer(), $this->cardMethod()]);
        $topUp = CurrentBot::run($this->bot, fn() => $this->service(OrderService::class)->openTopUp($customer, 50000));
        $this->sendTo($this->bot, $this->tap(MainMenu::WALLET));
        self::assertContains(TopUpHandler::START, $this->callbacks(0), 'selling: the wallet offers it');
        // An amount awaited, typed once the selling stopped.
        $this->sendTo($this->bot, $this->tap(TopUpHandler::START));
        $this->service(AgencyStanding::class)->suspendByHand($this->bot, null);
        $this->bot->refresh();

        foreach ([
            'an amount typed' => $this->message('50000'),
            'the top-up of an old wallet screen' => $this->tap(TopUpHandler::START),
            'its amount' => $this->tap(TopUpHandler::checkoutCallback(50000)),
            'a way to pay it' => $this->tap(CallbackData::build(TopUpHandler::checkoutCallback(50000), $card->id)),
            '«پرداخت دوباره» of one ordered before' => $this->tap(PurchaseHandler::reopenCallback($topUp->id)),
        ] as $step => $update) {
            $this->sendTo($this->bot, $update);
            self::assertSame([self::text(BotText::SellingPaused)], $this->said(), $step);
            self::assertSame([MainMenu::WALLET], $this->callbacks(0), "{$step}: «بازگشت» to the wallet");
        }
        self::assertSame(1, CurrentBot::run($this->bot, fn(): int => Order::query()->count()), 'nothing more ordered');

        $this->sendTo($this->bot, $this->tap(MainMenu::WALLET));
        self::assertNotContains(TopUpHandler::START, $this->callbacks(0), 'suspended: the wallet offers none');
    }

    public function testAShopWithCategoriesSaysItsSellingIsPausedNotThatACategoryIsEmpty(): void
    {
        $category = CurrentBot::run($this->bot, function (): PlanCategory {
            $category = $this->category();
            $this->plan->forceFill(['category_id' => $category->id])->save();

            return $category;
        });
        $this->service(AgencyStanding::class)->suspendByHand($this->bot, null);
        $this->bot->refresh();

        $this->sendTo($this->bot, $this->tap(MainMenu::PLANS));
        self::assertSame(self::text(BotText::SellingPaused), $this->params(0)['text'], 'no list of empty categories');
        $this->sendTo($this->bot, $this->tap(MenuHandler::categoryCallback($category->id)));
        self::assertSame(self::text(BotText::SellingPaused), $this->params(0)['text'], 'a category of an old message');
    }

    public function testAServiceThatWouldRenewItselfIsRemindedOfItsEndWhileTheShopSellsNothing(): void
    {
        CurrentBot::run($this->bot, function (): void {
            $this->botSettings('reminders', ['expiry_reminder' => true, 'expiry_reminder_days' => 3, 'traffic_reminder' => false, 'traffic_reminder_percent' => 80]);
            $this->subscription($this->customer(['telegram_id' => 7070]), $this->plan, $this->server, 'ali_1', ['expires_at' => now()->addDays(2), 'auto_renew' => true]);
        });
        $remind = fn(): int => CurrentBot::run($this->bot->refresh(), fn(): int => $this->service(ServiceReminders::class)->send());
        self::assertSame(0, $remind(), 'selling: «تمدید خودکار» speaks for itself');

        $this->service(AgencyStanding::class)->suspendByHand($this->bot, null);
        self::assertSame(1, $remind(), 'suspended, nothing renews it: its customer is told it ends');
    }

    public function testOnlyTheOwnerLiftsTheirOwnSuspensionAndAnEndPassedIsExtendedInstead(): void
    {
        $standing = $this->service(AgencyStanding::class);
        $standing->suspendByHand($this->bot, null);
        $this->wallet($this->agent, '5000000');
        $this->sellingTerms($this->bot, null, '1000');
        $this->buyTraffic(100);
        self::assertSame(SellingSuspension::Manual, $this->bot->refresh()->sellingSuspension(), 'buying lifts no suspension by hand');

        $this->sellingTerms($this->bot, Carbon::now()->subHour());
        self::assertSame(SellingSuspension::Manual, $this->bot->refresh()->suspension_reason, "the owner's reason stands over an end's");
        try {
            $standing->resume($this->bot);
            self::fail('resumed past its end');
        } catch (ValidationException $e) {
            self::assertSame([AgencyStanding::EXTEND_FIRST], $e->errors()['status']);
        }

        $this->sellingTerms($this->bot, Carbon::now()->addMonth());
        self::assertSame(SellingSuspension::Manual, $this->bot->refresh()->sellingSuspension(), 'extending lifts no suspension by hand');
        $standing->resume($this->bot);
        self::assertNull($this->bot->refresh()->sellingSuspension());
        try {
            $standing->resume($this->bot);
            self::fail('resumed twice');
        } catch (ValidationException $e) {
            self::assertSame([AgencyStanding::NOT_SUSPENDED], $e->errors()['status']);
        }
    }

    public function testTheMainBotsAccountShowsTheTermsAndWhySellingStopped(): void
    {
        $this->wallet($this->agent, '5000000');
        $end = Carbon::parse('2026-12-01 12:00:00');
        $this->sellingTerms($this->bot, $end, '500000');
        $this->buyTraffic(50);
        $this->service(AgencyStanding::class)->suspendByHand($this->bot, null);

        $this->send($this->message('/start', chat: self::AGENT_TELEGRAM_ID));
        $this->send($this->tap(MainMenu::AGENCY, chat: self::AGENT_TELEGRAM_ID));

        $text = $this->said()[0];
        self::assertStringContainsString(self::text(BotText::AgencyExpiryLine, ['date' => Persian::date($end, withTime: true)]), $text);
        self::assertStringContainsString(self::text(BotText::AgencyMonthLine, ['month' => 'مهر ۱۴۰۵', 'purchased' => Money::format('150000'), 'minimum' => Money::format('500000')]), $text);
        self::assertStringContainsString(self::text(BotText::AgencySellingLine, ['reason' => SellingSuspension::Manual->words()]), $text);
    }

    /** The hourly task, as the scheduler runs it. */
    private function judge(): void
    {
        $this->service(AgencyStandingTask::class)->run();
    }

    /** `$gb` of traffic the agent buys in the main bot, paid from their wallet and delivered. */
    private function buyTraffic(int $gb): void
    {
        $order = $this->service(OrderService::class)->openTraffic($this->agent, $gb);
        $this->service(PaymentService::class)->createForOrder($order, $this->walletMethod());
        self::assertSame(OrderStatus::Fulfilled, $order->refresh()->status);
    }

    /** @return list<string> What the main bot told the agent since the last reset. */
    private function toAgent(): array
    {
        return $this->telegram()->sentTo(self::AGENT_TELEGRAM_ID);
    }

    /** @return list<string> The callback data under the last message to the chat. */
    private function callbacksTo(int $chat): array
    {
        return array_values(array_filter(array_column(array_merge(...$this->telegram()->buttonsSentTo($chat)), 'callback_data')));
    }

    /** How many agency reports in the main group carry `$words`. */
    private function agencyReports(string $words): int
    {
        return ReportMessage::query()->where('topic', Topic::Agency->value)->where('text', 'like', "%{$words}%")->count();
    }
}
