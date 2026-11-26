<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Scheduling\Scheduler;
use App\Modules\Backups\Tasks\SendBackupsTask;
use App\Modules\Bots\Models\Bot;
use App\Modules\Settings\Models\Setting;
use App\Modules\Settings\Services\ScheduleSettings;
use App\Modules\Subscriptions\Services\AutoRenewal;
use App\Modules\Subscriptions\Tasks\SyncSubscriptionsTask;
use App\Support\LocalTime;
use Illuminate\Support\Carbon;
use Tests\HttpTestCase;

/**
 * «زمان‌بندی کارها», the owner's: every scheduled task with what it does, when it ran and when it is next due on the
 * shop's clock, each set by its interval, its start and the times it decides by — saved whole, checked against their
 * bounds, kept with the main shop (a default kept as none), and read by the scheduler and the tasks at once.
 */
final class AdminScheduleApiTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAsAdmin();
    }

    protected function tearDown(): void
    {
        LocalTime::use('UTC');

        parent::tearDown();
    }

    public function testEveryScheduledTaskIsListedInTheRunsOrderWithItsWordsAndFields(): void
    {
        $schedule = $this->decode($this->get('/api/admin/schedule'))['schedule'];
        $tasks = array_column($schedule['tasks'], null, 'key');

        self::assertSame(array_column($this->service(Scheduler::class)->tasks(), 'key'), array_keys($tasks), 'every task, in the order a run takes them');
        self::assertSame([], array_values(array_diff(array_keys($tasks), array_keys(ScheduleSettings::TASKS))), 'each has its words: a new task cannot be left unnamed');

        $sync = $tasks['sync_subscriptions'];
        self::assertSame(['همگام‌سازی سرویس‌ها', false, null, null], [$sync['title'], $sync['each_shop'], $sync['last_ran_at'], $sync['next_run_at']]);
        self::assertSame([['name' => 'sync_subscriptions_minutes', 'label' => 'فاصله اجرا', 'hint' => null, 'unit' => 'دقیقه', 'value' => 15, 'default' => 15, 'min' => 1, 'max' => ScheduleSettings::EVERY_MAX]], $sync['fields']);
        self::assertSame(['name' => 'sync_subscriptions_start', 'label' => 'ساعت شروع', 'hint' => ScheduleSettings::START_HINT, 'value' => '00:05', 'default' => '00:05'], $sync['start'], 'its runs counted from 00:05: :05, :20, :35, :50');
        self::assertSame(['00:00', '03:00'], [$tasks['send_backups']['start']['default'], $tasks['prune_imports']['start']['default']], 'the backup at midnight, the heavier tasks apart');
        self::assertTrue($tasks['expire_orders']['each_shop']);
        self::assertSame(['online_payments_minutes', 'online_payments_check_minutes', 'online_payments_late_hours', 'online_payments_late_check_minutes', 'online_payments_review_hours'], array_column($tasks['online_payments']['fields'], 'name'), 'its interval first, then the times it decides by');
        self::assertSame([48, 'ساعت'], [$tasks['expire_orders']['fields'][1]['value'], $tasks['expire_orders']['fields'][1]['unit']]);
    }

    public function testTheScheduleSavesWholeKeepsItWithTheMainShopAndADefaultAsNone(): void
    {
        $this->service(Scheduler::class)->run(force: true);
        $listed = $this->decode($this->get('/api/admin/schedule'))['schedule'];
        self::assertNotNull($listed['last_run_at']);
        $sync = array_column($listed['tasks'], null, 'key')['sync_subscriptions'];
        self::assertNotNull($sync['last_ran_at']);
        [$last, $next] = [strtotime((string) $sync['last_ran_at']), strtotime((string) $sync['next_run_at'])];
        self::assertTrue($next > $last && $next <= $last + 15 * 60 && (int) gmdate('i', $next) % 15 === 5 && gmdate('s', $next) === '00', 'due again at the next moment of its grid: :05, :20, :35 or :50');

        $saved = $this->decode($this->putJson('/api/admin/schedule', ['sync_subscriptions_minutes' => '۶۰', 'auto_renew_minutes' => 20, 'expire_orders_hours' => 72, 'send_backups_start' => '۰۲:۳۰', 'check_releases_start' => '3:5'] + $this->defaults()))['schedule'];
        $fields = self::fields($saved);
        self::assertSame([60, 20, 72], [$fields['sync_subscriptions_minutes'], $fields['auto_renew_minutes'], $fields['expire_orders_hours']], 'Persian digits too');
        $starts = array_column(array_column($saved['tasks'], 'start'), 'value', 'name');
        self::assertSame(['02:30', '03:05'], [$starts['send_backups_start'], $starts['check_releases_start']], 'a time as typed: Persian digits, one digit for the hour or the minute');
        self::assertSame(150, $this->service(Scheduler::class)->start(SendBackupsTask::class), 'the scheduler reads it');

        self::assertSame(60 * 60, $this->service(Scheduler::class)->interval(SyncSubscriptionsTask::class), 'the scheduler reads it');
        self::assertSame(20 + AutoRenewal::LATE_RUN_MINUTES, $this->service(AutoRenewal::class)->lastRunMinutes(), 'a renewal by a fresh term waits for the last run before its end, by the owner\'s interval');
        self::assertSame(72, $this->service(ScheduleSettings::class)->orderHours());
        self::assertSame(
            ['schedule.auto_renew.minutes', 'schedule.check_releases.start', 'schedule.expire_orders_hours', 'schedule.send_backups.start', 'schedule.sync_subscriptions.minutes'],
            Setting::query()->where('bot_id', Bot::MAIN)->where('key', 'like', 'schedule.%')->orderBy('key')->pluck('key')->all(),
            'only what differs from its default is kept, with the main shop',
        );

        $this->putJson('/api/admin/schedule', $this->defaults());
        self::assertSame(0, Setting::query()->where('key', 'like', 'schedule.%')->count(), 'back to the defaults: nothing kept, so a later default reaches the shop');
        self::assertSame(15 * 60, $this->service(Scheduler::class)->interval(SyncSubscriptionsTask::class));
    }

    public function testTheNextRunIsOnTheShopsClockAndAStartTypedWithACommaIsRead(): void
    {
        LocalTime::use('Asia/Tehran');
        Carbon::setTestNow(Carbon::parse('2026-11-26 12:00:00', 'UTC'));
        $this->service(Scheduler::class)->run(force: true);

        $backup = array_column($this->decode($this->get('/api/admin/schedule'))['schedule']['tasks'], null, 'key')['send_backups'];
        self::assertSame('2026-11-26T20:30:00+00:00', $backup['next_run_at'], 'the daily backup at the shop\'s midnight: 00:00 in Tehran is 20:30 UTC');

        $saved = $this->decode($this->putJson('/api/admin/schedule', ['send_backups_start' => '۰۲،۳۰', 'prune_imports_start' => '4,15', 'check_releases_start' => "\u{00A0}9\t:\u{202F}30"] + $this->defaults()))['schedule'];
        $starts = array_column(array_column($saved['tasks'], 'start'), 'value', 'name');
        self::assertSame(['02:30', '04:15', '09:30'], [$starts['send_backups_start'], $starts['prune_imports_start'], $starts['check_releases_start']], '«،» or "," for the colon, any space left out: a tab, a non-breaking one');
        $backup = array_column($saved['tasks'], null, 'key')['send_backups'];
        self::assertSame('2026-11-26T23:00:00+00:00', $backup['next_run_at'], 'and its next run with it: 02:30 in Tehran');
    }

    public function testWhatNoTaskCouldRunByIsRefusedEveryRefusalAtOnceAndNothingKept(): void
    {
        $response = $this->putJson('/api/admin/schedule', ['sync_subscriptions_minutes' => 0, 'notices_keep_days' => 5000, 'online_payments_late_hours' => 10, 'online_payments_review_hours' => 5, 'prune_imports_start' => '24:00', 'check_releases_start' => '9:30am'] + $this->defaults());

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([
            'sync_subscriptions_minutes' => ['فاصله اجرای «همگام‌سازی سرویس‌ها» باید عددی بین 1 تا 10080 دقیقه باشد.'],
            'notices_keep_days' => ['نگه‌داری اعلان‌های وب‌سایت باید عددی بین 1 تا 3650 روز باشد.'],
            'prune_imports_start' => ['ساعت شروع را به شکل ۰۰:۰۳ بنویسید، از ۰۰:۰۰ تا ۲۳:۵۹.'],
            'check_releases_start' => ['ساعت شروع را به شکل ۰۰:۰۳ بنویسید، از ۰۰:۰۰ تا ۲۳:۵۹.'],
            'online_payments_review_hours' => ['مهلت پول تسویه‌نشده نمی‌تواند از پرسیدن پس از بسته شدن صفحه کمتر باشد.'],
        ], $this->decode($response)['errors']);
        self::assertSame(0, Setting::query()->where('key', 'like', 'schedule.%')->count());

        $missing = $this->defaults();
        unset($missing['send_reports_minutes']);
        self::assertSame(422, $this->unchecked()->putJson('/api/admin/schedule', $missing)->getStatusCode(), 'saved whole: a field left out is refused');
    }

    public function testTheRequestNamesEveryFieldTheScreenHasAndNothingElse(): void
    {
        $request = self::apiDescription()->components->schemas['ScheduleRequest'];

        self::assertEqualsCanonicalizing(array_keys($this->defaults()), array_keys($request->properties));
        self::assertEqualsCanonicalizing(array_keys($this->defaults()), $request->required);
    }

    public function testItIsTheOwnersAlone(): void
    {
        $bot = $this->agentBot();
        $this->loginAsAgent($bot);

        self::assertSame(404, $this->get('/api/agent/schedule')->getStatusCode());
    }

    /** @return array<string, int|string> Every field and start, at its default */
    private function defaults(): array
    {
        $fields = [];
        foreach ($this->decode($this->get('/api/admin/schedule'))['schedule']['tasks'] as $task) {
            foreach ($task['fields'] as $field) {
                $fields[$field['name']] = $field['default'];
            }
            $fields[$task['start']['name']] = $task['start']['default'];
        }

        return $fields;
    }

    /**
     * @param array{tasks: list<array{fields: list<array{name: string, value: int}>}>} $schedule
     * @return array<string, int>
     */
    private static function fields(array $schedule): array
    {
        $fields = [];
        foreach ($schedule['tasks'] as $task) {
            foreach ($task['fields'] as $field) {
                $fields[$field['name']] = $field['value'];
            }
        }

        return $fields;
    }
}
