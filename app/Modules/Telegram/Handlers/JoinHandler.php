<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Modules\Telegram\Channels\ChannelMembership;
use App\Modules\Telegram\Channels\JoinPrompt;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Handler;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;

/**
 * "عضو شدم" under the join screen: look the memberships up again (no cache). Still missing one — say
 * which, and trim the screen to what is left; all there — confirm and open the menu. With the rule
 * turned off meanwhile the tap simply opens the menu.
 */
final class JoinHandler implements Handler
{
    public function __construct(
        private readonly ChannelMembership $membership,
        private readonly JoinPrompt $prompt,
        private readonly MainMenu $menu,
        private readonly BotTexts $texts,
    ) {}

    public function handle(Context $ctx): void
    {
        $missing = $this->membership->missing($ctx, fresh: true);
        if ($missing !== []) {
            $ctx->answer($this->texts->render(BotText::JoinStillMissing, ['title' => $missing[0]->title]), alert: true);
            $this->prompt->show($ctx, $missing, inPlace: true);

            return;
        }

        $ctx->answer($this->texts->get(BotText::JoinDone));
        $ctx->edit($this->texts->message(BotText::JoinDone));
        $this->menu->show($ctx, $this->texts->welcome($ctx->user->name()));
    }
}
