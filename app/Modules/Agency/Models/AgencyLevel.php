<?php

declare(strict_types=1);

namespace App\Modules\Agency\Models;

use App\Modules\Bots\CurrentBot;
use App\Modules\Users\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A level of the shop's agents («برنزی», «طلایی» …): the price, in Toman, its agents pay for each GB of traffic their
 * bots sell (Agency\Services\TrafficPool).
 *
 * @property int $id
 * @property string $name
 * @property string $price_per_gb
 * @property int $sort
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read int|null $agents_count
 */
class AgencyLevel extends Model
{
    protected $table = 'agency_levels';

    protected $fillable = ['name', 'price_per_gb', 'sort'];

    /** @var array<string, string> */
    protected $casts = [
        'price_per_gb' => 'decimal:2',
        'sort' => 'integer',
    ];

    /** What `$gb` GB of traffic costs an agent on this level. */
    public function priceOf(int $gb): string
    {
        return Money::times($this->price_per_gb, $gb);
    }

    /** @return HasMany<User, $this> The agents on it — customers of the main bot, whichever shop is being worked in. */
    public function agents(): HasMany
    {
        $agents = $this->hasMany(User::class, 'agency_level_id');
        $agents->withoutGlobalScope(CurrentBot::SCOPE);

        return $agents;
    }
}
