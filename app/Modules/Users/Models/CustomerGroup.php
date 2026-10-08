<?php

declare(strict_types=1);

namespace App\Modules\Users\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * One of the admin's own groups of customers («گروه» — VIP, a reseller's people, a campaign's): a name, in the admin's
 * order; a customer is in any number of them. See Services\CustomerGroups.
 *
 * @property int $id
 * @property string $name
 * @property int $sort
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read int|null $users_count
 */
class CustomerGroup extends Model
{
    use BelongsToBot;

    protected $table = 'customer_groups';

    protected $fillable = ['name', 'sort'];

    /** @var array<string, string> */
    protected $casts = ['sort' => 'integer'];

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'customer_group_user');
    }
}
