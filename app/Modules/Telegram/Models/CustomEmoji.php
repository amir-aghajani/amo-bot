<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A premium emoji an admin showed the bot (/emoji), offered by the editors' picker. See Emoji\CustomEmojis.
 *
 * @property int $id
 * @property string $emoji_id Telegram's custom_emoji_id (digits)
 * @property string $emoji The plain emoji it stands for — what a text carries inside its <tg-emoji>
 * @property string|null $file_id A still picture of it (the sticker's thumbnail, or the sticker itself), for the panel
 * @property string|null $format How Telegram draws it: CustomEmojis::STATIC, ANIMATED (Lottie) or VIDEO (WebM); null = not asked yet
 * @property string|null $animation_file_id The sticker that moves (an animated or video one), for the panel
 * @property bool $repaint Whether Telegram paints it in the colour of the text around it
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class CustomEmoji extends Model
{
    use BelongsToBot;

    protected $table = 'custom_emojis';

    protected $fillable = ['emoji_id', 'emoji', 'file_id', 'format', 'animation_file_id', 'repaint'];

    /** @var array<string, string> */
    protected $casts = ['repaint' => 'boolean'];
}
