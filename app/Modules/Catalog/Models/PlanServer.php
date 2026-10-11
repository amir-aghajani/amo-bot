<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Models\ServerInbound;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * One server a plan is sold on. Either the whole server (`all_inbounds`: every inbound the server marks selectable, read
 * at purchase time) or an explicit set of its inbounds. A purchase on this entry creates one panel client attached to
 * all of those inbounds.
 *
 * @property int $id
 * @property int $plan_id
 * @property int $server_id
 * @property bool $all_inbounds
 * @property int $sort
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Server $server
 * @property-read Collection<int, ServerInbound> $inbounds Pinned inbounds (empty when all_inbounds)
 */
class PlanServer extends Model
{
    protected $table = 'plan_servers';

    protected $fillable = ['plan_id', 'server_id', 'all_inbounds', 'sort'];

    /** @var array<string, string> */
    protected $casts = [
        'all_inbounds' => 'boolean',
        'sort' => 'integer',
    ];

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsToMany<ServerInbound, $this> */
    public function inbounds(): BelongsToMany
    {
        return $this->belongsToMany(ServerInbound::class, 'plan_server_inbounds', 'plan_server_id', 'server_inbound_id');
    }

    /**
     * The inbounds a purchase on this entry gets now: the server's selectable ones for a whole-server entry, else the
     * pinned ones still enabled, in id order. Uses the loaded `inbounds` relation when a list read it.
     *
     * @return Collection<int, ServerInbound>
     */
    public function sellableInbounds(): Collection
    {
        if ($this->all_inbounds) {
            return $this->server->sellableInbounds();
        }
        $pinned = $this->relationLoaded('inbounds') ? $this->inbounds : $this->inbounds()->get();

        return $pinned->filter(fn(ServerInbound $inbound): bool => (int) $inbound->server_id === (int) $this->server_id && $inbound->enabled)->sortBy('id')->values();
    }
}
