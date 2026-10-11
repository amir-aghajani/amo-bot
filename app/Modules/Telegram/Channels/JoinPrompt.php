<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Channels;

use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Keyboard\InlineKeyboard;
use App\Modules\Telegram\Models\BotChannel;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;

/** The join screen: a link button per missing channel, then «عضو شدم» (`join:check`, JoinHandler). */
final class JoinPrompt
{
    public const CHECK = 'join:check';

    public function __construct(private readonly BotTexts $texts) {}

    /**
     * The screen as a new message (the gate), or in place of the tapped one (a re-check still missing some).
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
