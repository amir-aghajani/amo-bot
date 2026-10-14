<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Models\Order;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\DTO\Expiry;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\AutoRenewal;
use App\Modules\Subscriptions\Services\ProvisioningService;
use App\Modules\Subscriptions\Tasks\AutoRenewTask;
use App\Modules\Subscriptions\Tasks\SendRemindersTask;
use App\Modules\Telegram\Handlers\PurchaseHandler;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Users\Models\User;
use App\Support\Money;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Tests\BotTestCase;
use Tests\Fakes\FakeProvider;

/**
 * Plans with a term in hours: sold and delivered by the hour, read back from the panel by the hour, and neither renewed
 * automatically nor reminded when the term is shorter than those features' windows.
 */
final class HourlyPlansTest extends BotTestCase
{
    private User $ali;
    private Server $server;
    private Plan $hourly;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-20 12:00:00');
        $this->withoutQr();
        $this->ali = $this->wallet($this->customer(['username' => 'ali']), '100000');
        $this->server = $this->sellingServer();
        $this->hourly = $this->plan(['name' => 'شش‌ساعته', 'price' => '5000.00', 'duration_hours' => 6, 'traffic_gb' => 2], $this->server);
    }

    public function testAnHourlyPlanIsSoldAndDeliveredByTheHour(): void
    {
        $this->send($this->tap(PurchaseHandler::planCallback($this->hourly->id)));
        self::assertSame([BotTexts::paragraphs(self::text(BotText::PlanDetails, [
            'plan' => 'شش‌ساعته',
            'description' => '',
            'traffic' => '۲ گیگابایت',
            'duration' => '۶ ساعت',
            'devices' => '۱ کاربر',
            'price' => Money::format('5000'),
        ]), self::text(BotText::PlanPickServer))], $this->said());

        $checkout = PurchaseHandler::serverCallback($this->hourly->id, $this->server->id);
        $this->send($this->tap($checkout));
        $this->send($this->tap(CallbackData::build($checkout, $this->walletMethod()->id)));

        self::assertSame([self::text(BotText::PaySuccess, [
            'client' => 'USER_1', // the update's profile has no handle
            'plan' => 'شش‌ساعته',
            'server' => 'آلمان',
            'duration' => '۶ ساعت',
            'traffic' => '۲ گیگابایت',
            'subscription' => 'https://fake.test/sub/sub-1',
        ])], $this->said());
        self::assertSame(6 * 3600, FakeProvider::$created[0]['spec']->expiry->pendingSeconds(), 'six hours from the first connection');
        $service = Subscription::query()->sole();
        self::assertSame([6, null, true], [$service->duration_hours, $service->expires_at, $service->awaitsFirstUse()]);
    }

    public function testThePanelsTermIsReadBackByTheHour(): void
    {
        $service = $this->subscription($this->ali, $this->hourly, $this->server, 'ali_1', ['starts_at' => null, 'expires_at' => null]);
        $provisioning = $this->service(ProvisioningService::class);

        FakeProvider::put($this->server, new ClientInfo('ali_1', true, totalBytes: 2 * Traffic::GIGABYTE, expiry: Expiry::afterFirstUse(5400)));
        $provisioning->inspect($service);
        self::assertSame([2, null], [$service->refresh()->duration_hours, $service->expires_at], 'an hour and a half held back reads as two: a part of an hour is one');

        FakeProvider::put($this->server, new ClientInfo('ali_1', true, totalBytes: 2 * Traffic::GIGABYTE, expiry: Expiry::at(new \DateTimeImmutable('2026-09-20 17:00:00'))));
        $provisioning->inspect($service);
        $service->refresh();
        self::assertSame(['2026-09-20 17:00:00', '2026-09-20 15:00:00'], [$service->expires_at?->format('Y-m-d H:i:s'), $service->starts_at?->format('Y-m-d H:i:s')], 'the clock started: its start counted back by its two hours');
    }

    public function testAutoRenewalLeavesATermShorterThanItsWindowAlone(): void
    {
        $this->botSettings('auto_renew', ['auto_renew_days' => 2, 'auto_renew_default' => true]);
        $twoDays = $this->plan(['name' => 'دو روزه', 'duration_hours' => 48], $this->server);

        $this->buy($this->ali, $this->hourly, $this->server);
        $this->buy($this->ali, $twoDays, $this->server);
        $hourly = Subscription::query()->where('plan_id', $this->hourly->id)->sole();
        $window = Subscription::query()->where('plan_id', $twoDays->id)->sole();
        self::assertSame([false, true], [$hourly->auto_renew, $window->auto_renew], 'the admin\'s default only where the service can renew itself: a term of the window at least');

        $autoRenewal = $this->service(AutoRenewal::class);
        self::assertSame([false, true], [$autoRenewal->offeredFor($hourly), $autoRenewal->offeredFor($window)]);

        // Switched on all the same (before the rule), and due: a renewal of six hours would leave it due again at once.
        $hourly->forceFill(['auto_renew' => true, 'starts_at' => now()->subHours(3), 'expires_at' => now()->addHours(3)])->save();
        FakeProvider::mirror($hourly);
        $orders = Order::query()->count();
        $this->service(AutoRenewTask::class)->run();
        self::assertSame($orders, Order::query()->count(), 'nothing renewed, nothing charged');
    }

    public function testAnEndIsNotRemindedOfWhenTheTermIsNoLongerThanTheReminderWindow(): void
    {
        $this->botSettings('reminders', ['expiry_reminder' => true, 'expiry_reminder_days' => 3, 'traffic_reminder' => false, 'traffic_reminder_percent' => 80]);
        $threeDays = $this->plan(['name' => 'سه روزه', 'duration_hours' => 72], $this->server);
        $this->subscription($this->ali, $this->hourly, $this->server, 'ali_1', ['starts_at' => now()->subHours(4), 'expires_at' => now()->addHours(2)]);
        $this->subscription($this->ali, $threeDays, $this->server, 'ali_2', ['starts_at' => now()->subDay(), 'expires_at' => now()->addDays(2)]);
        $month = $this->subscription($this->ali, $this->plan(), $this->server, 'ali_3', ['expires_at' => now()->addDays(2)]);

        $this->telegram()->reset();
        $this->service(SendRemindersTask::class)->run();

        self::assertCount(1, $this->telegram()->sentTo(self::CHAT), 'a term within the window would be reminded the moment its clock started');
        self::assertNotNull($month->refresh()->expiry_reminded_at);
    }
}
