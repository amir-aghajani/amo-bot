<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Modules\Telegram\Channels\ChannelMembership;
use App\Modules\Telegram\Channels\JoinPrompt;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Handler;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;

/**
 * «عضو شدم» under the join screen: the memberships checked again without the cache. Still missing a channel, the screen
 * shrinks to what is left; all joined (or the rule switched off), the menu opens — and the /start link a gate held
 * back, if one waits (StartHandler::open()).
 */
final class JoinHandler implements Handler
{
    public function __construct(
        private readonly ChannelMembership $membership,
        private readonly JoinPrompt $prompt,
        private readonly StartHandler $start,
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
        $this->start->open($ctx, $ctx->session->takeStartLink());
    }
}
