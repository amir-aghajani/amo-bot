<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsScreen;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * A card of the website's or the mini app's settings, saved a field at a time as SettingsScreen saves: with what the
 * panel says under each field — its hint, a switch's too — and lines over the fields that read the values (a warning
 * the panel shows over the card, the steps somewhere else).
 */
abstract class Card extends SettingsScreen
{
    /**
     * Lines over the fields (HTML), from the values.
     *
     * @param array<string, mixed> $values
     * @return list<string>
     */
    protected function lead(array $values): array
    {
        return [];
    }

    protected function card(Admin $admin, array $values): View
    {
        $view = View::make($this->title(), ...$this->path());
        if ($this->intro() !== '') {
            $view->line($this->intro());
        }
        foreach ($this->lead($values) as $line) {
            $view->line($line);
        }
        $buttons = [];
        foreach ($this->fields() as $index => $field) {
            if (!$field->shown($values)) {
                continue;
            }
            if ($buttons === []) {
                $view->line();
            }
            $view->line('• <b>' . Fmt::e($field->label) . '</b>: ' . $field->display($values[$field->name] ?? null));
            if ($field->hint !== '') {
                $view->line('<i>' . $field->hint . '</i>');
            }
            // A switch's button carries the value it sets (SettingsScreen): a second tap changes nothing.
            $buttons[] = $this->fieldButton($index, $field, $values);
        }
        $view->grid(1, ...$buttons);
        $this->extra($view, $admin, $values);

        return $this->back($admin, $view);
    }
}
