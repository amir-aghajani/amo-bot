<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Modules\Telegram\Context;
use App\Modules\Telegram\Handler;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;

/** What no route takes — an unknown text, a button from a screen long gone: «متوجه نشدم» with the menu. */
final class FallbackHandler implements Handler
{
    public function __construct(
        private readonly MainMenu $menu,
        private readonly BotTexts $texts,
    ) {}

    public function handle(Context $ctx): void
    {
        $this->menu->show($ctx, $this->texts->get(BotText::Unknown));
    }
}
