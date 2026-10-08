<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use App\Modules\Telegram\Reports\Topic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A topic the bot made in its admins' report group — the one connected now (Reports\ReportGroupState) —, for one
 * subject (Reports\Topic). See Reports\ReportTopics.
 *
 * @property int $id
 * @property Topic $topic
 * @property int|null $thread_id Its message_thread_id; null while it is being made (again)
 * @property string|null $lease_token Whoever is making it (Core\Database\Lease)
 * @property Carbon|null $leased_until
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ReportTopic extends Model
{
    use BelongsToBot;

    protected $table = 'report_topics';

    protected $fillable = ['topic', 'thread_id'];

    /** @var array<string, string> */
    protected $casts = [
        'topic' => Topic::class,
        'thread_id' => 'integer',
        'leased_until' => 'datetime',
    ];
}
