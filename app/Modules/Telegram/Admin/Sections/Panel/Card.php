<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsScreen;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * A card of the website's or the mini app's settings, saved a field at a time as SettingsScreen saves and drawn as it
 * draws a card — the summary, the buttons, the panel's warnings over it (warnings()), what the card is for and the steps
 * somewhere else (a BotFather's, a Google Cloud's) folded away under it (intro(), with the values it is drawn with:
 * drawn()), and what the card has besides as sections of its own (extra(): an address to copy, a test).
 */
abstract class Card extends SettingsScreen
{
    /**
     * @var array<string, mixed> The values the card is being drawn with (card()), for its intro's steps
     */
    private array $drawn = [];

    protected function card(Admin $admin, array $values): View
    {
        $this->drawn = $values;

        return parent::card($admin, $values);
    }

    /**
     * The values the card is being drawn with — an intro's steps say the website's address —; none outside a drawing.
     *
     * @return array<string, mixed>
     */
    protected function drawn(): array
    {
        return $this->drawn;
    }
}
