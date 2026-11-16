<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Search;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsScreen;

/**
 * One thing the bot's settings search finds (SettingsIndex): a section of the panel's settings — a card, a list —, or one
 * of its settings, with the panel's words for it and where the bot has it: a screen, a part of a card, a field of one
 * (opened by its API name: SettingsScreen::FIELD) — or, for a setting the bot has not by nature, the screen that says why.
 */
final class SettingsEntry
{
    /**
     * @param list<string> $trail Where it is in the bot, outermost first: «تنظیمات › تنظیمات ربات › کیف پول»
     * @param list<string> $words Other words it is looked for by: the English term, how owners write it
     * @param list<int|string> $args What the screen is opened with (a list's kind)
     * @param list<string> $near Its section's other words
     */
    public function __construct(
        /** The setting's key in its section (PanelSettings); '' for the section itself. */
        public readonly string $key,
        public readonly string $title,
        public readonly string $hint,
        public readonly array $trail,
        public readonly array $words,
        /** The bot screen it opens. */
        public readonly string $screen,
        public readonly array $args = [],
        /** The field of that API name opened on the screen (a SettingsScreen); null for the screen itself. */
        public readonly ?string $field = null,
        /** The part of the card opened (SettingsScreen::parts()); null for the card itself. */
        public readonly ?int $part = null,
        /** Why the bot has it not — the panel alone sets it —; '' for one the bot sets. */
        public readonly string $only = '',
        /** Its section's other words, found as its place is (a setting's: the panel's sectionKeywords). */
        public readonly array $near = [],
        /** Whether its card has it in the main shop alone (the lottery's «نماینده‌ها شرکت داده نشوند»). */
        public readonly bool $mainShop = false,
    ) {}

    /** Whether `$seat` finds it, its screen aside (Admin::opens()): one the main shop's card alone has, there alone. */
    public function shownTo(AdminSeat $seat): bool
    {
        return !$this->mainShop || $seat->shop()->isMain();
    }

    /** The button that opens it, under `$label`. */
    public function button(string $label): Button
    {
        return match (true) {
            $this->field !== null => Button::to($label, $this->screen, SettingsScreen::FIELD, $this->field),
            $this->part !== null => Button::to($label, $this->screen, SettingsScreen::PART, $this->part),
            default => Button::to($label, $this->screen, Nav::SHOW, ...$this->args),
        };
    }

    /** Whether it is a section — a card, a list — rather than one of its settings. */
    public function isSection(): bool
    {
        return $this->key === '';
    }
}
