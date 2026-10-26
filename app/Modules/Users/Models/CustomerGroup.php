<?php

declare(strict_types=1);

namespace App\Modules\Users\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * An admin-made group of customers («گروه»: VIP, a campaign…); a customer may be in many. A group marked «همکار»
 * (`agent_pricing`) gives its customers the plans' agent prices.
 *
 * @property int $id
 * @property string $name
 * @property bool $agent_pricing Its customers pay plans' agent prices (User::paysAgentPrices())
 * @property int $sort
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int|null $users_count
 */
class CustomerGroup extends Model
{
    use BelongsToBot;

    protected $table = 'customer_groups';

    protected $fillable = ['name', 'agent_pricing', 'sort'];

    /** @var array<string, string> */
    protected $casts = ['agent_pricing' => 'boolean', 'sort' => 'integer'];

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'customer_group_user');
    }
}
