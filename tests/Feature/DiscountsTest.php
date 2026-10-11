<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Discounts\DTO\CodeTarget;
use App\Modules\Discounts\Enums\DiscountSource;
use App\Modules\Discounts\Enums\DiscountType;
use App\Modules\Discounts\Exceptions\DiscountCodeRefusedException;
use App\Modules\Discounts\Models\DiscountCode;
use App\Modules\Discounts\Services\DiscountCodes;
use App\Modules\Orders\Enums\OrderPricing;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\Pricing;
use App\Modules\Orders\Tasks\ExpireOrdersTask;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Enums\ExtraKind;
use App\Modules\Subscriptions\Services\AutoRenewal;
use App\Modules\Subscriptions\Services\CustomerRenewal;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\Topic;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\WalletService;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\DatabaseTestCase;
use Tests\Fakes\FakeProvider;

/**
 * The pricing pipeline and discounts: the base, the largest automatic discount (sale, own percent, tier — never added), then
 * the code; each code rule and refusal; uses taken under the code's lock and given back when an unpaid order goes; automatic
 * renewal at the automatic price; agents' shops with their own codes and sales.
 */
final class DiscountsTest extends DatabaseTestCase
{
    private Server $server;
    private Plan $plan;
    private User $ali;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-08 12:00:00');
        $this->withoutQr();
        $this->server = $this->sellingServer();
        $this->plan = $this->plan(['price' => '100000.00'], $this->server);
        $this->ali = $this->customer(['username' => 'ali']);
    }

    public function testEachTypeOfCodeTakesItsShareOfWhatIsLeft(): void
    {
        $this->discountCode('PCT', 20);
        $this->discountCode('CAP', 50, ['max_discount' => '30000']);
        $this->discountCode('FIX', 0, ['type' => DiscountType::Fixed, 'value' => '15000']);
        $this->discountCode('HUGE', 0, ['type' => DiscountType::Fixed, 'value' => '500000']);
        $this->discountCode('FREE', 0, ['type' => DiscountType::Free]);

        foreach (['PCT' => '80000.00', 'CAP' => '70000.00', 'FIX' => '85000.00', 'HUGE' => '0.00', 'FREE' => '0.00'] as $code => $amount) {
            $quote = $this->pricing()->purchase($this->ali, $this->plan, $this->server, $code);

            self::assertSame($amount, $quote->amount(), $code);
            self::assertSame(DiscountSource::Code, $quote->code?->source);
            self::assertSame('کد ' . $code, $quote->code->label);
        }
        self::assertSame(20, $this->pricing()->purchase($this->ali, $this->plan, $this->server, 'pct')->code?->percent, 'typed in any case');
    }

    public function testACodeIsTypedInAnyCaseAndWithPersianDigits(): void
    {
        $this->discountCode('YALDA1405', 10);

        $quote = $this->pricing()->purchase($this->ali, $this->plan, $this->server, ' yalda۱۴۰۵ ');

        self::assertSame('90000.00', $quote->amount());
        self::assertSame('YALDA1405', $quote->code?->code);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function refusals(): iterable
    {
        yield 'switched off' => [['enabled' => false], DiscountCodes::OFF];
        yield 'ended' => [['ends_at' => '2026-10-08 11:00:00'], DiscountCodes::ENDED];
        yield 'another kind' => [['order_types' => ['renewal']], 'این کد تخفیف برای خرید نیست.'];
        yield 'another plan' => [['plan_ids' => [999]], DiscountCodes::WRONG_PLAN];
        yield 'another server' => [['server_ids' => [999]], DiscountCodes::WRONG_SERVER];
        yield 'a larger order' => [['min_amount' => '150000'], 'این کد تخفیف برای سفارش‌های دست‌کم ۱۵۰٬۰۰۰ تومان است.'];
    }

    /** @param array<string, mixed> $code */
    #[DataProvider('refusals')]
    public function testACodeThatDoesNotHoldIsRefusedInTheCustomersWords(array $code, string $words): void
    {
        $this->discountCode('NOWRUZ', 20, $code);

        $this->assertRefused($words, fn() => $this->pricing()->purchase($this->ali, $this->plan, $this->server, 'NOWRUZ'));
    }

    public function testAnUnknownCodeAndOneNotStartedSaySo(): void
    {
        $this->assertRefused(DiscountCodes::NOT_FOUND, fn() => $this->pricing()->purchase($this->ali, $this->plan, $this->server, 'NOPE'));

        $this->discountCode('LATER', 20, ['starts_at' => '2026-10-08 20:30:00']);
        $this->assertRefused('این کد تخفیف از ۱۶ مهر ۱۴۰۵، ۲۰:۳۰ فعال می‌شود.', fn() => $this->pricing()->purchase($this->ali, $this->plan, $this->server, 'LATER'));

        // Another shop's code is no code of this one's.
        $bot = $this->agentBot($this->agent(overrides: ['telegram_id' => 6001]));
        CurrentBot::run($bot, fn() => $this->discountCode('THEIRS', 50));
        $this->assertRefused(DiscountCodes::NOT_FOUND, fn() => $this->pricing()->purchase($this->ali, $this->plan, $this->server, 'THEIRS'));
    }

    public function testAnHourlyCodeHoldsUntilItsHourAndNotAMomentLonger(): void
    {
        $this->discountCode('FLASH', 30, ['ends_at' => '2026-10-08 13:00:00']);

        self::assertSame('70000.00', $this->pricing()->purchase($this->ali, $this->plan, $this->server, 'FLASH')->amount());

        Carbon::setTestNow('2026-10-08 13:00:00');
        $this->assertRefused(DiscountCodes::ENDED, fn() => $this->pricing()->purchase($this->ali, $this->plan, $this->server, 'FLASH'));
    }

    public function testAFirstPurchaseCodeIsANewcomersAlone(): void
    {
        $this->discountCode('WELCOME', 50, ['first_purchase_only' => true]);
        self::assertSame('50000.00', $this->pricing()->purchase($this->ali, $this->plan, $this->server, 'WELCOME')->amount());

        $this->buy($this->ali, $this->plan, $this->server);

        $this->assertRefused(DiscountCodes::FIRST_ONLY, fn() => $this->pricing()->purchase($this->ali, $this->plan, $this->server, 'WELCOME'));
    }

    public function testACodeForSomePlansAndServersHoldsForThemAlone(): void
    {
        $other = $this->sellingServer('فرانسه');
        $this->planEntry($this->plan, $other);
        $this->discountCode('BERLIN', 25, ['plan_ids' => [$this->plan->id], 'server_ids' => [$this->server->id]]);

        self::assertSame('75000.00', $this->pricing()->purchase($this->ali, $this->plan, $this->server, 'BERLIN')->amount());
        $this->assertRefused(DiscountCodes::WRONG_SERVER, fn() => $this->pricing()->purchase($this->ali, $this->plan, $other, 'BERLIN'));
        $this->assertRefused(DiscountCodes::WRONG_SERVER, fn() => $this->pricing()->purchase($this->ali, $this->plan, null, 'BERLIN'), 'no server picked yet');
    }

    public function testACodeForRenewalsAndExtrasHoldsByTheServicesPlanAndServer(): void
    {
        $this->extrasOnSale();
        $subscription = $this->subscription($this->ali, $this->plan, $this->server, 'ali_1');
        $this->discountCode('KEEP', 10, ['order_types' => ['renewal', 'extra_time'], 'plan_ids' => [$this->plan->id]]);

        self::assertSame('90000.00', $this->pricing()->renewal($this->ali, $subscription, $this->plan, 'KEEP')->amount());
        self::assertSame('6300.00', $this->pricing()->extra($this->ali, $subscription, ExtraKind::Time, '7000', 'KEEP')->amount());
        $this->assertRefused('این کد تخفیف برای حجم اضافه نیست.', fn() => $this->pricing()->extra($this->ali, $subscription, ExtraKind::Traffic, '18000', 'KEEP'));
    }

    public function testATopUpAndAnAgentsTrafficAreNeverDiscounted(): void
    {
        $this->sale();
        $this->ali->forceFill(['discount_percent' => 50])->save();

        $topUp = $this->service(OrderService::class)->openTopUp($this->ali, 50000);

        self::assertSame(['50000.00', '0.00', null], [$topUp->amount, $topUp->discount_amount, $topUp->discounts]);
        self::assertNull($this->pricing()->automatic($this->ali)->best($this->plan->id, OrderType::WalletTopUp, '50000'));
        self::assertNull($this->pricing()->automatic($this->ali)->best(null, OrderType::Traffic, '50000'));
    }

    public function testTheLargestAutomaticDiscountAppliesThenTheCodeOnWhatItLeft(): void
    {
        $this->sale('فروش ویژه نوروز', 10);
        $this->ali->forceFill(['discount_percent' => 15])->save();
        $this->loyaltyTier('طلایی', '0', 5);
        $this->discountCode('NOWRUZ', 10);

        $quote = $this->pricing()->purchase($this->ali->refresh(), $this->plan, $this->server, 'NOWRUZ');

        self::assertSame(DiscountSource::Personal, $quote->automatic?->source, 'the largest of the three, never added together');
        self::assertSame(['تخفیف اختصاصی شما ۱۵٪', '15000.00'], [$quote->automatic->label, $quote->automatic->amount]);
        self::assertSame('8500.00', $quote->code?->amount, "the code's 10% of what the automatic one left: 85,000");
        self::assertSame(['100000.00', '23500.00', '76500.00'], [$quote->base, $quote->discountAmount(), $quote->amount()]);

        $order = $this->service(OrderService::class)->openPurchase($this->ali, $this->plan, $this->server, quote: $quote);
        self::assertSame(['76500.00', '23500.00'], [$order->amount, $order->discount_amount]);
        self::assertSame(['personal', 'code'], array_map(static fn(array $line): string => $line['source'], $order->discounts ?? []));
        self::assertSame($quote->code->id, $order->discount_code_id);
    }

    public function testASaleIsForItsPlansKindsAndWindow(): void
    {
        $other = $this->plan(['name' => 'سه‌ماهه', 'price' => '200000.00'], $this->server);
        $this->sale('حراج پاییز', 30, ['plan_ids' => [$this->plan->id], 'order_types' => ['purchase'], 'ends_at' => '2026-10-10 00:00:00']);
        $this->sale('تمدید ارزان', 50, ['order_types' => ['renewal']]);
        $this->sale('به‌زودی', 90, ['starts_at' => '2026-10-11 00:00:00']);
        $this->sale('خاموش', 90, ['enabled' => false]);

        $offers = $this->pricing()->offers($this->ali, $this->plan, $other);

        self::assertSame(['70000.00', 'حراج پاییز ۳۰٪'], [$offers[$this->plan->id]->amount(), $offers[$this->plan->id]->automatic?->label]);
        self::assertSame('2026-10-10T00:00:00+00:00', Carbon::instance($offers[$this->plan->id]->automatic->endsAt ?? self::fail())->toIso8601String());
        self::assertNull($offers[$other->id]->automatic, 'another plan: no purchase sale reaches it');
        $subscription = $this->subscription($this->ali, $other, $this->server, 'ali_1');
        self::assertSame('100000.00', $this->pricing()->renewal($this->ali, $subscription, $other)->amount(), "a renewal's sale reaches every plan's renewal");

        Carbon::setTestNow('2026-10-10 00:00:00');
        self::assertSame('100000.00', $this->pricing()->purchase($this->ali, $this->plan)->amount(), 'ended');
    }

    public function testAGuestReadsTheSalesAloneAndTheTiersDiscountIsTheirTiers(): void
    {
        $this->sale('حراج', 10);
        self::assertSame('90000.00', $this->pricing()->purchase(null, $this->plan)->amount(), 'a guest: the sale');

        $this->loyaltyTier('نقره‌ای', '50000', 20);
        self::assertSame('90000.00', $this->pricing()->purchase($this->ali, $this->plan)->amount(), 'spent nothing yet: no tier');

        $this->buy($this->ali, $this->plan, $this->server);
        $quote = $this->pricing()->purchase($this->ali, $this->plan);
        self::assertSame([DiscountSource::Tier, 'سطح نقره‌ای ۲۰٪', '80000.00'], [$quote->automatic?->source, $quote->automatic->label, $quote->amount()]);
    }

    public function testTheAutomaticDiscountAppliesToAFirstPurchaseOrAnAgentPrice(): void
    {
        $plan = $this->plan(['name' => 'ویژه', 'price' => '150000.00', 'first_purchase_price' => '100000.00'], $this->server);
        $this->sale('حراج', 10);

        $quote = $this->pricing()->purchase($this->ali, $plan);

        self::assertSame([OrderPricing::FirstPurchase, '100000.00', '90000.00'], [$quote->pricing, $quote->base, $quote->amount()]);
    }

    public function testACodeHasItsUsesInAllAndACustomersAndACancelledOrderGivesItsUseBack(): void
    {
        $this->discountCode('TWICE', 10, ['max_uses' => 2, 'per_user' => 1]);
        $orders = $this->service(OrderService::class);
        $quote = fn(User $who) => $this->pricing()->purchase($who, $this->plan, $this->server, 'TWICE');

        $first = $orders->openPurchase($this->ali, $this->plan, $this->server, quote: $quote($this->ali));
        self::assertSame($first->id, $orders->openPurchase($this->ali, $this->plan, $this->server, quote: $quote($this->ali))->id, 'their checkout opened again finds its order: no second use');

        $this->buyWith($first);
        $this->assertRefused(DiscountCodes::USED_BY_YOU, fn() => $quote($this->ali->refresh()));

        $sara = $this->customer(['telegram_id' => 7001]);
        $taken = $orders->openPurchase($sara, $this->plan, $this->server, quote: $quote($sara));
        $this->assertRefused(DiscountCodes::USED_UP, fn() => $quote($this->customer(['telegram_id' => 7002])));

        $this->service(OrderService::class)->cancel($taken, OrderService::NOTE_CANCELLED_BY_SUPPORT);
        self::assertSame('90000.00', $quote($this->customer(['telegram_id' => 7003]))->amount(), 'an order cancelled unpaid gave its use back');
    }

    public function testOfTwoCustomersTakingTheLastUseOneDoes(): void
    {
        $code = $this->discountCode('LAST', 10, ['max_uses' => 1]);
        $sara = $this->customer(['telegram_id' => 7001]);
        $orders = $this->service(OrderService::class);
        $aliQuote = $this->pricing()->purchase($this->ali, $this->plan, $this->server, 'LAST');
        $saraQuote = $this->pricing()->purchase($sara, $this->plan, $this->server, 'LAST');

        // Sara's order is made the moment Ali's checkout locks the code's row to count its uses: his count finds it. (Here
        // both are one connection's: his refusal undoes hers with it — two processes would each keep their own.)
        $theirs = null;
        $raced = false;
        $this->whileListening('eloquent.retrieved: ' . DiscountCode::class, function () use (&$theirs, &$raced, $orders, $sara, $saraQuote): void {
            if (!$raced) {
                $raced = true;
                $theirs = $orders->openPurchase($sara, $this->plan, $this->server, quote: $saraQuote);
            }
        }, fn() => $this->assertRefused(DiscountCodes::USED_UP, fn() => $orders->openPurchase($this->ali, $this->plan, $this->server, quote: $aliQuote)));

        self::assertSame($code->id, $theirs?->discount_code_id, "she took the code's last use");
        self::assertSame(0, Order::query()->where('user_id', $this->ali->id)->count(), 'he ordered nothing');
    }

    public function testAnOrderMadeWithACodeExpiresOnceItsHoldIsOverAndGivesItsUseBack(): void
    {
        $this->discountCode('ONCE', 10, ['max_uses' => 1]);
        $card = $this->cardMethod();
        $order = $this->service(OrderService::class)->openPurchase($this->ali, $this->plan, $this->server, quote: $this->pricing()->purchase($this->ali, $this->plan, $this->server, 'ONCE'));
        $this->service(PaymentService::class)->createForOrder($order, $card);
        $plain = $this->service(OrderService::class)->openPurchase($this->ali, $this->plan, $this->server);
        $task = $this->service(ExpireOrdersTask::class);

        Carbon::setTestNow('2026-10-08 12:59:00');
        $task->run();
        self::assertSame(OrderStatus::Pending, $order->refresh()->status, 'within the hour the shop holds it (60 minutes by default)');

        Carbon::setTestNow('2026-10-08 13:00:00');
        $task->run();
        self::assertSame([OrderStatus::Cancelled, ExpireOrdersTask::NOTE_CODE_HOLD], [$order->refresh()->status, $order->notes]);
        self::assertSame(OrderStatus::Pending, $plain->refresh()->status, 'an order without a code waits its two days');
        self::assertSame('90000.00', $this->pricing()->purchase($this->customer(['telegram_id' => 7001]), $this->plan, $this->server, 'ONCE')->amount(), 'its use given back');
    }

    public function testAHoldOfNoneLeavesACodesOrderToTheTwoDaysAndAReceiptWithSupportKeepsIt(): void
    {
        $this->discountCode('ONCE', 10);
        $card = $this->cardMethod();
        $quote = $this->pricing()->purchase($this->ali, $this->plan, $this->server, 'ONCE');
        $order = $this->service(OrderService::class)->openPurchase($this->ali, $this->plan, $this->server, quote: $quote);
        $payment = $this->service(PaymentService::class)->createForOrder($order, $card)['payment'];
        $this->receipt($payment);

        Carbon::setTestNow('2026-10-08 15:00:00');
        $this->service(ExpireOrdersTask::class)->run();
        self::assertSame(OrderStatus::Pending, $order->refresh()->status, 'its receipt is with support');

        $this->botSettings('discounts', ['hold_minutes' => 0]);
        $other = $this->service(OrderService::class)->openPurchase($this->customer(['telegram_id' => 7001]), $this->plan, $this->server, quote: $this->pricing()->purchase($this->customer(['telegram_id' => 7002]), $this->plan, $this->server, 'ONCE'));
        Carbon::setTestNow('2026-10-09 15:00:00');
        $this->service(ExpireOrdersTask::class)->run();
        self::assertSame(OrderStatus::Pending, $other->refresh()->status, 'no hold of its own: the two days');
    }

    public function testAutoRenewalPaysTheAutomaticPriceNeverACode(): void
    {
        FakeProvider::$subscriptionBase = 'https://sub.example';
        $this->sale('تمدید ارزان', 25, ['order_types' => ['renewal']]);
        $this->discountCode('NOWRUZ', 50);
        $this->walletMethod();
        $subscription = $this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['auto_renew' => true, 'expires_at' => now()->addDay(), 'starts_at' => now()->subDays(29)]);
        FakeProvider::mirror($subscription);
        $this->wallet($this->ali, '75000');

        $this->service(AutoRenewal::class)->renew($subscription->refresh());

        $order = Order::query()->where('type', OrderType::Renewal->value)->sole();
        self::assertSame(['75000.00', '25000.00', null, OrderStatus::Fulfilled], [$order->amount, $order->discount_amount, $order->discount_code_id, $order->status]);
        self::assertSame('0.00', $this->ali->balance());
    }

    public function testTheRenewalsPreviewIsAtTheAutomaticPrice(): void
    {
        $this->ali->forceFill(['discount_percent' => 10])->save();
        $subscription = $this->subscription($this->ali, $this->plan, $this->server, 'ali_1');

        $preview = $this->service(CustomerRenewal::class)->preview($subscription->refresh());

        self::assertSame('90000.00', $preview?->price);
        self::assertSame(DiscountSource::Personal, $preview->quote->automatic?->source);
    }

    public function testTheReportGroupHearsWhatWasTakenOff(): void
    {
        $this->reportGroup();
        $this->sale('حراج', 10);
        $this->discountCode('NOWRUZ', 0, ['type' => DiscountType::Fixed, 'value' => '5000']);
        $order = $this->service(OrderService::class)->openPurchase($this->ali, $this->plan, $this->server, quote: $this->pricing()->purchase($this->ali, $this->plan, $this->server, 'NOWRUZ'));

        $this->buyWith($order);

        $report = ReportMessage::query()->where('topic', Topic::Purchases->value)->latest('id')->value('text');
        self::assertStringContainsString('🏷 تخفیف: ۱۵٬۰۰۰ تومان · حراج ۱۰٪ · کد NOWRUZ', (string) $report);
    }

    public function testAnAgentsShopHasItsOwnCodesAndSales(): void
    {
        $bot = $this->agentBot($this->agent(overrides: ['telegram_id' => 6001]), 500);
        $this->discountCode('MAIN', 50);
        $this->sale('حراج اصلی', 50);

        CurrentBot::run($bot, function (): void {
            $server = $this->sellingServer('آلمان ۲');
            $plan = $this->plan(['price' => '80000.00', 'traffic_gb' => 10], $server);
            $customer = $this->customer(['telegram_id' => 9100]);
            $this->discountCode('AGENT', 25);

            self::assertSame('80000.00', $this->pricing()->purchase($customer, $plan)->amount(), "the main shop's sale is not this shop's");
            self::assertSame('60000.00', $this->pricing()->purchase($customer, $plan, $server, 'agent')->amount());
            $this->assertRefused(DiscountCodes::NOT_FOUND, fn() => $this->pricing()->purchase($customer, $plan, $server, 'MAIN'));
        });
    }

    public function testWrongCodesCostTheCustomersBudget(): void
    {
        $codes = $this->service(DiscountCodes::class);
        $this->discountCode('GOOD', 10);
        $target = new CodeTarget(OrderType::Purchase, $this->plan->id, $this->server->id);

        for ($i = 0; $i < DiscountCodes::WRONG_CODES; ++$i) {
            // A code that holds costs nothing.
            $codes->tried($this->ali, fn() => $codes->apply($this->ali, 'GOOD', $target, '100000'));
            $this->assertRefused(DiscountCodes::NOT_FOUND, fn() => $codes->tried($this->ali, fn() => $codes->apply($this->ali, 'WRONG' . $i, $target, '100000')));
        }

        // Spent: whatever is typed waits.
        $this->expectException(TooManyAttemptsException::class);
        $codes->tried($this->ali, fn() => $codes->apply($this->ali, 'GOOD', $target, '100000'));
    }

    public function testAMergeKeepsTheLargerOwnDiscount(): void
    {
        $this->ali->forceFill(['discount_percent' => 5])->save();
        $web = $this->webCustomer(['discount_percent' => 12]);

        $kept = $this->service(\App\Modules\Accounts\Services\AccountMerger::class)->merge($this->ali, $web, \App\Modules\Accounts\Services\AccountMerger::BY_CUSTOMER);

        self::assertSame(12, $kept->refresh()->discount_percent);
    }

    private function pricing(): Pricing
    {
        return $this->service(Pricing::class);
    }

    /** The order paid from the wallet, its price credited first — and delivered. */
    private function buyWith(Order $order): void
    {
        $this->service(WalletService::class)->credit($order->user, $order->amount, 'test');
        $this->service(PaymentService::class)->createForOrder($order, $this->walletMethod());
        self::assertSame(OrderStatus::Fulfilled, $order->refresh()->status, (string) $order->notes);
    }

    private function assertRefused(string $words, \Closure $work, string $message = ''): void
    {
        try {
            $work();
        } catch (DiscountCodeRefusedException $e) {
            self::assertSame($words, $e->getMessage(), $message);
            self::assertSame(['discount_code' => [$words]], $e->errors());

            return;
        }
        self::fail('The code held: ' . $message);
    }
}
