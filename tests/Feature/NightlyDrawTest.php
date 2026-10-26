<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Notifications\Enums\NoticeType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Orders\Enums\OrderPricing;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Providers\Models\Server;
use App\Modules\Rewards\Enums\RewardKind;
use App\Modules\Rewards\Models\LotteryDraw;
use App\Modules\Rewards\Models\LotteryWinner;
use App\Modules\Rewards\Services\Lottery;
use App\Modules\Rewards\Support\WeightedDraw;
use App\Modules\Rewards\Tasks\NightlyDrawTask;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\Topic;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\User;
use App\Modules\Users\Models\WalletTransaction;
use App\Support\LocalTime;
use App\Support\Money;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Tests\DatabaseTestCase;
use Tests\Fakes\FakeProvider;

/**
 * The nightly draw: due once a day after the shop's hour, entries paid since the draw before it — the hour moved earlier
 * or later entering none twice and skipping none — (per order or per buyer), never admins, banned customers or
 * (optionally) agents; distinct weighted winners given the prize (one who cannot be given it passed over), told and
 * reported; a draw without entries tells nobody.
 */
final class NightlyDrawTest extends DatabaseTestCase
{
    private Server $server;
    private Plan $plan;
    private User $ali;
    private User $sara;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutQr();
        $this->telegram();
        $this->server = $this->sellingServer();
        $this->plan = $this->plan(['price' => '120000.00'], $this->server);
        $this->ali = $this->customer(['username' => 'ali']);
        $this->sara = $this->customer(['telegram_id' => 2002, 'username' => 'sara']);
        $this->lotteryOn();
        Carbon::setTestNow('2026-10-10 22:30:00');
    }

    public function testNothingIsDrawnBeforeTheHourNorWhileSwitchedOff(): void
    {
        $this->entry($this->ali, '2026-10-10 12:00:00');

        Carbon::setTestNow('2026-10-10 21:59:00');
        self::assertNull($this->draw());

        Carbon::setTestNow('2026-10-10 22:30:00');
        $this->lotteryOn(['lottery_enabled' => false]);
        self::assertNull($this->draw());
        self::assertSame(0, LotteryDraw::query()->count());
    }

    public function testADayIsDrawnOnceHoweverManyRunsAsk(): void
    {
        $this->entry($this->ali, '2026-10-10 12:00:00');

        $draw = $this->draw() ?? self::fail('the draw was due');
        self::assertSame(['2026-10-10', 1], [$draw->day, $draw->entries]);
        self::assertNull($this->draw(), 'drawn already');
        $this->service(NightlyDrawTask::class)->run();
        self::assertSame(1, LotteryDraw::query()->count());
        self::assertSame(1, LotteryWinner::query()->count());

        Carbon::setTestNow('2026-10-11 22:00:00');
        self::assertNotNull($this->draw(), 'the next day draws again');
    }

    public function testTwoRunsAtOnceDrawOnce(): void
    {
        $this->entry($this->ali, '2026-10-10 12:00:00');

        // Another run inserts the day's draw between this one's look and its insert: the row's unique index decides.
        $drawn = $this->whileListening(
            'eloquent.creating: ' . LotteryDraw::class,
            static fn(LotteryDraw $draw) => LotteryDraw::query()->insert(['bot_id' => 1, 'day' => $draw->day, 'entries' => 0, 'prize_kind' => 'wallet', 'created_at' => now()]),
            fn() => $this->draw(),
        );

        self::assertNull($drawn);
        self::assertSame(1, LotteryDraw::query()->count());
        self::assertSame(0, LotteryWinner::query()->count(), 'nobody given anything twice');
    }

    public function testTheEntriesAreTheOrdersPaidInTheWindow(): void
    {
        $this->entry($this->ali, '2026-10-09 21:59:59');
        $this->entry($this->ali, '2026-10-09 22:00:00');
        $this->entry($this->sara, '2026-10-10 21:59:59');
        $this->entry($this->sara, '2026-10-10 22:10:00');
        $this->entry($this->sara, '2026-10-10 12:00:00', ['status' => OrderStatus::Refunded]);
        $this->entry($this->sara, '2026-10-10 12:00:00', ['amount' => '0', 'pricing' => OrderPricing::Gift]);
        $this->entry($this->sara, '2026-10-10 12:00:00', ['type' => OrderType::WalletTopUp, 'plan_id' => null, 'server_id' => null]);

        self::assertSame([$this->ali->id => 1, $this->sara->id => 1], $this->dayBefore(), 'within the window, paid and kept, a purchase at a price — a refund, a gift and a top-up are none');
    }

    public function testEachDrawCountsWhatWasPaidSinceTheOneBeforeWhereverTheHourMoved(): void
    {
        $reza = $this->customer(['telegram_id' => 3003, 'username' => 'reza']);
        $this->entry($this->ali, '2026-10-09 12:00:00');
        $this->entry($this->ali, '2026-10-10 21:00:00');
        $first = $this->draw() ?? self::fail('the draw was due');
        self::assertSame(1, $first->entries, "the shop's first: the day before it");

        // The hour moved earlier, to 20: what the last draw counted is not counted again.
        $this->lotteryOn(['lottery_hour' => 20]);
        $this->entry($this->sara, '2026-10-11 10:00:00');
        Carbon::setTestNow('2026-10-11 20:10:00');
        $second = $this->draw() ?? self::fail('due at 20');
        self::assertSame([[$this->sara->id], 1], [$second->winners()->pluck('user_id')->all(), $second->entries], "Ali's order of 21:00 entered last night's draw, not this one too");

        // Then later, to 23: what was paid between this one and 23:00 yesterday is not skipped.
        $this->lotteryOn(['lottery_hour' => 23]);
        $this->entry($reza, '2026-10-11 21:00:00');
        Carbon::setTestNow('2026-10-12 23:05:00');
        $third = $this->draw() ?? self::fail('due at 23');
        self::assertSame([[$reza->id], 1], [$third->winners()->pluck('user_id')->all(), $third->entries]);
    }

    public function testAPaymentSettlingAsTheDrawIsMadeEntersTheNextOne(): void
    {
        $this->entry($this->ali, '2026-10-10 22:28:59');
        $this->entry($this->sara, '2026-10-10 22:29:30');

        self::assertSame(1, ($this->draw() ?? self::fail('the draw was due'))->entries, 'its window ends a minute before the draw');

        Carbon::setTestNow('2026-10-11 22:30:00');
        $next = $this->draw() ?? self::fail('the next day is due');
        self::assertSame([[$this->sara->id], 1], [$next->winners()->pluck('user_id')->all(), $next->entries], 'in the next window, never neither');
    }

    public function testAfterDrawsStoppedAWhileTheWindowReachesTwoDaysBack(): void
    {
        $this->entry($this->ali, '2026-10-10 12:00:00');
        self::assertNotNull($this->draw());
        $this->entry($this->ali, '2026-10-12 12:00:00');
        $this->entry($this->sara, '2026-10-19 12:00:00');

        Carbon::setTestNow('2026-10-20 22:30:00');
        $draw = $this->draw() ?? self::fail('the draw was due');

        self::assertSame([[$this->sara->id], 1], [$draw->winners()->pluck('user_id')->all(), $draw->entries], 'a week without draws: only what was paid in the two days before this one');
    }

    public function testADrawBeforeWithoutATimeLeavesTheWindowTwoDaysBack(): void
    {
        // Written outside AmoBot, the draw before has no time for this one's window to begin at.
        LotteryDraw::query()->insert(['bot_id' => 1, 'day' => '2026-10-09', 'entries' => 0, 'prize_kind' => 'wallet', 'created_at' => null]);
        $this->entry($this->ali, '2026-10-08 22:00:00');
        $this->entry($this->sara, '2026-10-09 12:00:00');

        $draw = $this->draw() ?? self::fail('the draw was due');

        self::assertSame([[$this->sara->id], 1], [$draw->winners()->pluck('user_id')->all(), $draw->entries], 'only what was paid in the two days before this one');
    }

    public function testBotAdminsBannedCustomersAndAgentsDoNotEnter(): void
    {
        $admin = $this->admin(['telegram_id' => 4004]);
        $banned = $this->customer(['telegram_id' => 5005, 'status' => UserStatus::Banned]);
        $agent = $this->agent(overrides: ['telegram_id' => 6006]);
        foreach ([$this->ali, $admin, $banned, $agent] as $buyer) {
            $this->entry($buyer, '2026-10-10 12:00:00');
        }

        self::assertSame([$this->ali->id], array_keys($this->dayBefore()));

        $this->lotteryOn(['lottery_exclude_agents' => false]);
        self::assertSame([$this->ali->id, $agent->id], array_keys($this->dayBefore()), 'agents in, while the settings let them');
    }

    public function testEveryOrderIsAnEntryOrEveryBuyerOne(): void
    {
        foreach (['10:00', '11:00', '12:00'] as $at) {
            $this->entry($this->ali, "2026-10-10 {$at}:00");
        }
        $this->entry($this->sara, '2026-10-10 12:00:00');

        self::assertSame([$this->ali->id => 3, $this->sara->id => 1], $this->dayBefore());
        $this->lotteryOn(['lottery_entries' => 'buyer']);
        self::assertSame([$this->ali->id => 1, $this->sara->id => 1], $this->dayBefore());

        // A roll of 1 to 4 lands on Ali's three entries, then on Sara's one.
        $entries = [$this->ali->id => 3, $this->sara->id => 1];
        self::assertSame([$this->ali->id, $this->ali->id, $this->ali->id, $this->sara->id], array_map(static fn(int $roll): int => WeightedDraw::pick($entries, $roll), [1, 2, 3, 4]));
    }

    public function testWinnersAreDistinctAndEachIsGivenThePrizeAndTold(): void
    {
        $this->entry($this->ali, '2026-10-10 10:00:00');
        $this->entry($this->ali, '2026-10-10 11:00:00');
        $this->entry($this->sara, '2026-10-10 12:00:00');
        $this->lotteryOn(['lottery_winners' => 5, 'lottery_wallet' => '30000']);

        $draw = $this->draw() ?? self::fail('the draw was due');

        self::assertSame(3, $draw->entries);
        $winners = LotteryWinner::query()->where('lottery_draw_id', $draw->id)->pluck('user_id')->all();
        self::assertEqualsCanonicalizing([$this->ali->id, $this->sara->id], $winners, 'two buyers, two winners — a customer once');
        foreach ([$this->ali, $this->sara] as $winner) {
            self::assertSame('30000.00', $winner->balance());
            self::assertSame(Lottery::REASON, WalletTransaction::query()->where('user_id', $winner->id)->value('description'));
            self::assertSame(self::text(BotText::LotteryWon, ['draw_prize' => Money::format('30000') . ' شارژ کیف پول']), Notification::query()->where('user_id', $winner->id)->where('type', NoticeType::LotteryWon->value)->sole()->text);
        }
        self::assertSame(Money::format('30000') . ' شارژ کیف پول', LotteryWinner::query()->value('outcome'));
    }

    public function testAWinnerWhoCannotBeGivenThePrizeIsPassedOver(): void
    {
        $this->entry($this->ali, '2026-10-10 10:00:00');
        $this->entry($this->sara, '2026-10-10 11:00:00');
        $service = $this->subscription($this->sara, $this->plan, $this->server, 'sara_1', ['expires_at' => now()->addDays(10)]);
        FakeProvider::mirror($service);
        $this->lotteryOn(['lottery_prize' => 'traffic', 'lottery_traffic_gb' => 5]);

        $draw = $this->draw() ?? self::fail('the draw was due');

        self::assertSame([$this->sara->id], $draw->winners()->pluck('user_id')->all(), 'Ali has no running service to take the traffic: the next drawn wins');
        self::assertSame(Traffic::bytesOfGb(35), $service->refresh()->traffic_limit_bytes);
        self::assertSame($service->id, LotteryWinner::query()->value('subscription_id'));
        self::assertSame(0, Notification::query()->where('user_id', $this->ali->id)->where('type', NoticeType::LotteryWon->value)->count());
    }

    public function testDaysAndAPlansServiceArePrizesToo(): void
    {
        $this->entry($this->ali, '2026-10-10 10:00:00');
        $service = $this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['expires_at' => now()->addDays(10)]);
        FakeProvider::mirror($service);
        $this->lotteryOn(['lottery_prize' => 'days', 'lottery_days' => 7]);

        $this->draw();
        self::assertSame(now()->addDays(17)->startOfMinute()->toIso8601String(), $service->refresh()->expires_at?->startOfMinute()->toIso8601String());

        Carbon::setTestNow('2026-10-11 22:30:00');
        $this->entry($this->ali, '2026-10-11 10:00:00');
        $this->lotteryOn(['lottery_prize' => 'plan', 'lottery_plan_id' => $this->plan->id]);
        $draw = $this->draw() ?? self::fail('the draw was due');

        self::assertSame([RewardKind::Plan, $this->plan->id], [$draw->prize_kind, $draw->prize_plan_id]);
        $winner = $draw->winners()->sole();
        $order = $winner->order ?? self::fail('a plan is given by an order');
        self::assertSame([OrderStatus::Fulfilled, '0.00', OrderPricing::Gift], [$order->status, $order->amount, $order->pricing]);
        self::assertSame($order->subscription_id, $winner->subscription_id);
    }

    public function testAPlansServiceWhoseDeliveryBrokeIsWonOnceNeverDrawnAgain(): void
    {
        $this->entry($this->ali, '2026-10-10 10:00:00');
        $this->entry($this->sara, '2026-10-10 11:00:00');
        $this->lotteryOn(['lottery_prize' => 'plan', 'lottery_plan_id' => $this->plan->id]);
        FakeProvider::$onCall = static fn() => throw new \RuntimeException('A fault of the shop\'s own.');

        $draw = $this->draw() ?? self::fail('the draw was due');

        $winner = $draw->winners()->sole();
        self::assertSame(OrderStatus::Failed, $winner->order?->status, 'won: the service waits for support\'s retry');
        self::assertSame(1, Order::query()->where('pricing', OrderPricing::Gift->value)->count(), 'one winner, one service — nobody drawn in their place');
    }

    public function testADrawWithoutEntriesTellsNobody(): void
    {
        $this->reportGroup();
        $this->entry($this->ali, '2026-10-09 12:00:00');

        $draw = $this->draw() ?? self::fail('the draw was due');

        self::assertSame(0, $draw->entries);
        self::assertSame(0, LotteryWinner::query()->count());
        self::assertSame(0, Notification::query()->count());
        self::assertSame(0, ReportMessage::query()->where('topic', Topic::Purchases->value)->count());
    }

    public function testTheReportGroupHearsOfTheDrawUnderPurchases(): void
    {
        $this->reportGroup();
        $this->entry($this->ali, '2026-10-10 10:00:00');

        $this->draw();

        $report = ReportMessage::query()->where('topic', Topic::Purchases->value)->latest('id')->firstOrFail();
        self::assertStringContainsString('قرعه‌کشی شبانه', $report->text);
        self::assertStringContainsString('@ali', $report->text);
        self::assertStringContainsString(Money::format('50000'), $report->text);
    }

    public function testTheHourIsTheShopsOwn(): void
    {
        LocalTime::use('Asia/Tehran');
        try {
            $this->entry($this->ali, '2026-10-10 12:00:00');

            Carbon::setTestNow('2026-10-10 18:20:00');
            self::assertNull($this->draw(), '21:50 in Tehran');

            Carbon::setTestNow('2026-10-10 18:40:00');
            $draw = $this->draw() ?? self::fail('22:10 in Tehran: due');
            self::assertSame('2026-10-10', $draw->day);
            self::assertSame(1, $draw->entries, 'the window ends just before the draw, at 18:39 UTC');

            Carbon::setTestNow('2026-10-10 21:00:00');
            self::assertNull($this->draw(), '00:30 of the next day in Tehran: not its hour yet');
        } finally {
            LocalTime::use('UTC');
        }
    }

    public function testAnAgentsShopDrawsAmongItsOwnBuyers(): void
    {
        $this->entry($this->ali, '2026-10-10 12:00:00');
        $bot = $this->agentBot(traffic: 500);
        self::assertNotNull($this->draw());

        CurrentBot::run($bot, function (): void {
            $reza = $this->customer(['telegram_id' => 3003]);
            $boss = $this->admin(['telegram_id' => 4004]);
            self::assertNull($this->draw(), "switched off in the agent's shop: the main shop's settings are not theirs");

            $this->lotteryOn(['lottery_winners' => 2]);
            $this->entry($reza, '2026-10-10 12:00:00');
            $this->entry($boss, '2026-10-10 12:00:00');
            $draw = $this->draw() ?? self::fail("the agent's shop draws its own day");
            self::assertSame([$reza->id], $draw->winners()->pluck('user_id')->all(), "its own customers — never its bot's admins");
            self::assertSame('50000.00', $reza->balance());
        });
    }

    /**
     * A purchase the customer paid at `$paidAt` (UTC) and was delivered — `$overrides` the order's columns.
     *
     * @param array<string, mixed> $overrides
     */
    private function entry(User $user, string $paidAt, array $overrides = []): void
    {
        $order = $this->purchaseOrder($user, $this->plan, $this->server, $overrides + ['status' => OrderStatus::Fulfilled, 'fulfilled_at' => $paidAt]);
        $this->cardPayment($order, $this->cardMethod(), ['status' => PaymentStatus::Paid, 'paid_at' => $paidAt]);
    }

    /**
     * The current shop's draw of today, when due (Lottery::drawIfDue()).
     *
     * @phpstan-impure
     */
    private function draw(): ?LotteryDraw
    {
        return $this->lottery()->drawIfDue();
    }

    private function lottery(): Lottery
    {
        return $this->service(Lottery::class);
    }

    /**
     * The entries of a window from 22:00 on 9 October to 22:00 on the 10th (UTC).
     *
     * @return array<int, int>
     */
    private function dayBefore(): array
    {
        return $this->lottery()->entries(Carbon::parse('2026-10-09 22:00:00'), Carbon::parse('2026-10-10 22:00:00'));
    }
}
