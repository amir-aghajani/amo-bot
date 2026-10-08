<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Modules\Accounts\Enums\SignInMethod;
use App\Modules\Bots\Models\Concerns\BelongsToBot;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A customer signed in on the shop's website, on one device — the bearer token it was handed is known only by its
 * sha256. See Services\CustomerSessions.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property string|null $device What the browser said it is, in words: «Chrome در Windows»
 * @property string|null $ip The address it signed in from
 * @property SignInMethod|null $method How it was signed in — or a stronger way proven on it since; null: opened before the shop kept it (a password alone)
 * @property Carbon|null $last_used_at
 * @property Carbon|null $authenticated_at When a way into the account was last proven on it: the sign-in, or one asked again since
 * @property Carbon $expires_at The end whatever its use: 180 days after the sign-in
 * @property Carbon $created_at
 * @property-read User $user
 */
class CustomerSession extends Model
{
    use BelongsToBot;

    public const UPDATED_AT = null;

    protected $table = 'customer_sessions';

    protected $fillable = ['user_id', 'token_hash', 'device', 'ip', 'method', 'last_used_at', 'authenticated_at', 'expires_at'];

    protected $hidden = ['token_hash'];

    /** @var array<string, string> */
    protected $casts = [
        'user_id' => 'integer',
        'method' => SignInMethod::class,
        'last_used_at' => 'datetime',
        'authenticated_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
