<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Modules\Telegram\Context;
use App\Modules\Telegram\Handler;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Texts\BotTexts;

/** /start, /menu and a contact card the gates let through: the greeting and the main menu (MainMenu::show()). */
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
