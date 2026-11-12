<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\Number;
use App\Core\Forms\FieldSpec;
use App\Core\Forms\Form;
use App\Core\Scheduling\Scheduler;
use App\Modules\Bots\Models\Bot;
use App\Modules\Settings\Services\ScheduleSettings;
use App\Modules\Settings\Services\Settings;

/**
 * «زمان‌بندی کارها», the owner's screen: every scheduled task in the order a run takes them — what it does, whether it
 * runs in each shop, when it last ran and is next due — with the fields it is set by: how often it runs, and the times
 * it decides by (ScheduleSettings). Saved as one form, kept with the main shop; a value equal to its default is not
 * kept, so a default a later version improves still reaches the shop.
 */
final class ScheduleScreen
{
    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly ScheduleSettings $schedule,
        private readonly Settings $settings,
    ) {}

    /** @return array{tasks: list<array<string, mixed>>, last_run_at: string|null} */
    public function present(): array
    {
        $runs = $this->scheduler->lastRuns();
        $tasks = [];
        foreach ($this->scheduler->tasks() as $class => $task) {
            [$title, $description] = ScheduleSettings::TASKS[$task['key']] ?? [$task['key'], ''];
            $last = $runs[$class] ?? null;
            $tasks[] = [
                'key' => $task['key'],
                'title' => $title,
                'description' => $description,
                'each_shop' => $task['eachBot'],
                'last_ran_at' => $last?->format(DATE_ATOM),
                'next_run_at' => $last?->modify('+' . $this->scheduler->interval($class) . ' seconds')->format(DATE_ATOM),
                'fields' => array_map($this->field(...), $this->fieldsOf($task['key'], $task['interval'])),
            ];
        }

        return ['tasks' => $tasks, 'last_run_at' => $this->scheduler->lastRunAt()?->format(DATE_ATOM)];
    }

    /**
     * Check every field and keep them all, or nothing; one equal to its default is forgotten.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function save(array $input): void
    {
        $form = new Form('schedule', $this->fields());
        $this->settings->save($form, $input, Bot::MAIN);
        foreach ($form->fields as $field) {
            if ($this->settings->read($field, Bot::MAIN) === $field->default) {
                $this->settings->forget($field->key, Bot::MAIN);
            }
        }
    }

    /**
     * Every task's fields, in the screen's order.
     *
     * @return list<Number>
     */
    private function fields(): array
    {
        $fields = [];
        foreach ($this->scheduler->tasks() as $task) {
            array_push($fields, ...$this->fieldsOf($task['key'], $task['interval']));
        }

        return $fields;
    }

    /**
     * A task's fields: its interval — its default the schedule's — and the times it decides by.
     *
     * @return list<Number>
     */
    private function fieldsOf(string $key, int $defaultSeconds): array
    {
        return [$this->schedule->intervalField($key, intdiv($defaultSeconds, 60)), ...$this->schedule->timingFields($key)];
    }

    /** @return array{name: string, label: string, hint: string|null, unit: string, value: int, default: int, min: int, max: int} */
    private function field(Number $field): array
    {
        $spec = $field->spec ?? new FieldSpec($field->label);

        return [
            'name' => $field->name,
            'label' => $spec->label,
            'hint' => $spec->hint,
            'unit' => $field->unit,
            'value' => $this->settings->read($field, Bot::MAIN),
            'default' => $field->default,
            'min' => $field->min,
            'max' => $field->max,
        ];
    }
}
