<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\Field;
use App\Core\Forms\Form;
use App\Modules\Settings\DeclaresSettings;
use App\Modules\Settings\Exceptions\UnknownGroupException;

/**
 * The bot settings screen («تنظیمات ربات», both panels, each bot its own): the groups the modules declare, shown together
 * and saved one at a time.
 */
final class BotSettingsScreen
{
    /** @param list<DeclaresSettings> $modules */
    public function __construct(
        private readonly Settings $settings,
        private readonly array $modules,
    ) {}

    /** @return array<string, mixed> Every group's fields by name; a name declared twice is a LogicException */
    public function present(): array
    {
        $values = [];
        foreach ($this->groups() as $group) {
            foreach ($this->settings->present($group) as $name => $value) {
                if (array_key_exists($name, $values)) {
                    throw new \LogicException("The bot settings field \"{$name}\" is declared twice.");
                }
                $values[$name] = $value;
            }
        }

        return $values;
    }

    /**
     * Check and keep one group's form.
     *
     * @param array<string, mixed> $input
     * @throws UnknownGroupException|ValidationException
     */
    public function save(string $group, array $input): void
    {
        foreach ($this->groups() as $declared) {
            if ($declared->key === $group) {
                $this->settings->save($declared, $input);

                return;
            }
        }

        throw new UnknownGroupException();
    }

    /**
     * The names of `$group`'s fields, in order: what a save of the group sends (the bot's management screens send the
     * whole group with one field changed).
     *
     * @return list<string>
     * @throws UnknownGroupException
     */
    public function names(string $group): array
    {
        foreach ($this->groups() as $declared) {
            if ($declared->key === $group) {
                return array_map(static fn(Field $field): string => $field->name, $declared->fields);
            }
        }

        throw new UnknownGroupException();
    }

    /**
     * The field of `$name` among every group's — its default and bounds, as the bot's management asks it —; null for
     * none.
     *
     * @return Field<mixed>|null
     */
    public function field(string $name): ?Field
    {
        foreach ($this->groups() as $group) {
            foreach ($group->fields as $field) {
                if ($field->name === $name) {
                    return $field;
                }
            }
        }

        return null;
    }

    /** @return list<Form> As the current bot's screen has them */
    private function groups(): array
    {
        return array_merge(...array_map(static fn(DeclaresSettings $module): array => $module->groups(), $this->modules));
    }
}
