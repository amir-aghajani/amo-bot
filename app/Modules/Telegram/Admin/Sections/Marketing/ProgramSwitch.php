<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Settings\Origin;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * A program's settings card (cashback, gift codes, the wheel, the lottery) whose first field is the program's switch:
 * turned on from the program's list (`on`, buttons()) — its card opened afresh as it saves, so its «⬅️ بازگشت» is the
 * list, whatever menu opened the card before — through the card's own save, the one place the switch is saved.
 */
trait ProgramSwitch
{
    /** The action that turns the program on from its list. */
    public const ON = 'on';

    /**
     * The list's row to this card, `$program` the program's name: «▶️ روشن کردن «…»» while the program is off — green,
     * as it turns something on —, and «⚙️ تنظیمات».
     *
     * @return array{0: ?Button, 1: Button}
     */
    public static function buttons(bool $on, string $program): array
    {
        return [$on ? null : Button::to(Words::turn(true, $program), self::ID, self::ON)->success(), Button::to(Words::SETTINGS, self::ID)];
    }

    protected function action(Admin $admin, string $action, array $args, array $values): View
    {
        if ($action !== self::ON) {
            return parent::action($admin, $action, $args, $values);
        }
        Origin::opened($admin, $this->screen(), []);

        return $this->turn($admin, 0, $this->fields()[0], $values, true, false);
    }
}
