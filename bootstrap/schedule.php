<?php

declare(strict_types=1);

use App\Core\Scheduling\Scheduler;
use App\Modules\Accounts\Tasks\PruneAccountsTask;
use App\Modules\Orders\Tasks\ExpireOrdersTask;
use App\Modules\Orders\Tasks\ResumeDeliveriesTask;
use App\Modules\Payments\Tasks\AutoApproveReceiptsTask;
use App\Modules\Rewards\Tasks\NightlyDrawTask;
use App\Modules\Subscriptions\Tasks\AutoRenewTask;
use App\Modules\Subscriptions\Tasks\CleanupServicesTask;
use App\Modules\Subscriptions\Tasks\GrantsTask;
use App\Modules\Subscriptions\Tasks\NextPeriodTask;
use App\Modules\Subscriptions\Tasks\SendRemindersTask;
use App\Modules\Subscriptions\Tasks\SyncSubscriptionsTask;
use App\Modules\Support\Tasks\PruneTicketsTask;
use App\Modules\Telegram\Tasks\PruneUpdatesTask;
use App\Modules\Telegram\Tasks\SendBroadcastsTask;
use App\Modules\Telegram\Tasks\SendReportsTask;
use App\Modules\Updates\Tasks\CheckReleasesTask;

/*
 * Scheduled tasks, in the order a run takes them. `php bin/console schedule:run` (or the /cron/{token} URL, or bot:poll
 * while it polls) should be triggered every minute; the scheduler decides which tasks are due. A shop's own work
 * (`eachBot: true`) runs in every bot's shop in turn — the main bot's and each agent's, with that bot's rules and texts;
 * the servers' work runs across all of them at once. One run's time is shared over them (Core\Scheduling\Budget).
 */
return static function (Scheduler $schedule): void {
    // Card-to-card receipts nobody reviewed inside their method's window.
    $schedule->everyMinutes(1, AutoApproveReceiptsTask::class, eachBot: true);
    // Paid orders a process that died left undelivered (never a failed delivery: that one waits for support).
    $schedule->everyMinutes(1, ResumeDeliveriesTask::class, eachBot: true);
    // Checkouts the customer walked away from, dropped once nothing happened to them for two days — one made with a
    // discount code once the code's hold is over, its use given back.
    $schedule->everyMinutes(ExpireOrdersTask::INTERVAL_MINUTES, ExpireOrdersTask::class, eachBot: true);
    // Broadcasts (/broadcast) the bot started, a batch per minute until everyone has it.
    $schedule->everyMinutes(1, SendBroadcastsTask::class, eachBot: true);
    // «تمدید خودکار»: services within the admin's days of their deadline, renewed from the wallet.
    $schedule->everyMinutes(AutoRenewTask::INTERVAL_MINUTES, AutoRenewTask::class, eachBot: true);
    // A renewed period begins where the paid one ended: what that one left unused goes, unless carried.
    $schedule->everyMinutes(10, NextPeriodTask::class, eachBot: true);
    // «افزودن زمان و حجم» and «هدیه همگانی» no screen is working on: a batch per minute, and a panel that was out of reach tried again.
    $schedule->everyMinutes(1, GrantsTask::class);
    // Every running service read from its panel — one call per server — and then «یادآوری»: services near their
    // end or low on traffic are reminded. In this order, so the reminders read what the sync just brought.
    $schedule->everyMinutes(15, SyncSubscriptionsTask::class);
    $schedule->everyMinutes(15, SendRemindersTask::class, eachBot: true);
    // The services that are done with removed from their panels, by each shop's rules: its ended services so many hours
    // after they ended (off until it switches it on), its free tests that ended or never connected — in every shop that
    // has some, a stopped agent's too; after the sync, so it reads what the sync just brought.
    $schedule->everyMinutes(15, CleanupServicesTask::class);
    // The updates the bots took, remembered so one Telegram sends again is served once: forgotten once it cannot come again.
    $schedule->everyMinutes(60, PruneUpdatesTask::class);
    // The websites' sessions that ended, the sign-in challenges that expired and the notices half a year old, every shop's.
    $schedule->everyMinutes(60, PruneAccountsTask::class);
    // The tickets' housekeeping, each shop's: the pictures uploaded to tickets closed a month ago, and the group's week-old
    // reports of tickets that are closed.
    $schedule->everyMinutes(60, PruneTicketsTask::class, eachBot: true);
    // «قرعه‌کشی شبانه», each shop's: once its clock passed the hour of the draw, the day's draw among its buyers, once.
    $schedule->everyMinutes(10, NightlyDrawTask::class, eachBot: true);
    // AmoBot's newest release read from GitHub once a day, so the owner's dashboard says when one is out.
    $schedule->everyMinutes(24 * 60, CheckReleasesTask::class);
    // The report groups: what the panel and the tasks above queued, at Telegram's pace. Last, so a tick's own reports go in them.
    $schedule->everyMinutes(1, SendReportsTask::class, eachBot: true);
};
