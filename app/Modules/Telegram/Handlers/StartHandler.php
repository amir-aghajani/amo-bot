<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Modules\Telegram\Context;
use App\Modules\Telegram\Handler;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Texts\BotTexts;

/**
 * /start and /menu — and a contact card the gates let through — greet the customer and show the main menu as the
 * admin laid it out (MainMenu::show()).
 */
final class StartHandler implements Handler
{
    public function __construct(
        private readonly MainMenu $menu,
        private readonly BotTexts $texts,
    ) {}

    public function handle(Context $ctx): void
    {
        $this->menu->show($ctx, $this->texts->welcome($ctx->user->name()));
    }
}
