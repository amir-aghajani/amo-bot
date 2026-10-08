<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Models;

use App\Core\Database\Casts\Encrypted;
use App\Modules\Bots\Models\Concerns\BelongsToBot;
use App\Modules\Telegram\Reports\GroupProblem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The report group of a bot as the shop knows it — one row per bot, read and written by Reports\ReportGroupState.
 *
 * @property int $id
 * @property int|null $chat_id The connected group (-100…); null while none is
 * @property string|null $title
 * @property Carbon|null $connected_at
 * @property GroupProblem|null $problem What keeps reports from reaching the group
 * @property Carbon|null $paused_until Until when sending waits (a flood limit, an unreachable Telegram, the group's trouble)
 * @property string|null $code The connect link's one-time code, encrypted at rest
 * @property Carbon|null $code_expires_at
 * @property string|null $attempt_title The last group offered with that code that could not become the report group…
 * @property GroupProblem|null $attempt_problem …and why not
 * @property Carbon|null $attempted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ReportChat extends Model
{
    use BelongsToBot;

    protected $table = 'report_chats';

    protected $fillable = ['chat_id', 'title', 'connected_at', 'problem', 'paused_until', 'code', 'code_expires_at', 'attempt_title', 'attempt_problem', 'attempted_at'];

    /** @var array<string, string> */
    protected $casts = [
        'chat_id' => 'integer',
        'connected_at' => 'datetime',
        'problem' => GroupProblem::class,
        'paused_until' => 'datetime',
        'code' => Encrypted::class,
        'code_expires_at' => 'datetime',
        'attempt_problem' => GroupProblem::class,
        'attempted_at' => 'datetime',
    ];
}
