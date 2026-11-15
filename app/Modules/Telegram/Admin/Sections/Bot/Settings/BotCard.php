<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Settings;

use App\Modules\Settings\Services\BotSettingsScreen;
use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Bot\BotSettingsMenu;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Settings\BotGroupSource;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsScreen;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsSource;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * A card of «تنظیمات ربات» (a bot settings group, BotGroupSource) as the bot shows it — its explanations, warnings and
 * «❔ راهنما» SettingsScreen's —, under the menu of them. A card may pick a field's value among the panel's own rows
 * (pick: a plan, the methods switched on), or ask and read a field its own way (a list of amounts with prices).
 *
 * Actions besides SettingsScreen's: `pk` (i, value: field i picked; `_` for none), and a card's own (action()).
 */
abstract class BotCard extends SettingsScreen
{
    /** A pick's value that is none. */
    protected const NONE = '_';

    public function __construct(protected readonly BotSettingsScreen $settings) {}

    /** The bot settings group the card saves (PUT /bot/settings/{group}). */
    abstract protected function group(): string;

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    protected function path(): array
    {
        return ['مدیریت', 'تنظیمات', 'تنظیمات ربات'];
    }

    protected function parent(): string
    {
        return BotSettingsMenu::ID;
    }

    protected function source(): SettingsSource
    {
        return new BotGroupSource($this->group(), $this->settings);
    }

    /**
     * The values a field is picked among, by the panel's own rows (the plans, the methods switched on), each with its
     * label; null for a field picked or typed as FieldDef says.
     *
     * @param array<string, mixed> $values
     * @return array<int|string, string>|null By value (a number's key is a number)
     * @throws ApiRefusal
     */
    protected function picks(Admin $admin, FieldDef $field, array $values): ?array
    {
        return null;
    }

    /**
     * A field asked the card's own way (a list of amounts with prices); null for FieldDef's.
     *
     * @param array<string, mixed> $values
     */
    protected function question(Admin $admin, int $index, FieldDef $field, array $values): ?View
    {
        return null;
    }

    /**
     * A typed answer read the card's own way for a field: `['value' => …]`, or null for FieldDef's reading.
     *
     * @return array{value: mixed}|null
     * @throws ApiRefusal 422 under the field for an answer it cannot read
     */
    protected function parse(FieldDef $field, Answer $answer): ?array
    {
        return null;
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        if ($action === 'e') {
            $this->here = $admin;
            $index = self::id($args);
            $field = $this->fields()[$index] ?? null;
            $values = $field === null ? [] : $this->source()->load($admin);
            if ($field !== null && $field->shown($values)) {
                $edited = $this->edited($admin, $index, $field, $values);
                if ($edited !== null) {
                    return $edited;
                }
            }
        }

        return parent::act($admin, $action, $args);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        $this->here = $admin;
        $field = $this->fields()[self::id($args)] ?? null;
        if ($action === 'e' && $field !== null) {
            $read = $this->parse($field, $answer);
            if ($read !== null) {
                return $this->store($admin, $field, $this->source()->load($admin), $read['value']);
            }
        }

        return parent::input($admin, $action, $args, $answer);
    }

    /**
     * A field's button pressed, before FieldDef's way: picked among the panel's rows (picks()), or asked the card's own
     * way (question()); null for FieldDef's way.
     *
     * @param array<string, mixed> $values
     * @throws ApiRefusal
     */
    protected function edited(Admin $admin, int $index, FieldDef $field, array $values): ?View
    {
        $picks = $this->picks($admin, $field, $values);

        return $picks !== null ? $this->picker($index, $field, $values, $picks, 1) : $this->question($admin, $index, $field, $values);
    }

    /** `pk` (i, value: field i picked among its rows; `_` for none), `pkp` (i, page: its rows' page); the card again for any other. */
    protected function action(Admin $admin, string $action, array $args, array $values): View
    {
        $index = self::id($args);
        $field = $this->fields()[$index] ?? null;
        $picks = in_array($action, ['pk', 'pkp'], true) && $field !== null && $field->shown($values) ? $this->picks($admin, $field, $values) : null;
        if ($field === null || $picks === null) {
            return $this->card($admin, $values);
        }
        if ($action === 'pkp') {
            return $this->picker($index, $field, $values, $picks, self::page($args, 1));
        }
        $value = $args[1] ?? self::NONE;
        if ($value !== self::NONE && !isset($picks[$value])) {
            return $this->card($admin, $values);
        }

        return $this->store($admin, $field, $values, $value === self::NONE ? null : (ctype_digit($value) ? (int) $value : $value));
    }

    /**
     * A field's values to pick among, the one it has marked: page `$page` of them past one keyboard's worth.
     *
     * @param array<string, mixed> $values
     * @param array<int|string, string> $picks
     */
    private function picker(int $index, FieldDef $field, array $values, array $picks, int $page): View
    {
        $current = (string) ($values[$field->name] ?? self::NONE);
        $view = View::make($field->label, ...[...$this->path(), $this->title()]);
        if ($field->hint !== '') {
            $view->line($field->hint);
        }
        if ($picks === []) {
            $view->line()->line('موردی برای انتخاب نیست.');
        }
        $buttons = [];
        foreach ($picks as $value => $label) {
            $buttons[] = Button::to(($current === (string) $value ? '● ' : '') . $label, $this->screen(), 'pk', $index, (string) $value);
        }
        if ($field->nullable) {
            $buttons[] = Button::to(($current === self::NONE ? '● ' : '') . 'هیچ‌کدام', $this->screen(), 'pk', $index, self::NONE);
        }

        return $view->paged(1, $buttons, $page, fn(int $at): array => [$this->screen(), 'pkp', [$index, $at]])->back($this->screen(), self::VIEW);
    }
}
