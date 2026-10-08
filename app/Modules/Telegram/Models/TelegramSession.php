<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use Illuminate\Database\Eloquent\Model;

/**
 * Conversation state per Telegram chat with a bot (e.g. "waiting for top-up amount") — the same chat with two bots is
 * two sessions. A customer is served in their private chat only, so the chat is theirs (`chat_id` is their Telegram id).
 * Read and written through Session\ChatSession.
 *
 * @property int $id
 * @property int $chat_id
 * @property string|null $state
 * @property array<string, mixed>|null $data
 */
class TelegramSession extends Model
{
    use BelongsToBot;

    public $timestamps = false;

    protected $table = 'telegram_sessions';

    protected $fillable = ['chat_id', 'state', 'data'];

    /** @var array<string, string> */
    protected $casts = [
        'chat_id' => 'integer',
        'data' => 'array',
    ];

    /** The current bot's session with this chat, if there is one. */
    public static function of(int $chatId): ?self
    {
        return static::query()->where('chat_id', $chatId)->first();
    }
}
