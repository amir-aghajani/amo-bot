<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Form;
use App\Modules\Settings\DeclaresSettings;
use App\Modules\Settings\Exceptions\UnknownGroupException;

/**
 * The bot settings screen («تنظیمات ربات», the same in both panels — every bot its own): the groups of settings the
 * modules declare, registered in bootstrap/container.php, shown together and saved one group at a time. The screen
 * knows no module; a module's rules are its own class's.
 */
final class BotSettingsScreen
{
    /** @param list<DeclaresSettings> $modules */
    public function __construct(
        private readonly Settings $settings,
        private readonly array $modules,
    ) {}

    /** @return array<string, mixed> Every group's fields as the screen shows them, by name — one name, one field */
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
     * One group's form, checked and kept.
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

    /** @return list<Form> As the current bot's screen has them */
    private function groups(): array
    {
        return array_merge(...array_map(static fn(DeclaresSettings $module): array => $module->groups(), $this->modules));
    }
}
