<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use Illuminate\Database\Eloquent\Model;

/**
 * A chat's conversation state with one bot (two bots, two sessions), read and written through Session\ChatSession. The
 * chat is the customer's private one, so `chat_id` is their Telegram id.
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
