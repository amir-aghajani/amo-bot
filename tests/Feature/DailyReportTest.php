<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Admin\Services\DashboardStats;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderPricing;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Providers\Models\Server;
use App\Modules\Settings\Services\ScheduleSettings;
use App\Modules\Settings\Services\Settings;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Telegram\Api\Limits;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\DailyFigures;
use App\Modules\Telegram\Reports\DailyReport;
use App\Modules\Telegram\Reports\ReportSender;
use App\Modules\Telegram\Reports\ShopReports;
use App\Modules\Telegram\Reports\Topic;
use App\Modules\Telegram\Tasks\DailyReportTask;
use App\Modules\Telegram\Texts\TelegramHtml;
use App\Modules\Users\Models\User;
use App\Support\LocalTime;
use App\Support\Money;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\DatabaseTestCase;

/**
 * «گزارش روزانه» (Telegram\Reports\DailyReport): the shop's day before — one whole day of its clock, the dashboard's
 * figures for it — queued once to its group's topic from the hour it set; nothing read without a group or with the topic
 * off; its income said apart from its sales, its plans and servers sold most, its customers; the agents' bots' sales in
 * the main shop's report alone, an agent's own report its shop's alone; a quiet day said so; any number of agents and
 * long names inside one message — agents folded into how many more first —; no direction override of a name turning a
 * line around; its row, the day's mark, kept through a day of 25 hours.
 */
final class DailyReportTest extends DatabaseTestCase
{
    private Server $server;
    private Plan $plan;
    private User $ali;
    private PaymentMethod $card;

    protected function setUp(): void
    {
        parent::setUp();

        LocalTime::use('Asia/Tehran');
        $this->tehran('1405-09-06', '00:05');
        $this->server = $this->sellingServer('آلمان');
        $this->plan = $this->plan(['name' => 'یک‌ماهه', 'price' => '100000.00'], $this->server);
        $this->ali = $this->customer(['username' => 'ali']);
        $this->card = $this->cardMethod();
    }

    protected function tearDown(): void
    {
        LocalTime::use('UTC');

        parent::tearDown();
    }

    public function testTheDayBeforeGoesToItsTopicOnceWithItsIncomeApartFromItsSales(): void
    {
        $this->reportGroup();
        $monthly = $this->plan(['name' => 'دوماهه', 'price' => '180000.00'], $this->server);
        // The day is the shop's: 00:15 of it is still the evening before in UTC.
        $this->paid(OrderType::Purchase, '100000', '00:15');
        $this->paid(OrderType::Purchase, '100000', '10:00');
        $this->paid(OrderType::Purchase, '180000', '11:00', order: ['plan_id' => $monthly->id]);
        $this->paid(OrderType::Renewal, '100000', '12:00', method: $this->walletMethod());
        $this->paid(OrderType::ExtraTime, '7000', '13:00', order: ['plan_id' => $this->plan->id]);
        $this->paid(OrderType::WalletTopUp, '200000', '14:00', order: ['plan_id' => null, 'server_id' => null]);
        // Refunded: no sale — but its money stayed in the shop (the customer's wallet), as the dashboard counts it.
        $this->paid(OrderType::Purchase, '50000', '15:00', status: PaymentStatus::Refunded, orderStatus: OrderStatus::Refunded);
        // The days around it count for neither.
        $this->paid(OrderType::Purchase, '70000', '23:59', day: '1405-09-04');
        $this->paid(OrderType::Purchase, '90000', '00:01', day: '1405-09-06');
        $this->customer(['username' => 'newcomer', 'telegram_id' => 5501])->forceFill(['created_at' => $this->at('1405-09-05', '09:00')])->save();

        self::assertTrue($this->daily()->sendIfDue());
        self::assertFalse($this->daily()->sendIfDue(), 'once a day, however many runs ask');
        $this->service(DailyReportTask::class)->run();

        $report = ReportMessage::query()->where('topic', Topic::Daily->value)->sole();
        self::assertSame('daily:2026-11-26', $report->ref, "the shop's day before, 5 Azar 1405");
        $text = $report->text;
        self::assertStringContainsString('📊 <b>گزارش روزانه</b> — ۵ آذر ۱۴۰۵', $text);
        self::assertStringContainsString('💰 <b>درآمد: ۶۳۷٬۰۰۰ تومان</b>', $text, 'card payments and the top-up, the refunded one too; never the wallet-paid renewal');
        self::assertStringContainsString('📈 ۸۱۰٪ بیشتر از روز قبل (۷۰٬۰۰۰ تومان)', $text);
        self::assertStringContainsString("💳 به تفکیک روش: کارت به کارت (ملت)\u{200F}: ۶۳۷٬۰۰۰ تومان", $text);
        self::assertStringContainsString('🛒 خرید سرویس: ۳ سفارش، ۳۸۰٬۰۰۰ تومان', $text);
        self::assertStringContainsString('🔄 تمدید: ۱ سفارش، ۱۰۰٬۰۰۰ تومان', $text, 'wallet-paid, still a sale');
        self::assertStringContainsString('➕ حجم، زمان و کاربر اضافه: ۱ سفارش، ۷٬۰۰۰ تومان', $text);
        self::assertStringContainsString('💰 شارژ کیف پول: ۱ سفارش، ۲۰۰٬۰۰۰ تومان', $text);
        self::assertStringNotContainsString('حجم نمایندگی', $text, 'a line nothing was sold in is left out');
        self::assertStringContainsString("\u{200F}۱. یک‌ماهه\u{200F} — ۳ فروش، ۳۰۰٬۰۰۰ تومان\n\u{200F}۲. دوماهه\u{200F} — ۱ فروش، ۱۸۰٬۰۰۰ تومان", $text, 'purchases and renewals, the most sold first, each line right to left');
        self::assertStringContainsString("🌍 سرورهای پرفروش: آلمان\u{200F} (۴)", $text);
        self::assertStringContainsString('👥 کاربران تازه: ۱، خریداران: ۱، اکانت تست: ۰', $text);
        self::assertStringNotContainsString('🤝', $text, 'no agent sold anything');

        $chart = array_column($this->service(DashboardStats::class)->summary(7)['series'], 'revenue', 'date');
        self::assertSame('637000.00', $chart['2026-11-26'], "the dashboard's figure for that day");
    }

    public function testALatePaymentThatWentToTheWalletIsIncomeButNoSecondSale(): void
    {
        $this->reportGroup();
        $order = $this->paid(OrderType::Purchase, '100000', '10:00');
        // Its online payment came after the card paid it: the money went to the customer's wallet.
        (new Payment())->forceFill([
            'order_id' => $order->id,
            'payment_method_id' => $this->card->id,
            'status' => PaymentStatus::Paid,
            'amount' => '100000',
            'invoice' => ['to_wallet' => true],
            'paid_at' => $this->at('1405-09-05', '10:30'),
        ])->save();

        $this->daily()->sendIfDue();

        $text = ReportMessage::query()->where('topic', Topic::Daily->value)->sole()->text;
        self::assertStringContainsString('💰 <b>درآمد: ۲۰۰٬۰۰۰ تومان</b>', $text, 'both came in');
        self::assertStringContainsString('🛒 خرید سرویس: ۱ سفارش، ۱۰۰٬۰۰۰ تومان', $text, 'one sale');
    }

    public function testNothingIsReadBeforeTheHourWithoutAGroupOrWithTheTopicOff(): void
    {
        $this->paid(OrderType::Purchase, '100000', '10:00');
        $settings = $this->service(Settings::class);
        self::assertSame([], self::figuresRead($this->queriesOf(fn() => self::assertFalse($this->daily()->sendIfDue(), 'no group'))), 'and nothing read');

        $this->reportGroup();
        $settings->set('reports.topic.daily', false);
        self::assertSame([], self::figuresRead($this->queriesOf(fn() => self::assertFalse($this->daily()->sendIfDue(), 'the topic off'))), 'nothing read with the topic off');
        $settings->set('reports.topic.daily', true);

        $settings->set('reports.daily_hour', 9);
        $this->tehran('1405-09-06', '08:59');
        self::assertSame([], self::figuresRead($this->queriesOf(fn() => self::assertFalse($this->daily()->sendIfDue(), 'before its hour'))), 'nothing read before its hour');
        $this->tehran('1405-09-06', '09:00');
        self::assertNotSame([], self::figuresRead($this->queriesOf(fn() => self::assertTrue($this->daily()->sendIfDue(), 'from it: still the day before'))), 'read once due');
        self::assertSame('daily:2026-11-26', ReportMessage::query()->where('topic', Topic::Daily->value)->sole()->ref);
    }

    public function testAGiftIsNoSale(): void
    {
        $this->reportGroup();
        // A prize: a free service of the plan, settled by the wallet at no price.
        $this->paid(OrderType::Purchase, '0', '10:00', method: $this->walletMethod(), order: ['pricing' => OrderPricing::Gift]);
        $agentShop = $this->agentShop('gift_agent_bot', 'رضا', 6101);
        CurrentBot::run($agentShop, fn() => $this->paid(OrderType::Purchase, '0', '10:00', method: $this->walletMethod(), user: $this->customer(['telegram_id' => 7101]), order: ['pricing' => OrderPricing::Gift]));

        $this->daily()->sendIfDue();

        $text = ReportMessage::query()->where('topic', Topic::Daily->value)->sole()->text;
        self::assertStringContainsString('💤 در این روز فروشی ثبت نشد.', $text);
        self::assertStringNotContainsString('🛍', $text);
        self::assertStringNotContainsString('پرفروش', $text);
        self::assertStringContainsString('👥 کاربران تازه: ۰، خریداران: ۰، اکانت تست: ۰', $text, 'no buyer');
        self::assertStringNotContainsString('🤝', $text, "an agent's gift is no sale of theirs either");

        // Beside a sale of the same plan, the gift is no second one.
        $this->paid(OrderType::Purchase, '100000', '10:00', day: '1405-09-04', user: $this->customer(['telegram_id' => 7102]));
        $this->paid(OrderType::Purchase, '0', '11:00', day: '1405-09-04', method: $this->walletMethod(), order: ['pricing' => OrderPricing::Gift]);
        $figures = $this->daily()->figures($this->at('1405-09-04', '12:00'));
        self::assertSame(['purchase' => ['count' => 1, 'amount' => '100000.00', 'bytes' => 0]], $figures->sales);
        self::assertSame([['name' => 'یک‌ماهه', 'count' => 1, 'amount' => '100000.00']], $figures->plans);
        self::assertSame([['name' => 'آلمان', 'count' => 1]], $figures->servers);
        self::assertSame(1, $figures->buyers);
    }

    public function testASaleOfNoPlanIsCalledSo(): void
    {
        $this->paid(OrderType::Purchase, '90000', '10:00', order: ['plan_id' => null]);

        self::assertSame([['name' => 'سرویس دلخواه یا پلن حذف‌شده', 'count' => 1, 'amount' => '90000.00']], $this->daily()->figures($this->at('1405-09-05', '12:00'))->plans);
    }

    public function testIncomeWithNoSaleIsSaidAndTheSalesSayNone(): void
    {
        $this->reportGroup();
        // Refunded: no sale, but its money came in (and stayed in the customer's wallet).
        $this->paid(OrderType::Purchase, '50000', '15:00', status: PaymentStatus::Refunded, orderStatus: OrderStatus::Refunded);

        $this->daily()->sendIfDue();

        $text = ReportMessage::query()->where('topic', Topic::Daily->value)->sole()->text;
        self::assertStringContainsString('💰 <b>درآمد: ۵۰٬۰۰۰ تومان</b>', $text);
        self::assertStringContainsString('↗️ روز قبل: ۰ تومان', $text);
        self::assertStringContainsString('💤 در این روز فروشی ثبت نشد.', $text, 'no sale, whatever came in');
        self::assertStringNotContainsString('🛍', $text);
    }

    public function testLessIncomeThanTheDayBeforeIsSaidSo(): void
    {
        $this->reportGroup();
        $this->paid(OrderType::Purchase, '200000', '10:00', day: '1405-09-04');
        $this->paid(OrderType::Purchase, '100000', '10:00');

        $this->daily()->sendIfDue();

        self::assertStringContainsString('📉 ۵۰٪ کمتر از روز قبل (۲۰۰٬۰۰۰ تومان)', ReportMessage::query()->where('topic', Topic::Daily->value)->sole()->text);
    }

    public function testWhatWaitsOnSupportAndTheServicesRunningButTests(): void
    {
        $this->reportGroup();
        // Today's, as they stand now: a receipt to review, an order stuck after its payment, a ticket open, a review.
        $this->paid(OrderType::Purchase, '100000', '00:01', day: '1405-09-06', status: PaymentStatus::AwaitingReview, orderStatus: OrderStatus::Pending);
        $this->paid(OrderType::Purchase, '100000', '00:02', day: '1405-09-06', orderStatus: OrderStatus::Failed);
        $this->ticket($this->ali);
        $this->review($this->ali);
        $this->subscription($this->ali, $this->plan, $this->server, 'running');
        $this->subscription($this->ali, $this->plan, $this->server, 'trial', ['is_test' => true]);
        $this->subscription($this->ali, $this->plan, $this->server, 'ended', ['status' => SubscriptionStatus::Expired]);

        $this->daily()->sendIfDue();

        $text = ReportMessage::query()->where('topic', Topic::Daily->value)->sole()->text;
        self::assertStringContainsString('📦 سرویس‌های فعال: ۱', $text, 'a test account is no service running, an ended one neither');
        self::assertStringContainsString('⏳ منتظر شما: ۱ رسید برای بررسی، ۱ سفارش گیرکرده، ۱ تیکت باز، ۱ نظر برای بررسی', $text);
    }

    /**
     * A day of a zone whose clock moves for daylight saving is its own whole day: each payment in one day alone, a day's
     * end the next one's start — a midnight skipped (Cairo) or repeated (Havana) too —, and a day's income the next
     * one's day before.
     *
     * @param array<string, string> $payments Amount by when it was paid, UTC
     * @param array<string, string> $days Income by day, on the zone's clock, in order
     */
    #[DataProvider('daylightSaving')]
    public function testADayOfAZoneThatMovesItsClockIsItsOwn(string $zone, array $payments, array $days): void
    {
        LocalTime::use($zone);
        foreach ($payments as $at => $amount) {
            $this->paid(OrderType::Purchase, $amount, '', at: Carbon::parse($at, 'UTC'));
        }

        $before = null;
        foreach ($days as $day => $income) {
            $figures = $this->daily()->figures(Carbon::parse("{$day} 12:00", $zone));
            self::assertSame($day, $figures->day->format('Y-m-d'));
            self::assertSame($income, $figures->income, "{$zone} {$day}");
            self::assertSame($income, $figures->sales['purchase']['amount'] ?? null, "{$zone} {$day}: its sales");
            if ($before !== null) {
                self::assertSame($before, $figures->incomeBefore, "{$zone} {$day}: the day before");
            }
            $before = $income;
        }
    }

    /** @return iterable<string, array{string, array<string, string>, array<string, string>}> */
    public static function daylightSaving(): iterable
    {
        // 2026-03-29 02:00 → 03:00 and 2026-10-25 03:00 → 02:00: midnight untouched, the payments at 23:30.
        yield 'Berlin, spring' => ['Europe/Berlin', [
            '2026-03-28 22:30' => '1000', // 23:30 on the 28th
            '2026-03-29 21:30' => '2000', // 23:30 on the 29th, a day of 23 hours
            '2026-03-29 22:30' => '4000', // 00:30 on the 30th
        ], ['2026-03-28' => '1000.00', '2026-03-29' => '2000.00', '2026-03-30' => '4000.00']];
        yield 'Berlin, autumn' => ['Europe/Berlin', [
            '2026-10-24 21:30' => '1000', // 23:30 on the 24th
            '2026-10-25 22:30' => '2000', // 23:30 on the 25th, a day of 25 hours
            '2026-10-25 23:30' => '4000', // 00:30 on the 26th
        ], ['2026-10-24' => '1000.00', '2026-10-25' => '2000.00', '2026-10-26' => '4000.00']];
        // 2026-04-24 00:00 → 01:00: the 24th begins at 01:00 and has 23 hours.
        yield 'Cairo, midnight skipped' => ['Africa/Cairo', [
            '2026-04-22 22:30' => '1000', // 00:30 on the 23rd
            '2026-04-23 21:30' => '2000', // 23:30 on the 23rd
            '2026-04-23 22:00' => '4000', // 01:00 on the 24th, its first moment
            '2026-04-24 20:30' => '8000', // 23:30 on the 24th
            '2026-04-24 21:30' => '16000', // 00:30 on the 25th
        ], ['2026-04-23' => '3000.00', '2026-04-24' => '12000.00', '2026-04-25' => '16000.00']];
        // 2026-11-01 01:00 → 00:00: the 1st's first hour comes twice, and both are the 1st's.
        yield 'Havana, midnight repeated' => ['America/Havana', [
            '2026-11-01 03:30' => '1000', // 23:30 on 31 October
            '2026-11-01 04:30' => '2000', // 00:30 on the 1st, the first time
            '2026-11-01 05:30' => '4000', // 00:30 on the 1st, the second time
            '2026-11-02 04:30' => '8000', // 23:30 on the 1st
            '2026-11-02 05:30' => '16000', // 00:30 on the 2nd
        ], ['2026-10-31' => '1000.00', '2026-11-01' => '14000.00', '2026-11-02' => '16000.00']];
    }

    public function testTheDayBeforeOfAZoneThatMovesItsClockIsTheOneReported(): void
    {
        LocalTime::use('Africa/Cairo');
        $this->reportGroup();
        $this->paid(OrderType::Purchase, '16000', '', at: Carbon::parse('2026-04-24 21:30', 'UTC'));
        $this->paid(OrderType::Purchase, '8000', '', at: Carbon::parse('2026-04-24 20:30', 'UTC'));
        Carbon::setTestNow(Carbon::parse('2026-04-25 00:45', 'Africa/Cairo'));

        self::assertTrue($this->daily()->sendIfDue());

        $report = ReportMessage::query()->where('topic', Topic::Daily->value)->sole();
        self::assertSame('daily:2026-04-24', $report->ref);
        self::assertStringContainsString('💰 <b>درآمد: ۸٬۰۰۰ تومان</b>', $report->text, "the 25th's first half hour is the 25th's");
    }

    public function testAQuietDaySaysSo(): void
    {
        $this->reportGroup();
        // A receipt sent today, waiting: what waits on support is said as it stands now.
        $this->paid(OrderType::Purchase, '100000', '00:01', day: '1405-09-06', status: PaymentStatus::AwaitingReview, orderStatus: OrderStatus::Pending);

        $this->daily()->sendIfDue();

        $text = ReportMessage::query()->where('topic', Topic::Daily->value)->sole()->text;
        self::assertStringContainsString('💰 <b>درآمد: ۰ تومان</b>', $text);
        self::assertStringContainsString('💤 در این روز فروشی ثبت نشد.', $text);
        self::assertStringNotContainsString('پرفروش', $text);
        self::assertStringNotContainsString('روز قبل', $text, 'nothing to compare');
        self::assertStringContainsString('⏳ منتظر شما: ۱ رسید برای بررسی', $text);
        self::assertStringNotContainsString('تیکت باز', $text, 'only what waits');
    }

    public function testTheMainShopsReportSaysEachAgentsSalesAndAnAgentsSaysItsOwnShopAlone(): void
    {
        $this->reportGroup();
        $first = $this->agentShop('first_agent_bot', 'رضا', 6101);
        $second = $this->agentShop('second_agent_bot', 'مریم', 6102);
        $this->paid(OrderType::Traffic, '500000', '09:00', order: ['plan_id' => null, 'server_id' => null, 'traffic_bytes' => 50 * 1024 ** 3]);
        CurrentBot::run($first, function (): void {
            $this->reportGroup();
            $buyer = $this->customer(['telegram_id' => 7101]);
            $this->paid(OrderType::Purchase, '120000', '10:00', user: $buyer, method: $this->cardMethod('کارت رضا'));
            $this->paid(OrderType::Renewal, '120000', '11:00', user: $buyer, method: $this->cardMethod('کارت رضا ۲'));
        });
        CurrentBot::run($second, function (): void {
            $this->paid(OrderType::Purchase, '300000', '10:00', user: $this->customer(['telegram_id' => 7102]), method: $this->cardMethod('کارت مریم'));
        });

        $this->daily()->sendIfDue();
        $main = ReportMessage::query()->where('topic', Topic::Daily->value)->sole()->text;
        self::assertStringContainsString('💾 حجم نمایندگی: ۱ سفارش، ۵۰ گیگابایت، ۵۰۰٬۰۰۰ تومان', $main, 'what the agents bought from the main shop');
        self::assertStringContainsString('🤝 <b>فروش نماینده‌ها</b>: ۳ سفارش، ۵۴۰٬۰۰۰ تومان', $main);
        self::assertStringContainsString("\u{200F}• \u{200E}@second_agent_bot\u{200F} (مریم\u{200F}): ۱ سفارش، ۳۰۰٬۰۰۰ تومان\n\u{200F}• \u{200E}@first_agent_bot\u{200F} (رضا\u{200F}): ۲ سفارش، ۲۴۰٬۰۰۰ تومان", $main, 'the most money first, each line right to left though it starts with the bot; counted in orders as its head is');
        self::assertStringContainsString('💰 <b>درآمد: ۵۰۰٬۰۰۰ تومان</b>', $main, "the agents' takings are theirs, not the main shop's");

        $theirs = CurrentBot::run($first, function (): string {
            self::assertTrue($this->daily()->sendIfDue());

            return ReportMessage::query()->where('topic', Topic::Daily->value)->sole()->text;
        });
        self::assertStringContainsString('💰 <b>درآمد: ۲۴۰٬۰۰۰ تومان</b>', $theirs);
        self::assertStringContainsString('🗄 حجم باقی‌مانده نمایندگی: ۱۰۰ گیگابایت', $theirs);
        self::assertStringNotContainsString('🤝', $theirs);
        self::assertStringNotContainsString('second_agent_bot', $theirs, 'no other shop named');
        self::assertStringNotContainsString('حجم نمایندگی:', $theirs, "the main shop's sale of traffic is the main shop's");
    }

    public function testManyAgentsAndLongNamesStayInsideOneMessage(): void
    {
        $this->reportGroup();
        $long = str_repeat('نام بسیار بلند ', 8);
        for ($n = 1; $n <= 6; $n++) {
            $plan = $this->plan(['name' => $long . $n, 'price' => '100000.00'], $this->server);
            $method = $this->cardMethod($long . 'روش ' . $n);
            $this->paid(OrderType::Purchase, (string) (100000 * $n), '10:00', method: $method, order: ['plan_id' => $plan->id]);
        }
        for ($n = 1; $n <= 15; $n++) {
            $bot = $this->agentShop('agent_number_' . $n . '_with_a_long_bot_name', $long . $n, 6200 + $n);
            CurrentBot::run($bot, fn() => $this->paid(OrderType::Purchase, (string) (10000 * $n), '10:00', user: $this->customer(['telegram_id' => 7200 + $n]), method: $this->cardMethod('کارت')));
        }

        $this->daily()->sendIfDue();

        $text = ReportMessage::query()->where('topic', Topic::Daily->value)->sole()->text;
        self::assertLessThanOrEqual(Limits::MESSAGE, TelegramHtml::visibleLength($text));
        self::assertSame(DailyReport::PLANS_MOST, preg_match_all('/^\x{200F}[۰-۹]+\. /mu', $text), 'five plans named');
        self::assertStringContainsString('• و ۵ نماینده دیگر', $text);
        self::assertStringContainsString("سایر\u{200F}: ", $text, 'the methods past the fourth folded');
    }

    public function testAReportPastTelegramsLengthFoldsAgentsIntoHowManyMoreAndKeepsItsTotals(): void
    {
        $this->reportGroup();
        // Every list at its longest, every name cut at its most and each of its characters two of Telegram's units.
        $emoji = static fn(string $one, int $n): string => str_repeat($one, 70) . $n;
        for ($n = 1; $n <= 6; $n++) {
            $server = $this->sellingServer($emoji('🌍', $n));
            $plan = $this->plan(['name' => $emoji('📦', $n), 'price' => '100000.00'], $server);
            $this->paid(OrderType::Purchase, (string) (100000 * $n), '10:00', method: $this->cardMethod($emoji('💳', $n)), order: ['plan_id' => $plan->id, 'server_id' => $server->id]);
        }
        // A busy day: every line said, and something waiting on support.
        $this->paid(OrderType::ExtraTime, '7000', '11:00');
        $this->paid(OrderType::WalletTopUp, '1000000', '12:00', order: ['plan_id' => null, 'server_id' => null]);
        $this->paid(OrderType::Traffic, '500000', '13:00', order: ['plan_id' => null, 'server_id' => null, 'traffic_bytes' => 50 * 1024 ** 3]);
        $this->paid(OrderType::Purchase, '100000', '00:01', day: '1405-09-06', status: PaymentStatus::AwaitingReview, orderStatus: OrderStatus::Pending);
        $this->ticket($this->ali);
        $this->review($this->ali);
        for ($n = 1; $n <= 15; $n++) {
            // A bot with no @username yet: its title, as long as a name goes.
            $bot = $this->agentShop(null, $emoji('🤖', $n), 6200 + $n);
            CurrentBot::run($bot, fn() => $this->paid(OrderType::Purchase, (string) (10000 * $n), '10:00', user: $this->customer(['telegram_id' => 7200 + $n]), method: $this->cardMethod('کارت')));
        }

        $this->daily()->sendIfDue();

        $text = ReportMessage::query()->where('topic', Topic::Daily->value)->sole()->text;
        self::assertLessThanOrEqual(Limits::MESSAGE, TelegramHtml::visibleLength($text));
        self::assertStringContainsString('💰 <b>درآمد: ۳٬۶۰۷٬۰۰۰ تومان</b>', $text);
        self::assertStringContainsString('🛒 خرید سرویس: ۶ سفارش، ۲٬۱۰۰٬۰۰۰ تومان', $text);
        self::assertStringContainsString('💾 حجم نمایندگی: ۱ سفارش، ۵۰ گیگابایت، ۵۰۰٬۰۰۰ تومان', $text);
        self::assertStringContainsString('⏳ منتظر شما: ۱ رسید برای بررسی، ۱ تیکت باز، ۱ نظر برای بررسی', $text);
        self::assertStringContainsString('🤝 <b>فروش نماینده‌ها</b>: ۱۵ سفارش، ۱٬۲۰۰٬۰۰۰ تومان', $text, 'the totals of them all');
        self::assertSame(DailyReport::PLANS_MOST, preg_match_all('/^\x{200F}[۰-۹]+\. /mu', $text), 'the agents went first: every plan still named');
        $named = preg_match_all('/^\x{200F}• /mu', $text);
        self::assertSame(1, preg_match('/^• و ([۰-۹]+) نماینده دیگر$/mu', $text, $more));
        $left = (int) strtr($more[1], array_flip(['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹']));
        self::assertGreaterThan(15 - DailyReport::AGENTS_MOST, $left, 'more than the five past the tenth left out');
        self::assertSame(15, $named + $left, 'each agent named or counted');
    }

    public function testPastTheAgentsThePlansThenTheServersAreShortenedAndLastTheMethodsFolded(): void
    {
        $this->reportGroup();
        $named = static fn(string $one, int $count, array $more): array => array_map(static fn(int $n): array => ['name' => str_repeat($one, 40) . $n] + $more, range(1, $count));

        $text = $this->dailyOf(self::figures(
            methods: $named('💳', 30, ['total' => '1000.00']),
            plans: $named('📦', 30, ['count' => 1, 'amount' => '1000.00']),
            servers: $named('🌍', 30, ['count' => 1]),
        ));
        self::assertLessThanOrEqual(Limits::MESSAGE, TelegramHtml::visibleLength($text));
        self::assertStringContainsString('💰 <b>درآمد: ۳۰٬۰۰۰ تومان</b>', $text);
        self::assertStringContainsString('🛒 خرید سرویس: ۳۰ سفارش، ۳۰٬۰۰۰ تومان', $text);
        self::assertStringNotContainsString('🏆', $text, 'the plans went first, all of them');
        self::assertGreaterThan(0, preg_match_all('/🌍{40}\x{200F}/u', $text), 'then the servers, as many as it took');
        self::assertLessThan(30, preg_match_all('/🌍{40}\x{200F}/u', $text));
        self::assertSame(30, preg_match_all('/💳{40}\x{200F}: /u', $text), 'every method still named');

        $text = $this->dailyOf(self::figures(methods: $named('💳', 60, ['total' => '1000.00'])));
        self::assertLessThanOrEqual(Limits::MESSAGE, TelegramHtml::visibleLength($text));
        self::assertStringContainsString('🛒 خرید سرویس: ۳۰ سفارش، ۳۰٬۰۰۰ تومان', $text);
        $kept = preg_match_all('/💳{40}\x{200F}: /u', $text);
        self::assertLessThan(60, $kept);
        self::assertStringContainsString('سایر' . "\u{200F}: " . Money::format((60 - $kept) * 1000) . "\n", $text, 'the methods past them folded into one, their money kept');
    }

    public function testADirectionOverrideInANameIsDroppedAndItsLineKeepsItsMarks(): void
    {
        $this->reportGroup();
        $plan = $this->plan(['name' => "\u{202E}VIP 30GB\u{2066}", 'price' => '100000.00'], $this->server);
        $this->paid(OrderType::Purchase, '100000', '10:00', order: ['plan_id' => $plan->id]);
        // The agent's name, and their bot's title (no @username yet), carry one too.
        $bot = $this->agentShop(null, "\u{202E}رضا", 6101);
        CurrentBot::run($bot, fn() => $this->paid(OrderType::Purchase, '120000', '10:00', user: $this->customer(['telegram_id' => 7101]), method: $this->cardMethod('کارت')));

        $this->daily()->sendIfDue();

        $text = ReportMessage::query()->where('topic', Topic::Daily->value)->sole()->text;
        self::assertSame(0, preg_match('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $text), 'no embedding, override or isolate left');
        self::assertStringContainsString("\u{200F}۱. VIP 30GB\u{200F} — ۱ فروش، ۱۰۰٬۰۰۰ تومان", $text, 'its marks as they were');
        self::assertStringContainsString("\u{200F}• \u{200E}رضا\u{200F} (رضا\u{200F}): ۱ سفارش، ۱۲۰٬۰۰۰ تومان", $text);
    }

    #[DataProvider('changes')]
    public function testTheChangeFromTheDayBeforeIsSaidByItsDirection(string $before, string $now, string $said): void
    {
        $this->reportGroup();
        $this->paid(OrderType::Purchase, $before, '10:00', day: '1405-09-04');
        $this->paid(OrderType::Purchase, $now, '10:00');

        $this->daily()->sendIfDue();

        self::assertStringContainsString($said, ReportMessage::query()->where('topic', Topic::Daily->value)->sole()->text);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function changes(): iterable
    {
        yield 'the same' => ['100000', '100000', '➖ هم‌اندازه روز قبل (۱۰۰٬۰۰۰ تومان)'];
        yield 'a little more' => ['100000', '100400', '📈 کمتر از ۱٪ بیشتر از روز قبل (۱۰۰٬۰۰۰ تومان)'];
        yield 'a little less' => ['100000', '99600', '📉 کمتر از ۱٪ کمتر از روز قبل (۱۰۰٬۰۰۰ تومان)'];
        yield 'one percent' => ['100000', '101000', '📈 ۱٪ بیشتر از روز قبل (۱۰۰٬۰۰۰ تومان)'];
    }

    public function testADaysReportIsKeptThroughADayOf25HoursAtTheLeastKeeping(): void
    {
        LocalTime::use('Europe/Berlin');
        $this->reportGroup();
        $sender = $this->service(ReportSender::class);
        // 25 October 2026 has 25 hours: at 03:00 the clock goes back to 02:00. The report's hour is midnight.
        Carbon::setTestNow(Carbon::parse('2026-10-25 00:05', 'Europe/Berlin'));
        self::assertTrue($this->daily()->sendIfDue());
        $this->service(ShopReports::class)->notice(Topic::Errors, 'فرستاده');
        ReportMessage::query()->update(['sent_at' => now()]);

        // A day of keeping on: 23:10, still the 25th.
        Carbon::setTestNow(now()->addHours(24)->addMinutes(5));
        self::assertSame('2026-10-25 23:10', LocalTime::of(now())->format('Y-m-d H:i'));
        $sender->prune(1, ScheduleSettings::REPORT_WAIT_HOURS);
        self::assertSame(['daily:2026-10-24'], ReportMessage::query()->pluck('ref')->all(), 'the other report forgotten, the day\'s kept');
        self::assertFalse($this->daily()->sendIfDue(), 'the 24th reported already');

        Carbon::setTestNow(now()->addDay()->addHour());
        $sender->prune(1, ScheduleSettings::REPORT_WAIT_HOURS);
        self::assertSame(0, ReportMessage::query()->count(), 'two days on, it goes too');
    }

    /** The daily report of hand-made figures, as queued. */
    private function dailyOf(DailyFigures $figures): string
    {
        ReportMessage::query()->delete();
        $this->service(ShopReports::class)->daily($figures, 'daily:2026-11-26');

        return ReportMessage::query()->where('topic', Topic::Daily->value)->sole()->text;
    }

    /**
     * A day of 30 purchases, 30,000 Toman in all, with these lists.
     *
     * @param list<array{name: string, total: string}> $methods
     * @param list<array{name: string, count: int, amount: string}> $plans
     * @param list<array{name: string, count: int}> $servers
     */
    private static function figures(array $methods = [], array $plans = [], array $servers = []): DailyFigures
    {
        return new DailyFigures(Carbon::parse('2026-11-26', 'Asia/Tehran'), '30000.00', '0.00', $methods, ['purchase' => ['count' => 30, 'amount' => '30000.00', 'bytes' => 0]], $plans, $servers, 30, 0, 0, 0, [], null, null);
    }

    private function daily(): DailyReport
    {
        return $this->service(DailyReport::class);
    }

    /** The clock at `$time` of the Jalali day `$day` on the shop's clock (Tehran). */
    private function tehran(string $day, string $time): void
    {
        Carbon::setTestNow($this->at($day, $time));
    }

    /** That moment in UTC. 1405-09-05 is 2026-11-26. */
    private function at(string $day, string $time): Carbon
    {
        $gregorian = ['1405-09-04' => '2026-11-25', '1405-09-05' => '2026-11-26', '1405-09-06' => '2026-11-27'][$day] ?? self::fail("No date for {$day}.");

        return Carbon::parse("{$gregorian} {$time}", 'Asia/Tehran')->utc();
    }

    /**
     * The queries `$run` made: the query log on only around it, then off and emptied — the connection outlives the
     * test, so its log must not reach the next one.
     *
     * @return list<string>
     */
    private function queriesOf(callable $run): array
    {
        $db = $this->db();
        $db->enableQueryLog();
        try {
            $run();

            return array_values(array_column($db->getQueryLog(), 'query'));
        } finally {
            $db->disableQueryLog();
            $db->flushQueryLog();
        }
    }

    /**
     * Those of `$queries` that read the day's figures or whether its report went: payments, orders, report rows.
     *
     * @param list<string> $queries
     * @return list<string>
     */
    private static function figuresRead(array $queries): array
    {
        return array_values(array_filter($queries, static fn(string $sql): bool => (bool) preg_match('/payments|orders|report_messages/', $sql)));
    }

    /**
     * An order of `$type` at `$amount` whose payment was accepted at `$time` of `$day` (the reported day unless said) —
     * or at `$at` —, by the card unless told otherwise; of the plan on the server unless `$order` says.
     *
     * @param array<string, mixed> $order
     */
    private function paid(
        OrderType $type,
        string $amount,
        string $time,
        string $day = '1405-09-05',
        ?PaymentMethod $method = null,
        ?User $user = null,
        array $order = [],
        PaymentStatus $status = PaymentStatus::Paid,
        OrderStatus $orderStatus = OrderStatus::Fulfilled,
        ?Carbon $at = null,
    ): Order {
        $made = Order::query()->create($order + [
            'user_id' => ($user ?? $this->ali)->id,
            'type' => $type,
            'status' => $orderStatus,
            'plan_id' => $this->plan->id,
            'server_id' => $this->server->id,
            'amount' => $amount,
        ]);
        (new Payment())->forceFill([
            'order_id' => $made->id,
            'payment_method_id' => ($method ?? $this->card)->id,
            'status' => $status,
            'amount' => $amount,
            'paid_at' => $at ?? $this->at($day, $time),
        ])->save();

        return $made;
    }

    /** An agent's shop, its bot @`$username` (none yet: null), the agent named `$name`, with 100 GB to sell. */
    private function agentShop(?string $username, string $name, int $telegramId): Bot
    {
        $agent = $this->agent(overrides: ['telegram_id' => $telegramId, 'first_name' => $name]);
        $bot = $agent->ownBot ?? self::fail('The agent has no shop.');
        $bot->forceFill(['username' => $username, 'title' => $name])->save();
        $this->traffic($bot, 100);

        return $bot;
    }
}
