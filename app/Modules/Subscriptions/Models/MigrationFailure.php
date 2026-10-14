<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Models;

use App\Modules\Bots\CurrentBot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A service a move of its server's services (ServerMigration) could not carry, and why (Services\ServerMigrations): its
 * client's name then, and the service while it lasts.
 *
 * @property int $id
 * @property int $migration_id
 * @property int|null $subscription_id
 * @property string $remote_name
 * @property string $reason
 * @property Carbon $created_at
 * @property-read Subscription|null $subscription
 */
class MigrationFailure extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'migration_failures';

    protected $fillable = ['migration_id', 'subscription_id', 'remote_name', 'reason'];

    /** @var array<string, string> */
    protected $casts = [
        'migration_id' => 'integer',
        'subscription_id' => 'integer',
    ];

    /** @return BelongsTo<Subscription, $this> The service, in its own shop whatever shop the code works in. */
    public function subscription(): BelongsTo
    {
        $subscription = $this->belongsTo(Subscription::class);
        $subscription->withoutGlobalScope(CurrentBot::SCOPE);

        return $subscription;
    }
}
