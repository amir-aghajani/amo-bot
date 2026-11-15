<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Schedule;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Panel\Access;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * One scheduled task (ScheduleScreen's, by its place): what it does, when it last ran and is next due, how often it runs
 * and the times it decides by — each beside its default, typed anew or put back to it, the schedule saved whole with the
 * one changed.
 *
 * Actions: `e` (task, field: asks), `df` (task, field: its default).
 */
final class ScheduleTaskScreen extends BaseScreen
{
    public const ID = 'ppjt';

    public function allows(AdminSeat $seat): bool
    {
        return Access::main($seat);
    }

    public function show(Admin $admin, array $args): View
    {
        $tasks = ScheduleScreen::tasks(ScheduleScreen::read($admin));
        $index = self::id($args);
        $task = $tasks[$index] ?? null;
        if ($task === null) {
            return $admin->open(ScheduleScreen::ID);
        }
        $next = Fmt::moment(is_string($task['next_run_at'] ?? null) ? $task['next_run_at'] : null);
        $view = View::make('⚙️ ' . (string) ($task['title'] ?? ''), 'مدیریت', 'تنظیمات پنل', 'زمان‌بندی کارها');
        if (Json::on($task, 'each_shop')) {
            $view->line('🏪 در هر فروشگاه');
        }
        $view->line(Fmt::e((string) ($task['description'] ?? '')))
            ->line('<i>' . (is_string($task['last_ran_at'] ?? null) ? 'آخرین اجرا: ' . Fmt::date($task['last_ran_at'], true) : 'هنوز اجرا نشده') . ' · اجرای بعدی: '
                . ($next !== null && $next->isFuture() ? Fmt::date($next->toIso8601String(), true) : "در نوبت بعدی \u{2068}Cron\u{2069}") . '</i>');

        $buttons = [];
        foreach (ScheduleScreen::fields($task) as $place => $field) {
            $view->line()->line('• <b>' . Fmt::e((string) ($field['label'] ?? '')) . '</b>: ' . self::amount($field, (int) ($field['value'] ?? 0), $place === 0)
                . ' <i>(پیش‌فرض: ' . self::amount($field, (int) ($field['default'] ?? 0), false) . ')</i>');
            if (is_string($field['hint'] ?? null) && $field['hint'] !== '') {
                $view->line('<i>' . Fmt::e($field['hint']) . '</i>');
            }
            $buttons[] = Button::to('✏️ ' . (string) ($field['label'] ?? '') . ': ' . Fmt::plain(self::amount($field, (int) ($field['value'] ?? 0), false)), self::ID, 'e', $index, $place);
        }

        return $view->grid(1, ...$buttons)->back(ScheduleScreen::ID);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $tasks = ScheduleScreen::tasks(ScheduleScreen::read($admin));
        $field = ScheduleScreen::fields($tasks[self::id($args)] ?? [])[self::id($args, 1)] ?? null;
        if ($field === null) {
            return $this->show($admin, $args);
        }
        if ($action === 'e') {
            $hint = [];
            if (is_string($field['hint'] ?? null) && $field['hint'] !== '') {
                $hint[] = Fmt::e($field['hint']);
            }
            $hint[] = 'الان: ' . self::amount($field, (int) ($field['value'] ?? 0), false) . ' · پیش‌فرض: ' . self::amount($field, (int) ($field['default'] ?? 0), false);
            $hint[] = 'یک عدد بنویسید (' . Fmt::e((string) ($field['unit'] ?? '')) . ').';
            $prompt = new Prompt((string) ($field['label'] ?? ''), self::ID, 'e', [self::id($args), self::id($args, 1)], implode("\n", $hint), Button::to('✖️ لغو', self::ID, 'show', self::id($args)));
            if ((int) ($field['value'] ?? 0) !== (int) ($field['default'] ?? 0)) {
                $prompt->choices(Button::to('↩️ پیش‌فرض: ' . Fmt::plain(self::amount($field, (int) ($field['default'] ?? 0), false)), self::ID, 'df', self::id($args), self::id($args, 1)));
            }

            return $admin->ask($prompt);
        }
        if ($action === 'df') {
            $this->save($admin, $tasks, (string) ($field['name'] ?? ''), (int) ($field['default'] ?? 0));
        }

        return $this->show($admin, [(string) self::id($args)]);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        $tasks = ScheduleScreen::tasks(ScheduleScreen::read($admin));
        $field = ScheduleScreen::fields($tasks[self::id($args)] ?? [])[self::id($args, 1)] ?? null;
        if ($action === 'e' && $field !== null) {
            $value = $answer->integer() ?? throw new ApiRefusal(422, 'یک عدد بنویسید.');
            $this->save($admin, $tasks, (string) ($field['name'] ?? ''), $value);
        }

        return $this->show($admin, [(string) self::id($args)]);
    }

    /**
     * The schedule saved whole, `$name` as `$value`.
     *
     * @param list<array<string, mixed>> $tasks
     * @throws ApiRefusal
     */
    private function save(Admin $admin, array $tasks, string $name, int $value): void
    {
        $admin->api->put('/schedule', array_replace(ScheduleScreen::body($tasks), [$name => $value]));
        $admin->notice('✅ زمان‌بندی ذخیره شد.');
    }

    /**
     * A field's number with its unit — «هر» before a task's interval —, HTML.
     *
     * @param array<string, mixed> $field
     */
    private static function amount(array $field, int $value, bool $every): string
    {
        return ($every ? 'هر ' : '') . Fmt::number($value) . ' ' . Fmt::e((string) ($field['unit'] ?? ''));
    }
}
