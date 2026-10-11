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
 * One attempt to pay an order with a payment method. A card transfer's receipt is a Telegram file sent in the bot or a
 * picture uploaded from the website (`receipt_*`); `note` is the word on its latest verdict.
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
     * Money that came in and stayed: the shop's takings (the dashboard's revenue, referral commissions). Paid payments not
     * made from the wallet, and refunded ones too — a refund goes to the customer's wallet and stays in the shop — except
     * a top-up's, whose money went back to the customer. A wallet payment spends money counted when it was charged.
     *
     * The non-wallet method ids are passed as a list (a subquery would make the database read every payment of each
     * method), and the order's type is looked up only for refunded rows.
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

    /** Accepted by the review window's timer (AutoApproveReceiptsTask): a paid receipt with no reviewer's name on it. */
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
