<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Gates;

use App\Modules\Telegram\Channels\ChannelMembership;
use App\Modules\Telegram\Channels\JoinPrompt;
use App\Modules\Telegram\Context;

/**
 * Required channels ("عضویت اجباری در کانال"): a customer who is not a member of every listed channel gets
 * the join screen instead of an answer, whatever they sent. The "عضو شدم" button is let through to
 * Handlers\JoinHandler, which re-checks and opens the menu. Who the rule holds for, and how long a confirmed
 * membership is trusted, is ChannelMembership's.
 */
final class ChannelGate implements Gate
{
    public function __construct(
        private readonly ChannelMembership $membership,
        private readonly JoinPrompt $prompt,
    ) {}

    public function pass(Context $ctx): bool
    {
        if ($ctx->update->callbackData() === JoinPrompt::CHECK) {
            return true;
        }

        $missing = $this->membership->missing($ctx);
        if ($missing === []) {
            return true;
        }

        $this->prompt->show($ctx, $missing);

        return false;
    }
}
