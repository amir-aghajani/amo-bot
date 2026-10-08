<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A customer's review of the shop, written on its website — by a guest, or by a customer signed in there — and shown
 * there once support approved it (Reviews\Services\Reviews). Every change of where it stands is one conditional update
 * away from the next.
 *
 * @property int $id
 * @property int $bot_id
 * @property int|null $user_id The customer whose bearer token came with it; null for a guest's — or one whose account is gone
 * @property string $name The name it is signed with
 * @property int $rating 1 to 5
 * @property string $body The writer's words
 * @property string|null $context A line of where they use the service from («ایرانسل · اندروید · Happ»)
 * @property string|null $avatar A key of the website's own avatars, never an address
 * @property ReviewStatus $status
 * @property string|null $reviewer Who of support decided it last
 * @property Carbon|null $decided_at When
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $user
 */
class Review extends Model
{
    use BelongsToBot;

    protected $table = 'reviews';

    protected $fillable = ['user_id', 'name', 'rating', 'body', 'context', 'avatar'];

    /** @var array<string, string> */
    protected $casts = [
        'user_id' => 'integer',
        'rating' => 'integer',
        'status' => ReviewStatus::class,
        'decided_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => ReviewStatus::Pending->value,
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
