<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Session;

use App\Modules\Telegram\Models\TelegramSession;

/** Loads a chat's session with the current bot. */
final class SessionStore
{
    /** The chat's session, its row made on first use (createOrFirst: two first updates at once share one row). */
    public function load(int $chatId): ChatSession
    {
        return new ChatSession(TelegramSession::of($chatId) ?? TelegramSession::query()->createOrFirst(['chat_id' => $chatId], ['data' => []]));
    }
}
