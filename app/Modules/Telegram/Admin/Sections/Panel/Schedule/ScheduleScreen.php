<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Schedule;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Panel\Access;
use App\Modules\Telegram\Admin\Sections\Panel\Config\AdvancedScreen;
use App\Modules\Telegram\Admin\Sections\Panel\PanelSettingsScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «زمان‌بندی کارها» (the owner's, in the main shop): every scheduled task in the order a run takes them — how often it
 * runs, each opening its own screen with the times it decides by (ScheduleTaskScreen) —, when Cron last ran (a warning
 * while it never did), and every default put back at once, asked first. The schedule is saved whole (PUT /schedule),
 * as the panel's one form is.
 *
 * Actions: `reset` (asks), `defaults` (every field back to its default).
 */
final class ScheduleScreen extends BaseScreen
{
    public const ID = 'ppj';

    public function allows(AdminSeat $seat): bool
    {
        return Access::main($seat);
    }

    public function show(Admin $admin, array $args): View
    {
        $schedule = self::read($admin);
        $tasks = self::tasks($schedule);
        $ran = is_string($schedule['last_run_at'] ?? null) ? $schedule['last_run_at'] : null;
        $view = View::make('⏱ زمان‌بندی کارها', 'مدیریت', 'تنظیمات', 'تنظیمات پنل')
            ->line('هر کار زمان‌بندی‌شده هر چند وقت یک بار اجرا شود، و زمان‌هایی که بر اساسشان تصمیم می‌گیرد. برای همه فروشگاه‌ها یکی است و در دیتابیس ذخیره می‌شود؛ پیش‌فرض‌ها همان‌هایی هستند که فروشگاه تا امروز با آن‌ها کار می‌کرد.')
            ->line()
            ->line('<i>کارها به ترتیبی آمده‌اند که اجرا می‌شوند: Cron هر دقیقه اجرا می‌شود و هر کاری را که موعدش رسیده، به همین ترتیب انجام می‌دهد. کاری که نشان «🏪 در هر فروشگاه» دارد، در فروشگاه اصلی و فروشگاه هر نماینده یکی پس از دیگری اجرا می‌شود؛ بقیه یک بار برای همه.</i>');
        if ($ran !== null) {
            $view->line("آخرین اجرای \u{2068}Cron\u{2069}: " . Fmt::date($ran, true));
        } else {
            $view->line("⚠️ \u{2068}Cron\u{2069} هنوز اجرا نشده است؛ تا راه نیفتد، هیچ‌کدام از این کارها انجام نمی‌شود.");
        }

        $buttons = [];
        foreach ($tasks as $index => $task) {
            $interval = self::fields($task)[0] ?? null;
            $every = $interval === null ? '' : ' · هر ' . Fmt::number((int) ($interval['value'] ?? 0)) . ' ' . (string) ($interval['unit'] ?? '');
            $changed = array_filter(self::fields($task), static fn(array $field): bool => (int) ($field['value'] ?? 0) !== (int) ($field['default'] ?? 0)) !== [];
            $buttons[] = Button::to((Json::on($task, 'each_shop') ? '🏪 ' : '⚙️ ') . (string) ($task['title'] ?? '') . $every . ($changed ? ' ✏️' : ''), ScheduleTaskScreen::ID, 'show', $index);
        }
        $view->grid(1, ...$buttons);
        if ($ran === null) {
            $view->row(Button::to("🛠 راه‌اندازی \u{2068}Cron\u{2069}", AdvancedScreen::ID));
        }
        if (!self::allDefault($tasks)) {
            $view->row(Button::to('↩️ بازگشت به همه پیش‌فرض‌ها', self::ID, 'reset'));
        }

        return $view->back(PanelSettingsScreen::ID);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        switch ($action) {
            case 'reset':
                return $admin->confirm(
                    'همه زمان‌ها به پیش‌فرض برگردند؟',
                    Button::to('↩️ بله، همه پیش‌فرض', self::ID, 'defaults'),
                    Button::to('✖️ انصراف', self::ID),
                    'هر کار و هر زمانی که تغییر داده‌اید، به همان پیش‌فرضی برمی‌گردد که فروشگاه تا امروز با آن کار می‌کرد.',
                );
            case 'defaults':
                $tasks = self::tasks(self::read($admin));
                $body = [];
                foreach ($tasks as $task) {
                    foreach (self::fields($task) as $field) {
                        $body[(string) ($field['name'] ?? '')] = (int) ($field['default'] ?? 0);
                    }
                }
                $admin->api->put('/schedule', $body);
                $admin->notice('✅ زمان‌بندی ذخیره شد.');
                break;
        }

        return $this->show($admin, []);
    }

    /**
     * GET /schedule's `schedule`.
     *
     * @return array<string, mixed>
     */
    public static function read(Admin $admin): array
    {
        return (array) ($admin->api->get('/schedule')['schedule'] ?? []);
    }

    /**
     * The tasks, in the order a run takes them.
     *
     * @param array<string, mixed> $schedule
     * @return list<array<string, mixed>>
     */
    public static function tasks(array $schedule): array
    {
        return array_values(array_filter(Json::part($schedule, 'tasks'), is_array(...)));
    }

    /**
     * A task's fields, its interval first.
     *
     * @param array<string, mixed> $task
     * @return list<array<string, mixed>>
     */
    public static function fields(array $task): array
    {
        return array_values(array_filter(Json::part($task, 'fields'), is_array(...)));
    }

    /**
     * Every field of every task, by its name: what PUT /schedule takes whole.
     *
     * @param list<array<string, mixed>> $tasks
     * @return array<string, int>
     */
    public static function body(array $tasks): array
    {
        $body = [];
        foreach ($tasks as $task) {
            foreach (self::fields($task) as $field) {
                $body[(string) ($field['name'] ?? '')] = (int) ($field['value'] ?? 0);
            }
        }

        return $body;
    }

    /** @param list<array<string, mixed>> $tasks */
    private static function allDefault(array $tasks): bool
    {
        foreach ($tasks as $task) {
            foreach (self::fields($task) as $field) {
                if ((int) ($field['value'] ?? 0) !== (int) ($field['default'] ?? 0)) {
                    return false;
                }
            }
        }

        return true;
    }
}
