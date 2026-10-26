<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use App\Modules\Providers\Models\Server;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A free test account given («اکانت تست») — what a customer's quota counts, kept after its service is gone. Claimed
 * (`issued_at` null) before its panel is asked and issued once the service is made (Services\TestAccounts); the terms
 * are kept as they were given.
 *
 * @property int $id
 * @property int $bot_id
 * @property int $user_id
 * @property int|null $server_id null once the server was deleted
 * @property int|null $subscription_id The test's service while it lasts
 * @property int $traffic_bytes
 * @property int $duration_hours
 * @property bool $on_hold whether its term counted from the first connection
 * @property Carbon|null $issued_at null while its panel is being asked
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Server|null $server
 * @property-read Subscription|null $subscription
 */
class TestAccount extends Model
{
    use BelongsToBot;

    protected $table = 'test_accounts';

    protected $fillable = ['user_id', 'server_id', 'subscription_id', 'traffic_bytes', 'duration_hours', 'on_hold', 'issued_at'];

    /** @var array<string, string> */
    protected $casts = [
        'user_id' => 'integer',
        'server_id' => 'integer',
        'subscription_id' => 'integer',
        'traffic_bytes' => 'integer',
        'duration_hours' => 'integer',
        'on_hold' => 'boolean',
        'issued_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
