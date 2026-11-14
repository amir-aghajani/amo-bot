<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use App\Modules\Payments\Enums\DepositStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A bank SMS a phone app forwarded to the shop (Services\Deposits), once per its id at the app, and what came of it.
 *
 * @property int $id
 * @property int $bot_id
 * @property string $source The app's key (Contracts\DepositSource): "forapp"
 * @property string $external_id The app's own id for the SMS: a retry sends it again, and finds this row
 * @property DepositStatus $status
 * @property int|null $amount_rial The sum the SMS said, in Rial as banks write it; null when none was read
 * @property string|null $bank
 * @property string|null $sender
 * @property string|null $body The SMS as it came, cut to BODY_MAX
 * @property string|null $note Why it approved nothing, as support reads it
 * @property int|null $payment_id The card payment it approved
 * @property Carbon|null $received_at When the phone got the SMS
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Payment|null $payment
 */
final class Deposit extends Model
{
    use BelongsToBot;

    /** The most of an SMS kept. */
    public const BODY_MAX = 2000;

    /** How long the shop keeps the SMS it was sent (Orders\Tasks\ExpireOrdersTask forgets older ones). */
    public const KEEP_DAYS = 90;

    protected $fillable = ['source', 'external_id', 'status', 'amount_rial', 'bank', 'sender', 'body', 'note', 'payment_id', 'received_at'];

    /** @var array<string, string> */
    protected $casts = [
        'status' => DepositStatus::class,
        'amount_rial' => 'integer',
        'payment_id' => 'integer',
        'received_at' => 'datetime',
    ];

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** The sum in Toman, as the shop's prices are; null when none was read. */
    public function toman(): ?string
    {
        return $this->amount_rial === null ? null : number_format($this->amount_rial / 10, 2, '.', '');
    }
}
