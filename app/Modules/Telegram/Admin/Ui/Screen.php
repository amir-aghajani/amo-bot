<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Ui;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;

/**
 * One management screen in the bot's private chat: a section of the panel (its list, a row's page, a settings card).
 * Registered by its id in Screens. Each method returns the View to show next — itself again after an action, another
 * screen's (Admin::open()), a question (Admin::ask()) or a confirmation (Admin::confirm()). The panels' API answers for
 * it through Admin::$api; an ApiRefusal thrown out of an action is shown to the admin (a popup, or under the question it
 * answered), and the screen stays as it was.
 */
interface Screen
{
    /** Whether `$seat` may open it (and see it on the menus): the owner, an agent, an admin with a grant. */
    public function allows(AdminSeat $seat): bool;

    /**
     * The screen, opened with `$args` (Nav::SHOW).
     *
     * @param list<string> $args
     * @throws ApiRefusal
     */
    public function show(Admin $admin, array $args): View;

    /**
     * A button pressed on it: `$action` with its arguments.
     *
     * @param list<string> $args
     * @throws ApiRefusal
     */
    public function act(Admin $admin, string $action, array $args): View;

    /**
     * The answer to a question it asked (Prompt): `$action` and its arguments as the question kept them.
     *
     * @param list<string> $args
     * @throws ApiRefusal
     */
    public function input(Admin $admin, string $action, array $args, Answer $answer): View;
}
