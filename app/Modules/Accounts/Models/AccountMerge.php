<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Two of a shop's customers merged into one (Services\AccountMerger): the account that stayed, the merged one's former
 * number — its row is gone — and how it was signed in and called, how much of what it owned moved, and who merged them.
 *
 * @property int $id
 * @property int $user_id The account that stayed
 * @property int $merged_user_id The merged account's number, gone since
 * @property array{telegram_id: int|null, username: string|null, email: string|null, google: bool, first_name: string|null, last_name: string|null, created_at: string} $merged
 * @property array<string, int> $moved How many of each thing it owned moved (AccountMerger::REFERENCES' names)
 * @property string $actor Who merged them: AccountMerger::BY_CUSTOMER, or a panel's principal
 * @property Carbon $created_at
 */
class AccountMerge extends Model
{
    use BelongsToBot;

    public const UPDATED_AT = null;

    protected $table = 'account_merges';

    protected $fillable = ['user_id', 'merged_user_id', 'merged', 'moved', 'actor'];

    /** @var array<string, string> */
    protected $casts = [
        'user_id' => 'integer',
        'merged_user_id' => 'integer',
        'merged' => 'array',
        'moved' => 'array',
    ];
}
