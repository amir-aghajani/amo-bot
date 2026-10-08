<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ServiceReminders;
use App\Modules\Subscriptions\Tasks\SendRemindersTask;
use App\Modules\Telegram\Handlers\SubscriptionHandler;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\User;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Tests\BotTestCase;

/**
 * «یادآوری»: a customer is told in the bot that a service ends soon, or that most of its traffic is used —
 * as the admin set it, once each while the service stays past the threshold, and again after it came back
 * under it (renewed, given more traffic, no longer running). A service that renews itself hears about its deadline
 * from the renewal instead (not about its traffic); a banned customer hears nothing; a run sends a batch at most.
 */
final class ServiceRemindersTest extends BotTestCase
{
    private User $ali;
    private Server $server;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-20 12:00:00');
        $this->fakePanel();
        $this->ali = $this->customer(['username' => 'ali']);
        $this->server = $this->fakeServer();
        $this->plan = $this->plan();
    }

    public function testNothingIsSentWhileTheRemindersAreOff(): void
    {
        $this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['expires_at' => now()->addDay(), 'download_bytes' => 29 * Traffic::GIGABYTE]);

        $this->remind();

        self::assertSame([], $this->calls());
    }

    public function testAServiceEndingSoonIsRemindedOnceUntilItsDeadlineMoves(): void
    {
        $this->enable(expiry: true);
        $ending = $this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['expires_at' => now()->addDays(2)]);
        $this->subscription($this->ali, $this->plan, $this->server, 'ali_2', ['expires_at' => now()->addDays(10)]);
        $this->subscription($this->ali, $this->plan, $this->server, 'ali_3', ['starts_at' => null, 'expires_at' => null]);

        $this->remind();

        self::assertSame(['sendMessage'], $this->calls(), 'the one within the admin\'s three days');
        self::assertSame([$this->endsSoon($ending, hint: true)], $this->telegram()->sentTo(self::CHAT), 'with the switch that would renew it');
        self::assertSame([[['text' => self::text(BotText::ReminderOpenService), 'callback_data' => SubscriptionHandler::serviceCallback($ending->id)]]], $this->inlineKeyboard(0));

        $this->remind();
        self::assertSame([], $this->calls(), 'once');

        // Renewed: the deadline leaves the window, and the reminder is armed for the new one.
        $ending->forceFill(['expires_at' => now()->addDays(32)])->save();
        $this->remind();
        self::assertSame([], $this->calls());
        self::assertNull($ending->refresh()->expiry_reminded_at);

        Carbon::setTestNow(now()->addDays(30));
        $this->remind();
        self::assertSame(['sendMessage'], $this->calls(), 'near the new deadline, reminded again');
    }

    public function testAServiceThatRenewsItselfAndABannedCustomerAreNotReminded(): void
    {
        $this->enable(expiry: true);
        $renewing = $this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['expires_at' => now()->addDays(2), 'auto_renew' => true]);
        $sara = $this->customer(['telegram_id' => 1002, 'username' => 'sara', 'status' => UserStatus::Banned]);
        $this->subscription($sara, $this->plan, $this->server, 'sara_1', ['expires_at' => now()->addDays(2)]);

        $this->remind();
        self::assertSame([], $this->calls(), 'the renewal speaks for itself; a banned customer hears nothing');

        // The wallet switched off: nothing will renew it, so the customer is told — without the switch.
        $this->walletMethod()->forceFill(['enabled' => false])->save();
        $this->remind();
        self::assertSame([$this->endsSoon($renewing, hint: false)], $this->telegram()->sentTo(self::CHAT));
        self::assertSame(['sendMessage'], $this->calls());
    }

    public function testAServiceThatRenewsItselfIsStillToldItsTrafficRunsLow(): void
    {
        $this->enable(expiry: true, traffic: true);
        $renewing = $this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['expires_at' => now()->addDays(2), 'auto_renew' => true, 'download_bytes' => 25 * Traffic::GIGABYTE]);

        $this->remind();

        self::assertSame([$this->runsLow($renewing)], $this->telegram()->sentTo(self::CHAT), 'the renewal is by date: the traffic is still news');
    }

    public function testATrafficRunningLowIsRemindedOnceUntilMoreIsGiven(): void
    {
        $this->enable(traffic: true);
        $low = $this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['download_bytes' => 25 * Traffic::GIGABYTE]);
        $this->subscription($this->ali, $this->plan, $this->server, 'ali_2', ['download_bytes' => 10 * Traffic::GIGABYTE]);
        $this->subscription($this->ali, $this->plan, $this->server, 'ali_3', ['traffic_limit_bytes' => 0, 'download_bytes' => 500 * Traffic::GIGABYTE]);

        $this->remind();

        self::assertSame(['sendMessage'], $this->calls(), 'past the admin\'s 80 percent; an unlimited one never');
        self::assertSame([$this->runsLow($low)], $this->telegram()->sentTo(self::CHAT));
        self::assertSame([[['text' => self::text(BotText::ReminderOpenService), 'callback_data' => SubscriptionHandler::serviceCallback($low->id)]]], $this->inlineKeyboard(0));

        $this->remind();
        self::assertSame([], $this->calls(), 'once');

        // More traffic (a grant, a renewal): under the threshold again, and armed for the next time.
        $low->forceFill(['traffic_limit_bytes' => 60 * Traffic::GIGABYTE])->save();
        $this->remind();
        self::assertSame([], $this->calls());
        self::assertNull($low->refresh()->traffic_reminded_at);

        $low->forceFill(['download_bytes' => 50 * Traffic::GIGABYTE])->save();
        $this->remind();
        self::assertSame([$this->runsLow($low)], $this->telegram()->sentTo(self::CHAT), 'near again: reminded again');
    }

    public function testAServiceNoLongerRunningIsArmedForItsNextRun(): void
    {
        $this->enable(traffic: true);
        $used = $this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['download_bytes' => 25 * Traffic::GIGABYTE]);
        $this->remind();
        self::assertNotNull($used->refresh()->traffic_reminded_at);

        // It ran out (the sync learned it from its panel): whenever it runs again, its next time near is news.
        $used->forceFill(['status' => SubscriptionStatus::Expired])->save();
        $this->remind();

        self::assertNull($used->refresh()->traffic_reminded_at);
    }

    public function testBothRemindersForOneServiceComeApart(): void
    {
        $this->enable(expiry: true, traffic: true);
        $both = $this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['expires_at' => now()->addDay(), 'download_bytes' => 28 * Traffic::GIGABYTE]);

        $this->remind();

        self::assertSame([$this->endsSoon($both, hint: true), $this->runsLow($both)], $this->telegram()->sentTo(self::CHAT));
    }

    public function testAtMostABatchOfRemindersGoesOutARun(): void
    {
        $batch = (int) (new \ReflectionClassConstant(ServiceReminders::class, 'BATCH'))->getValue();
        $this->enable(expiry: true, traffic: true);
        $ending = [];
        foreach (range(1, $batch + 1) as $n) {
            $ending[] = $this->subscription($this->ali, $this->plan, $this->server, "ali_{$n}", ['expires_at' => now()->addDay()]);
        }
        $low = $this->subscription($this->ali, $this->plan, $this->server, 'ali_low', ['download_bytes' => 25 * Traffic::GIGABYTE]);

        $this->remind();
        self::assertCount($batch, $this->calls(), 'a batch, whatever the kind');

        $this->remind();
        self::assertSame([$this->endsSoon(end($ending), hint: true), $this->runsLow($low)], $this->telegram()->sentTo(self::CHAT), 'the rest on the next run');
    }

    private function enable(bool $expiry = false, bool $traffic = false): void
    {
        $this->botSettings('reminders', [
            'expiry_reminder' => $expiry,
            'expiry_reminder_days' => 3,
            'traffic_reminder' => $traffic,
            'traffic_reminder_percent' => 80,
        ]);
    }

    /** One run of the scheduled task, with what Telegram was told before it forgotten. */
    private function remind(): void
    {
        $this->telegram()->reset();
        $this->service(SendRemindersTask::class)->run();
    }

    /** The reminder that the service ends soon — with the «تمدید خودکار» hint when it could renew itself. */
    private function endsSoon(Subscription $subscription, bool $hint): string
    {
        return self::text(BotText::ExpiryReminder, [
            'client' => $subscription->remote_name,
            'expires' => Messages::expiry($subscription->expires_at, $subscription->duration_days),
            'remaining' => Messages::bytes((int) $subscription->remainingBytes()),
            'hint' => $hint ? self::text(BotText::ReminderAutoRenewHint) : '',
        ]);
    }

    /** The reminder that most of the service's traffic is used. */
    private function runsLow(Subscription $subscription): string
    {
        return self::text(BotText::TrafficReminder, [
            'percent' => Messages::percent($subscription->usedBytes(), $subscription->traffic_limit_bytes),
            'client' => $subscription->remote_name,
            'remaining' => Messages::bytes((int) $subscription->remainingBytes()),
            'traffic' => Messages::traffic($subscription->traffic_limit_bytes),
            'expires' => Messages::expiry($subscription->expires_at, $subscription->duration_days),
        ]);
    }
}
