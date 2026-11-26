<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Schedule;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Panel\Access;
use App\Modules\Telegram\Admin\Sections\Panel\PanelSettingsScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * One scheduled task (ScheduleScreen's, by its place): what it does, when it last ran and is next due, how often it runs,
 * from what time of day («ساعت شروع», "HH:MM") and the times it decides by — each beside its default, typed anew or put
 * back to it, the schedule saved whole with the one changed.
 *
 * Actions: `e` (task, field: asks), `df` (task, field: its default), `es` (task: asks its start), `ds` (task: its start's
 * default).
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
        $view = View::make((Json::on($task, 'each_shop') ? '🏪 ' : '⚙️ ') . (string) ($task['title'] ?? ''), ...PanelSettingsScreen::under(Fmt::bare(ScheduleScreen::TITLE)))
            ->line(Fmt::e((string) ($task['description'] ?? '')));
        $facts = [
            'اجرا' => Json::on($task, 'each_shop') ? 'در هر فروشگاه' : 'یک بار برای همه',
            'آخرین اجرا' => is_string($task['last_ran_at'] ?? null) ? Fmt::date($task['last_ran_at'], true) : 'هنوز اجرا نشده',
            'اجرای بعدی' => $next !== null && $next->isFuture() ? Fmt::date($next->toIso8601String(), true) : "در نوبت بعدی \u{2068}Cron\u{2069}",
        ];
        $hints = [];
        $buttons = [];
        $start = ScheduleScreen::start($task);
        foreach (ScheduleScreen::fields($task) as $place => $field) {
            $label = (string) ($field['label'] ?? '');
            $value = (int) ($field['value'] ?? 0);
            $default = (int) ($field['default'] ?? 0);
            // The value, and the default beside it while it differs: what «↩️ پیش‌فرض» would put back.
            $facts[$label] = self::amount($field, $value, $place === 0) . ($value === $default ? '' : ' ✏️ (پیش‌فرض: ' . self::amount($field, $default, false) . ')');
            if (is_string($field['hint'] ?? null) && $field['hint'] !== '') {
                $hints[] = '<b>' . Fmt::e($label) . '</b>: ' . Fmt::e($field['hint']);
            }
            $buttons[] = Button::to(Words::edit($label), self::ID, 'e', $index, $place);
            if ($place === 0) {
                // Its start, right after its interval: the two say when it runs.
                $facts[$start['label']] = Fmt::digits($start['value']) . ($start['value'] === $start['default'] ? '' : ' ✏️ (پیش‌فرض: ' . Fmt::digits($start['default']) . ')');
                $hints[] = '<b>' . Fmt::e($start['label']) . '</b>: ' . Fmt::e($start['hint']);
                $buttons[] = Button::to(Words::edit($start['label']), self::ID, 'es', $index);
            }
        }

        return $view->card($facts)->tiles(...$buttons)->help(implode("\n\n", $hints))->back(ScheduleScreen::ID);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $tasks = ScheduleScreen::tasks(ScheduleScreen::read($admin));
        if (in_array($action, ['es', 'ds'], true)) {
            return $this->startAct($admin, $action, $tasks, self::id($args));
        }
        $field = ScheduleScreen::fields($tasks[self::id($args)] ?? [])[self::id($args, 1)] ?? null;
        if ($field === null) {
            return $this->show($admin, $args);
        }
        if ($action === 'e') {
            $prompt = (new Prompt((string) ($field['label'] ?? ''), self::ID, 'e', [self::id($args), self::id($args, 1)], Fmt::e(is_string($field['hint'] ?? null) ? $field['hint'] : ''), Button::to(Words::CANCEL, self::ID, 'show', self::id($args))))
                ->current(Fmt::digits((int) ($field['value'] ?? 0)))
                ->limits(is_int($field['min'] ?? null) && is_int($field['max'] ?? null)
                    ? 'از ' . Fmt::number($field['min']) . ' تا ' . Fmt::number($field['max']) . ' ' . (string) ($field['unit'] ?? '')
                    : 'یک عدد، به ' . (string) ($field['unit'] ?? ''));
            if ((int) ($field['value'] ?? 0) !== (int) ($field['default'] ?? 0)) {
                $prompt->resettable(self::ID, 'df', self::id($args), self::id($args, 1));
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
        if ($action === 'es' && isset($tasks[self::id($args)])) {
            // As typed — «۰۲:۳۰», "2:30" —: the server reads it, and refuses what is no time of day.
            $this->save($admin, $tasks, ScheduleScreen::start($tasks[self::id($args)])['name'], trim($answer->text()));

            return $this->show($admin, [(string) self::id($args)]);
        }
        $field = ScheduleScreen::fields($tasks[self::id($args)] ?? [])[self::id($args, 1)] ?? null;
        if ($action === 'e' && $field !== null) {
            $value = $answer->integer() ?? throw new ApiRefusal(422, Words::NOT_NUMBER);
            $this->save($admin, $tasks, (string) ($field['name'] ?? ''), $value);
        }

        return $this->show($admin, [(string) self::id($args)]);
    }

    /**
     * A task's start: `es` asks it, `ds` puts its default back.
     *
     * @param list<array<string, mixed>> $tasks
     */
    private function startAct(Admin $admin, string $action, array $tasks, int $index): View
    {
        $task = $tasks[$index] ?? null;
        if ($task === null) {
            return $admin->open(ScheduleScreen::ID);
        }
        $start = ScheduleScreen::start($task);
        if ($action === 'ds') {
            $this->save($admin, $tasks, $start['name'], $start['default']);

            return $this->show($admin, [(string) $index]);
        }
        $prompt = (new Prompt($start['label'], self::ID, 'es', [$index], Fmt::e($start['hint']), Button::to(Words::CANCEL, self::ID, 'show', $index)))
            ->current(Fmt::digits($start['value']))
            ->example('۰۰:۰۳')
            ->limits('از ۰۰:۰۰ تا ۲۳:۵۹، به وقت منطقه زمانی فروشگاه');
        if ($start['value'] !== $start['default']) {
            $prompt->resettable(self::ID, 'ds', $index);
        }

        return $admin->ask($prompt);
    }

    /**
     * The schedule saved whole, `$name` as `$value`.
     *
     * @param list<array<string, mixed>> $tasks
     * @throws ApiRefusal
     */
    private function save(Admin $admin, array $tasks, string $name, int|string $value): void
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
