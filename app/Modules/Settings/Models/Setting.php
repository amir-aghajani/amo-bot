<?php

declare(strict_types=1);

namespace App\Modules\Settings\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One runtime setting of a bot, its value JSON. No shop scope: the Settings service names `bot_id` on every query,
 * since some settings (the agency's) are the main bot's whoever reads them.
 *
 * @property int $id
 * @property int $bot_id
 * @property string $key
 * @property string|null $value
 * @property Carbon|null $updated_at
 */
class Setting extends Model
{
    public const CREATED_AT = null;

    protected $table = 'settings';

    protected $fillable = ['bot_id', 'key', 'value'];

    /** @var array<string, string> */
    protected $casts = [
        'bot_id' => 'integer',
    ];
}
