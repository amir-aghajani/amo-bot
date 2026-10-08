<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Database\Casts\Encrypted;
use App\Modules\Accounts\Enums\ChallengePurpose;
use App\Modules\Bots\Models\Concerns\BelongsToBot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A short-lived secret of a sign-in flow, kept by Services\AuthChallenges: the sha256 of what the browser holds, what
 * it was issued for and until when, and what the flow needs back when it is spent (encrypted). One use.
 *
 * @property int $id
 * @property ChallengePurpose $purpose
 * @property string|null $subject What it is about, where a flow looks it up by more than its secret
 * @property int|null $user_id The customer it was issued to, where it was
 * @property string $secret_hash
 * @property string|null $payload What the flow keeps with it: JSON, encrypted at rest
 * @property int $attempts
 * @property Carbon $expires_at
 * @property Carbon $created_at
 */
class AuthChallenge extends Model
{
    use BelongsToBot;

    public const UPDATED_AT = null;

    protected $table = 'auth_challenges';

    protected $fillable = ['purpose', 'subject', 'user_id', 'secret_hash', 'payload', 'expires_at'];

    protected $hidden = ['secret_hash', 'payload'];

    /** @var array<string, string> */
    protected $casts = [
        'purpose' => ChallengePurpose::class,
        'user_id' => 'integer',
        'payload' => Encrypted::class,
        'attempts' => 'integer',
        'expires_at' => 'datetime',
    ];
}
