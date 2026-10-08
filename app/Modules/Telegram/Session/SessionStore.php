<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Session;

use App\Modules\Telegram\Models\TelegramSession;

final class SessionStore
{
    /**
     * The chat's session with the current bot — a private chat is its customer's —, its row made the first time the bot
     * serves the chat: two updates of a brand-new chat at once both get the one row (the second finds the first's).
     */
    public function load(int $chatId): ChatSession
    {
        return new ChatSession(TelegramSession::of($chatId) ?? TelegramSession::query()->createOrFirst(['chat_id' => $chatId], ['data' => []]));
    }
}
