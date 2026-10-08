<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Channels;

use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Keyboard\InlineKeyboard;
use App\Modules\Telegram\Models\BotChannel;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;

/**
 * The "join these first" screen: one link button per channel still missing, then the button that asks
 * the bot to look again (`join:check`, answered by Handlers\JoinHandler).
 */
final class JoinPrompt
{
    public const CHECK = 'join:check';

    public function __construct(private readonly BotTexts $texts) {}

    /**
     * The screen as a message of its own (the gate), or in place of the tapped one (a re-check that still
     * finds some missing — trimmed to those).
     *
     * @param list<BotChannel> $missing
     */
    public function show(Context $ctx, array $missing, bool $inPlace = false): void
    {
        $keyboard = InlineKeyboard::make();
        foreach ($missing as $channel) {
            $keyboard->row(InlineKeyboard::url($this->texts->render(BotText::JoinChannel, ['title' => $channel->title]), $channel->link()));
        }
        $keyboard->row(InlineKeyboard::callback($this->texts->get(BotText::JoinButton), self::CHECK, 'success'));

        $text = $this->texts->get(BotText::JoinPrompt);
        $options = ['reply_markup' => $keyboard->build()] + BotApi::NO_LINK_PREVIEW;
        if ($inPlace) {
            $ctx->edit($text, $options);
        } else {
            $ctx->reply($text, $options);
        }
    }
}
