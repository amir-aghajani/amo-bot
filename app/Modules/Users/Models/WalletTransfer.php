<?php

declare(strict_types=1);

namespace App\Modules\Users\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * «انتقال موجودی»: money one customer sent from their wallet to another of the shop (Services\WalletTransfers) — the
 * two ledger lines it wrote, what the sender's daily cap sums, and the website request's Idempotency-Key it came with.
 *
 * @property int $id
 * @property int $sender_id
 * @property int $recipient_id
 * @property string $amount
 * @property int $debit_id The sender's line
 * @property int $credit_id The recipient's line
 * @property string|null $request_key The website's Idempotency-Key; null from the bot
 * @property Carbon|null $created_at
 * @property-read User $sender
 * @property-read User $recipient
 */
class WalletTransfer extends Model
{
    use BelongsToBot;

    public const UPDATED_AT = null;

    protected $table = 'wallet_transfers';

    /** @var array<string, string> */
    protected $casts = [
        'sender_id' => 'integer',
        'recipient_id' => 'integer',
        'amount' => 'decimal:2',
        'debit_id' => 'integer',
        'credit_id' => 'integer',
    ];

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }
}
