<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Settings;

use App\Core\Forms\Fields\Field;
use App\Core\Forms\Fields\Number;
use App\Core\Forms\Fields\Numbers;
use App\Modules\Settings\Services\BotSettingsScreen;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Bot\BotSettingsMenu;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Settings\BotGroupSource;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsScreen;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsSource;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * A card of «تنظیمات ربات» (a bot settings group, BotGroupSource) as the bot shows it — its summary, explanations,
 * warnings, parts and «❔ راهنما» SettingsScreen's —, under the menu of them. A card may pick a field's value among the
 * panel's own rows (picks(): a plan, the methods switched on — the one in force marked ✅), or ask and read a field its
 * own way (a list of amounts with prices).
 *
 * Actions besides SettingsScreen's: `pk` (i, value: field i picked; `_` for none), `pkp` (i, page: its rows' page), and
 * a card's own (action()).
 */
abstract class BotCard extends SettingsScreen
{
    /** A pick's value that is none. */
    protected const NONE = '_';

    /**
     * @var array<string, Field<mixed>|false> The panel's own field of each name the card has (described()), false for none
     */
    private array $declared = [];

    public function __construct(protected readonly BotSettingsScreen $settings) {}

    /** The bot settings group the card saves (PUT /bot/settings/{group}). */
    abstract protected function group(): string;

    protected function path(): array
    {
        return [Words::ROOT, Fmt::bare(Words::SETTINGS), BotSettingsMenu::TITLE];
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
     * A card's fields with what the panel's own form says of each, where the card does not say it itself: a number's
     * bounds («از ۱ تا ۳۰ روز», its question's limits) and the value a number or a list of them comes with («↩️ پیش‌فرض»).
     * A card's fields() returns its list through it.
     *
     * @param list<FieldDef> $fields
     * @return list<FieldDef>
     */
    protected function described(array $fields): array
    {
        foreach ($fields as $field) {
            $declared = $this->declared[$field->name] ??= $this->settings->field($field->name) ?? false;
            if ($declared instanceof Number && $field->limits === '') {
                $unit = $declared->unit !== '' ? $declared->unit : $field->unit;
                $field->limits('از ' . Fmt::number($declared->min) . ' تا ' . Fmt::number($declared->max) . ($unit !== '' ? ' ' . $unit : ''));
            }
            if (($declared instanceof Number || $declared instanceof Numbers) && !$field->defaulted && in_array($field->type, [FieldDef::NUMBER, FieldDef::MONEY, FieldDef::LIST], true)) {
                $field->byDefault($declared->default);
            }
        }

        return $fields;
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
                $this->focus($admin, $field);
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
        $this->focus($admin, $field);
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
     * A field's values to pick among, the one it has marked ✅ (Words::PICKED) — «هیچ‌کدام» last for one that may be
     * none —: page `$page` of them past one keyboard's worth, the field's hint folded over them.
     *
     * @param array<string, mixed> $values
     * @param array<int|string, string> $picks
     */
    private function picker(int $index, FieldDef $field, array $values, array $picks, int $page): View
    {
        $current = (string) ($values[$field->name] ?? self::NONE);
        $view = View::make($field->label, ...[...$this->path(), Fmt::bare($this->title())]);
        if ($picks === []) {
            $view->line(self::NOTHING_TO_PICK);
        }
        $view->help($field->hint);
        $buttons = [];
        foreach ($picks as $value => $label) {
            $buttons[] = Button::to(($current === (string) $value ? Words::PICKED : '') . $label, $this->screen(), 'pk', $index, (string) $value);
        }
        if ($field->nullable) {
            $buttons[] = Button::to(($current === self::NONE ? Words::PICKED : '') . 'هیچ‌کدام', $this->screen(), 'pk', $index, self::NONE);
        }

        return $view->paged(1, $buttons, $page, fn(int $at): array => [$this->screen(), 'pkp', [$index, $at]])->back($this->screen(), self::VIEW);
    }
}
