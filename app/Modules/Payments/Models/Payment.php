<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Concerns\BelongsToBot;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Drivers\Wallet\WalletGateway;
use App\Modules\Payments\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Carbon;

/**
 * One attempt to pay an order through a payment method. The customer is the order's, the driver the method's.
 * `reference` is the gateway's own id of the payment; a card-to-card receipt is the Telegram file the customer sent the
 * bot, or the picture they uploaded from the website (`receipt_*`); `note` is the word on its latest verdict. The shop's
 * takings are moneyIn().
 *
 * @property int $id
 * @property int $order_id
 * @property int $payment_method_id
 * @property PaymentStatus $status
 * @property string $amount
 * @property string|null $reference
 * @property string|null $receipt_file_id The receipt's Telegram file id — Telegram keeps the picture (Services\Receipts)
 * @property string|null $receipt_path The receipt uploaded from the website: its name in the uploads folder (Services\Receipts)
 * @property string|null $receipt_name Its file name, when the picture was sent as a file
 * @property string|null $receipt_note What the customer wrote under it
 * @property int|null $receipt_message_id The customer's message that carried it: every word about a verdict replies to it
 * @property Carbon|null $receipt_at When the customer sent the receipt
 * @property string|null $note The word on its latest verdict: the reason of a rejection, a cancellation's or a refund's note, the gateway's refusal
 * @property Carbon|null $paid_at
 * @property string|null $reviewer Who decided it last: the panel login, or a bot admin's @username / tg:<id>; null when nobody did by hand (an instant gateway, the review window)
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Order $order
 * @property-read PaymentMethod $method
 */
class Payment extends Model
{
    use BelongsToBot;

    protected $table = 'payments';

    protected $fillable = [
        'order_id',
        'payment_method_id',
        'status',
        'amount',
        'reference',
        'receipt_file_id',
        'receipt_path',
        'receipt_name',
        'receipt_note',
        'receipt_message_id',
        'receipt_at',
        'note',
        'paid_at',
        'reviewer',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'status' => PaymentStatus::class,
        'amount' => 'decimal:2',
        'receipt_message_id' => 'integer',
        'receipt_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    /**
     * Money that came in and stayed in the shop — its takings, the one definition the dashboard's revenue and the
     * referral commissions go by: payments not made from the wallet (a card transfer, a gateway) that were paid, and
     * refunded ones but a wallet top-up's. A refund puts the money in the customer's wallet, where it stays; a top-up's
     * takes the top-up back out of the wallet instead — that money went back to the customer. A purchase from the
     * wallet spends money counted when the wallet was charged.
     *
     * Read off the index of a shop's payments by status and time, which carries their method too: the shop's methods
     * other than the wallet are a short list of ids (a subquery there has the database read every payment of each method,
     * whatever the period), and the order behind a payment is looked up for a refunded one alone.
     *
     * @return Builder<static>
     */
    public static function moneyIn(): Builder
    {
        // The type of the order a payment paid: a subquery a refunded row is checked with on its own (an EXISTS here,
        // the database turns into a list of every order's id first).
        $type = Order::query()->withoutGlobalScope(CurrentBot::SCOPE)->select('type')->whereColumn('orders.id', 'payments.order_id')->toBase();

        return static::query()
            ->whereIn('status', [PaymentStatus::Paid->value, PaymentStatus::Refunded->value])
            ->whereIn('payment_method_id', PaymentMethod::query()->where('driver', '!=', WalletGateway::key())->pluck('id')->all())
            ->where(static fn(Builder $query) => $query
                ->where('status', PaymentStatus::Paid->value)
                ->orWhere(new Expression('(' . $type->toSql() . ')'), '!=', OrderType::WalletTopUp->value));
    }

    public function isPaid(): bool
    {
        return $this->status === PaymentStatus::Paid;
    }

    /** Paid from the customer's wallet: money spent, not money that came in. */
    public function isFromWallet(): bool
    {
        return $this->method->isWallet();
    }

    /**
     * Settled by the timer, not by an admin (see AutoApproveReceiptsTask): a receipt accepted with nobody's name on it —
     * every approval by a person, on the screen or in the report group, writes its reviewer.
     */
    public function wasAutoApproved(): bool
    {
        return $this->isPaid() && $this->receipt_at !== null && $this->reviewer === null;
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<PaymentMethod, $this> */
    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }
}
