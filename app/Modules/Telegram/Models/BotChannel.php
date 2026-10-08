<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A channel or group the customer must join before the bot serves them (see Channels\RequiredChannels). It always has
 * a way in: a public handle, or a private one's invite link.
 *
 * @property int $id
 * @property int $chat_id Telegram chat id (-100… for channels and supergroups)
 * @property string $type "channel" or "supergroup"
 * @property string $title
 * @property string|null $username Public handle without "@", null for private ones
 * @property string|null $invite_link A private one's invite link; null for a public one
 * @property bool $bot_is_admin What the last check found; the bot needs it to see the member list
 * @property Carbon|null $checked_at
 * @property int $sort
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class BotChannel extends Model
{
    use BelongsToBot;

    protected $table = 'bot_channels';

    protected $fillable = ['chat_id', 'type', 'title', 'username', 'invite_link', 'bot_is_admin', 'checked_at', 'sort'];

    /** @var array<string, string> */
    protected $casts = [
        'chat_id' => 'integer',
        'bot_is_admin' => 'boolean',
        'checked_at' => 'datetime',
        'sort' => 'integer',
    ];

    /** @return Builder<static> In the order the customer sees them. */
    public static function ordered(): Builder
    {
        return static::query()->oldest('sort')->oldest('id');
    }

    /** Where the customer is sent to join: the public handle's t.me link, else the private one's invite link. */
    public function link(): string
    {
        return $this->username !== null ? "https://t.me/{$this->username}" : ($this->invite_link ?? throw new \LogicException("Channel #{$this->id} has no way in."));
    }
}
