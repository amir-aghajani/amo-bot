<?php

declare(strict_types=1);

namespace App\Modules\Users\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use App\Modules\Users\Enums\WalletTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One line of a customer's wallet, append-only (Services\WalletService): what moved and why, and the balance from
 * then on — the last line's is the wallet's balance (User::balance()).
 *
 * @property int $id
 * @property int $user_id
 * @property WalletTransactionType $type
 * @property string $amount
 * @property string $balance_after
 * @property string|null $description
 * @property string|null $reviewer Who wrote it by hand (Auth\Services\Reviewers); null for the lines the shop writes itself
 * @property Carbon $created_at
 */
class WalletTransaction extends Model
{
    use BelongsToBot;

    public const UPDATED_AT = null;

    protected $table = 'wallet_transactions';

    protected $fillable = [
        'user_id',
        'type',
        'amount',
        'balance_after',
        'description',
        'reviewer',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'type' => WalletTransactionType::class,
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];
}
