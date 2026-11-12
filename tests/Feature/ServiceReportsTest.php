<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\TestAccounts;
use App\Modules\Subscriptions\Tasks\CleanupServicesTask;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\ShopReports;
use App\Modules\Telegram\Reports\Topic;
use App\Modules\Users\Models\User;
use App\Support\Persian;
use Illuminate\Support\Carbon;
use Tests\Fakes\FakeProvider;
use Tests\HttpTestCase;

/**
 * What the report group hears of the services that come and go outside a sale: a free test taken («اکانت‌های تست») —
 * whose, which client and where, its terms, and the customer's count —; a service support deleted, with what it still
 * had, who and their note, a client left on its panel said; a purchase's service a refund took back; and the ended
 * services the clean-up removed, in one report a run, as many as fit, the free tests left out («حذف سرویس‌ها»). A topic
 * switched off hears none of it.
 */
final class ServiceReportsTest extends HttpTestCase
{
    /** Ali as the group reads him: the handle after a left-to-right mark. */
    private const ALI = '<a href="tg://user?id=1001">Ali</a> · ' . "\u{200E}@ali_r · <code>1001</code>";

    private User $ali;
    private Server $server;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->telegram();
        $this->withoutQr();
        $this->loginAsAdmin();
        $this->reportGroup();

        $this->ali = $this->customer(['telegram_id' => 1001, 'username' => 'ali_r']);
        $this->server = $this->sellingServer('آلمان', ['test_enabled' => true]);
        $this->plan = $this->plan([], $this->server);
    }

    public function testATestTakenIsReportedWithItsTermsAndHowManyTheCustomerHasTaken(): void
    {
        $this->testsOn(['test_per_user' => 2]);
        $tests = $this->service(TestAccounts::class);

        $first = $tests->issue($this->ali, $this->server->id);
        $second = $tests->issue($this->ali, $this->server->id);

        $reports = $this->reportedIn(Topic::Tests);
        self::assertCount(2, $reports);
        self::assertSame(implode("\n", [
            "🧪 <b>اکانت تست ساخته شد</b> · اشتراک \u{200E}#{$first->id}",
            '👤 ' . self::ALI,
            '🔖 سرویس: <code>test_ali_r_1</code>',
            '📍 لوکیشن: آلمان',
            '📦 حجم: ' . Messages::traffic($first->traffic_limit_bytes) . ' · 📅 پایان: ' . Messages::expiry($first->expires_at, $first->duration_hours),
            '🔢 تست‌های این مشتری تا حالا: ۱',
        ]), $reports[0]);
        self::assertStringContainsString("<code>{$second->remote_name}</code>", $reports[1]);
        self::assertStringEndsWith('🔢 تست‌های این مشتری تا حالا: ۲', $reports[1], 'the second of his');
    }

    public function testSupportDeletingAServiceIsReportedWithWhatItHadWhoAndTheirNote(): void
    {
        $service = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_r_1'));

        $response = $this->postJson("/api/admin/subscriptions/{$service->id}/delete", ['note' => 'درخواست خود مشتری']);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(implode("\n", [
            "🗑 <b>سرویس حذف شد</b> · اشتراک \u{200E}#{$service->id}",
            '👤 ' . self::ALI,
            '🔖 سرویس: <code>ali_r_1</code> · یک‌ماهه',
            '📍 لوکیشن: آلمان',
            '📥 حجم باقی‌مانده: ۳۰ گیگابایت · 📅 پایان: ' . Messages::expiry($service->expires_at, $service->duration_hours),
            '🎧 توسط پشتیبانی',
            '📝 توضیح پشتیبانی: درخواست خود مشتری',
        ]), $this->reported(Topic::Deletions), "the owner's own login is never named");
    }

    public function testADeletedTestOrOneItsPanelLostIsSaidSoAndAClientLeftOnThePanelToo(): void
    {
        $test = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'test_ali_r_1', ['is_test' => true, 'plan_id' => null]));
        $lost = $this->subscription($this->ali, $this->plan, $this->server, 'ali_r_2', ['status' => SubscriptionStatus::Deleted]);
        $left = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_r_3'));

        $this->postJson("/api/admin/subscriptions/{$test->id}/delete", []);
        $this->postJson("/api/admin/subscriptions/{$lost->id}/delete", []);
        $this->postJson("/api/admin/subscriptions/{$left->id}/delete", ['leave_panel' => true]);

        [$ofTest, $ofLost, $ofLeft] = $this->reportedIn(Topic::Deletions);
        self::assertStringContainsString('🔖 سرویس: <code>test_ali_r_1</code> · اکانت تست', $ofTest);
        self::assertStringContainsString('🔎 کلاینتش از قبل روی پنل نبود.', $ofLost);
        self::assertStringNotContainsString('روی پنل سرور ماند', $ofLost, 'nothing of it was left anywhere');
        self::assertStringEndsWith('⚠️ کلاینتش روی پنل سرور ماند؛ آن را از خود پنل حذف کنید.', $ofLeft);
        self::assertSame(['test_ali_r_1'], array_column(FakeProvider::$deleted, 'name'), 'the panel was asked about the test alone');
    }

    public function testAServiceARefundTookBackIsReportedAsTheRefundsAndTheCustomerHearsItOnce(): void
    {
        $payment = $this->paidByCard($this->purchaseOrder($this->ali, $this->plan, $this->server), $this->cardMethod());
        $service = $payment->order->subscription ?? self::fail('nothing delivered');
        $this->telegram()->reset();

        self::assertSame(200, $this->postJson("/api/admin/payments/{$payment->id}/refund", [])->getStatusCode());

        $report = $this->reported(Topic::Deletions);
        self::assertStringStartsWith("🗑 <b>سرویس خرید برگشتی حذف شد</b> · اشتراک \u{200E}#{$service->id}", $report);
        self::assertStringContainsString("\n💸 برای برگشت مبلغ سفارش \u{200E}#{$payment->order_id}\n🎧 توسط پشتیبانی", $report);
        self::assertCount(1, $this->telegram()->sentTo(1001), "the refund's own message, which names the service");
    }

    public function testTheCleanUpReportsTheEndedServicesItRemovedInOneReportAndNoTests(): void
    {
        $this->botSettings('expired_services', ['expired_cleanup' => true, 'expired_cleanup_hours' => 12]);
        $this->testsOn();
        $sara = $this->webCustomer();
        $this->ended($this->ali, 'ali_r_1');
        $this->ended($sara, 'sara_1');
        $this->ended($this->ali, 'test_ali_r_1', ['is_test' => true, 'plan_id' => null]);
        $this->ended($this->ali, 'ali_r_2', ['ended_at' => now()->subHours(2)]);

        $this->service(CleanupServicesTask::class)->run();

        self::assertSame(['test_ali_r_1', 'ali_r_1', 'sara_1'], array_column(FakeProvider::$deleted, 'name'), 'the tests first; the last service ended two hours ago, not twelve');
        self::assertSame(implode("\n", [
            '🧹 <b>۲ سرویس منقضی‌شده خودکار حذف شد</b>',
            '🔖 <code>ali_r_1</code> · <a href="tg://user?id=1001">Ali</a> · آلمان',
            "🔖 <code>sara_1</code> · Sara Ahmadi \u{200E}<code>#{$sara->id}</code> · آلمان",
        ]), $this->reported(Topic::Deletions), 'one report for the run; its test is no news');

        $this->service(CleanupServicesTask::class)->run();
        self::assertCount(1, $this->reportedIn(Topic::Deletions), 'a run that removed nothing says nothing');
    }

    public function testACleanUpReportTooLongForAMessageSaysHowManyMore(): void
    {
        $long = $this->customer(['telegram_id' => 2002, 'first_name' => str_repeat('نام بلند ', 10)]);
        $services = [];
        for ($i = 1; $i <= 60; $i++) {
            $services[] = $this->subscription($long, $this->plan, $this->server, sprintf('long_client_name_%02d', $i));
        }

        $this->service(ShopReports::class)->servicesCleanedUp($services);

        $lines = explode("\n", $this->reported(Topic::Deletions));
        self::assertSame('🧹 <b>۶۰ سرویس منقضی‌شده خودکار حذف شد</b>', $lines[0]);
        $listed = count($lines) - 2;
        self::assertGreaterThan(20, $listed);
        self::assertLessThan(60, $listed);
        self::assertSame(sprintf('… و %s سرویس دیگر', Persian::number(60 - $listed)), end($lines));
        self::assertLessThanOrEqual(4096, mb_strlen(strip_tags(implode("\n", $lines))), "within Telegram's limit");
    }

    public function testATopicSwitchedOffHearsNothing(): void
    {
        $this->botSettings('reports', ['report_tests' => false, 'report_deletions' => false] + array_fill_keys(array_map(static fn(Topic $topic): string => 'report_' . $topic->value, Topic::cases()), true));
        $this->testsOn();
        $service = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_r_1'));

        $this->service(TestAccounts::class)->issue($this->ali, $this->server->id);
        $this->postJson("/api/admin/subscriptions/{$service->id}/delete", []);

        self::assertSame(0, ReportMessage::query()->count());
    }

    public function testAnAgentsShopsTestsAndDeletionsGoToItsOwnGroup(): void
    {
        $bot = $this->agentBot();
        CurrentBot::run($bot, function (): void {
            $this->reportGroup(-1009999);
            $this->testsOn();
            $test = $this->service(TestAccounts::class)->issue($this->customer(['telegram_id' => 3003]), $this->server->id);
            $this->service(ShopReports::class)->servicesCleanedUp([$test]);
        });

        self::assertSame([1, 1], CurrentBot::run($bot, fn(): array => [count($this->reportedIn(Topic::Tests)), count($this->reportedIn(Topic::Deletions))]), "the agent's own group");
        self::assertSame([[], []], [$this->reportedIn(Topic::Tests), $this->reportedIn(Topic::Deletions)], "the shop's group hears nothing of the agent's shop");
    }

    /**
     * The shop's test accounts switched on: one test a customer for good, no cap, anyone, cleaned up a day after they
     * end — `$overrides` on top.
     *
     * @param array<string, mixed> $overrides
     */
    private function testsOn(array $overrides = []): void
    {
        $this->botSettings('test_accounts', $overrides + [
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

    /**
     * A service of the customer's that ended two days ago, as the sync left it, its panel saying the same.
     *
     * @param array<string, mixed> $overrides
     */
    private function ended(User $user, string $name, array $overrides = []): Subscription
    {
        return $this->mirrored($this->subscription($user, $this->plan, $this->server, $name, $overrides + [
            'status' => SubscriptionStatus::Expired,
            'starts_at' => now()->subDays(32),
            'expires_at' => now()->subDays(2),
            'ended_at' => now()->subDays(2),
        ]));
    }

    /** The one report queued for the topic. */
    private function reported(Topic $topic): string
    {
        return ReportMessage::query()->where('topic', $topic->value)->sole()->text;
    }

    /** @return list<string> What was queued for the topic, oldest first. */
    private function reportedIn(Topic $topic): array
    {
        return ReportMessage::query()->where('topic', $topic->value)->oldest('id')->pluck('text')->all();
    }
}
