<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Scheduling\Scheduler;
use App\Modules\Bots\Models\Bot;
use App\Modules\Settings\Models\Setting;
use App\Modules\Settings\Services\ScheduleSettings;
use App\Modules\Subscriptions\Services\AutoRenewal;
use App\Modules\Subscriptions\Tasks\SyncSubscriptionsTask;
use Tests\HttpTestCase;

/**
 * «زمان‌بندی کارها», the owner's: every scheduled task with what it does and when it ran, each set by its interval and the
 * times it decides by — saved whole, checked against their bounds, kept with the main shop (a default kept as none), and
 * read by the scheduler and the tasks at once.
 */
final class AdminScheduleApiTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAsAdmin();
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
        self::assertSame(strtotime((string) $sync['last_ran_at']) + 15 * 60, strtotime((string) $sync['next_run_at']), 'due again after its interval');

        $saved = $this->decode($this->putJson('/api/admin/schedule', ['sync_subscriptions_minutes' => '۶۰', 'auto_renew_minutes' => 20, 'expire_orders_hours' => 72] + $this->defaults()))['schedule'];
        $fields = self::fields($saved);
        self::assertSame([60, 20, 72], [$fields['sync_subscriptions_minutes'], $fields['auto_renew_minutes'], $fields['expire_orders_hours']], 'Persian digits too');

        self::assertSame(60 * 60, $this->service(Scheduler::class)->interval(SyncSubscriptionsTask::class), 'the scheduler reads it');
        self::assertSame(20 + AutoRenewal::LATE_RUN_MINUTES, $this->service(AutoRenewal::class)->lastRunMinutes(), 'a renewal by a fresh term waits for the last run before its end, by the owner\'s interval');
        self::assertSame(72, $this->service(ScheduleSettings::class)->orderHours());
        self::assertSame(
            ['schedule.auto_renew.minutes', 'schedule.expire_orders_hours', 'schedule.sync_subscriptions.minutes'],
            Setting::query()->where('bot_id', Bot::MAIN)->where('key', 'like', 'schedule.%')->orderBy('key')->pluck('key')->all(),
            'only what differs from its default is kept, with the main shop',
        );

        $this->putJson('/api/admin/schedule', $this->defaults());
        self::assertSame(0, Setting::query()->where('key', 'like', 'schedule.%')->count(), 'back to the defaults: nothing kept, so a later default reaches the shop');
        self::assertSame(15 * 60, $this->service(Scheduler::class)->interval(SyncSubscriptionsTask::class));
    }

    public function testWhatNoTaskCouldRunByIsRefusedEveryRefusalAtOnceAndNothingKept(): void
    {
        $response = $this->putJson('/api/admin/schedule', ['sync_subscriptions_minutes' => 0, 'notices_keep_days' => 5000, 'online_payments_late_hours' => 10, 'online_payments_review_hours' => 5] + $this->defaults());

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([
            'sync_subscriptions_minutes' => ['فاصله اجرای «همگام‌سازی سرویس‌ها» باید عددی بین 1 تا 10080 دقیقه باشد.'],
            'notices_keep_days' => ['نگه‌داری اعلان‌های وب‌سایت باید عددی بین 1 تا 3650 روز باشد.'],
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

    /** @return array<string, int> Every field, at its default */
    private function defaults(): array
    {
        $fields = [];
        foreach ($this->decode($this->get('/api/admin/schedule'))['schedule']['tasks'] as $task) {
            foreach ($task['fields'] as $field) {
                $fields[$field['name']] = $field['default'];
            }
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
