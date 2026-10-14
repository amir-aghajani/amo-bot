<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Models;

use App\Modules\Bots\CurrentBot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A client a move left on a panel when its service moved on (Services\ProvisioningService::move()) — a server's bulk
 * move (Services\ServerMigrations) or a single one: the old one a rescue («نجات») or a move leaving the previous server
 * out never asked about, one a panel did not say it deleted or made, or a new one it would not take back. It would work
 * once that panel answers, a second service nobody pays for, so it is removed then — by the scheduler, or the owner's
 * «حذف همین حالا» — and stamped `removed_at`. `last_error` is why the last try failed. It outlives its job (a server
 * deleted takes its jobs): the panel still has the client.
 *
 * @property int $id
 * @property int|null $migration_id the job (ServerMigration) that left it, while it lasts; null for a single move
 * @property int $server_id
 * @property int|null $subscription_id the service it was, while it lasts
 * @property string $remote_name the client's name on that panel
 * @property string|null $last_error
 * @property Carbon $created_at
 * @property Carbon|null $removed_at
 * @property-read Subscription|null $subscription
 */
class MigrationLeftover extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'migration_leftovers';

    protected $fillable = ['migration_id', 'server_id', 'subscription_id', 'remote_name'];

    /** @var array<string, string> */
    protected $casts = [
        'migration_id' => 'integer',
        'server_id' => 'integer',
        'subscription_id' => 'integer',
        'removed_at' => 'datetime',
    ];

    /** @return Builder<static> The clients still on their panel. */
    public static function pending(): Builder
    {
        return static::query()->whereNull('removed_at');
    }

    /** @return BelongsTo<Subscription, $this> The service it was, in its own shop whatever shop the code works in. */
    public function subscription(): BelongsTo
    {
        $subscription = $this->belongsTo(Subscription::class);
        $subscription->withoutGlobalScope(CurrentBot::SCOPE);

        return $subscription;
    }
}
