<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Modules\Telegram\Context;
use App\Modules\Telegram\Handler;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Texts\BotTexts;

/**
 * /start, /menu and a contact card the gates let through: the greeting and the main menu (MainMenu::show()), then the
 * screen a /start link opens — `?start=usertest` the free test. A link a gate held back waits in the chat for an hour
 * (ChatSession::takeStartLink()) and opens with the update that gets through: the contact card, /start or /menu here,
 * or «عضو شدم» (JoinHandler).
 */
final class StartHandler implements Handler
{
    public function __construct(
        private readonly MainMenu $menu,
        private readonly BotTexts $texts,
        private readonly TestAccountHandler $tests,
    ) {}

    public function handle(Context $ctx): void
    {
        $kept = $ctx->session->takeStartLink();
        $this->open($ctx, $ctx->update->commandArgument() ?? $kept);
    }

    /** The greeting and the menu, then the screen the /start link `$link` opens, as a new message under them. */
    public function open(Context $ctx, ?string $link): void
    {
        $this->menu->show($ctx, $this->texts->welcome($ctx->user->name()));
        if ($link === TestAccountHandler::START_LINK) {
            $this->tests->home($ctx, fresh: true);
        }
    }
}
