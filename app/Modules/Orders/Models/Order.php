<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * What the customer asked for. An order is paid through one or more Payment attempts — one of which pays it, and
 * when is its `paid_at` — and, once paid, fulfilled (subscription provisioned / renewed / wallet credited / an agent's
 * traffic added). In an agent's bot a purchase or a renewal draws its traffic from the agent's
 * (Agency\Services\TrafficPool).
 *
 * @property int $id
 * @property int $user_id
 * @property OrderType $type
 * @property OrderStatus $status
 * @property int|null $plan_id
 * @property int|null $server_id The server the customer chose (purchase orders); a renewal's is its service's
 * @property int|null $subscription_id
 * @property int|null $traffic_bytes The traffic an agent bought (traffic orders)
 * @property string $amount
 * @property string|null $notes Why a delivery failed — in words anyone of the shop may read — or why it was cancelled
 * @property string|null $diagnosis The owner's reading of the panel that failed its delivery (OrderDirectory::notes())
 * @property Carbon|null $fulfilled_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 * @property-read Plan|null $plan
 * @property-read Server|null $server
 * @property-read Subscription|null $subscription
 * @property-read Collection<int, Payment> $payments
 */
class Order extends Model
{
    use BelongsToBot;

    protected $table = 'orders';

    protected $fillable = [
        'user_id',
        'type',
        'status',
        'plan_id',
        'server_id',
        'subscription_id',
        'traffic_bytes',
        'amount',
        'notes',
        'diagnosis',
        'fulfilled_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'type' => OrderType::class,
        'status' => OrderStatus::class,
        'amount' => 'decimal:2',
        'traffic_bytes' => 'integer',
        'fulfilled_at' => 'datetime',
    ];

    /** The statuses of a sale: the money is in and the delivery done or under way. */
    public const SOLD = [OrderStatus::Paid->value, OrderStatus::Processing->value, OrderStatus::Fulfilled->value];

    /**
     * Orders that count as a sale (SOLD).
     *
     * @return Builder<static>
     */
    public static function sold(): Builder
    {
        return static::query()->whereIn('status', self::SOLD);
    }

    /**
     * Paid, and nobody is delivering them: a delivery that never started, or a claim gone quiet — untouched for
     * OrderService::STALE_PROCESSING_MINUTES (OrderService::isStale() of one order).
     *
     * @return Builder<static>
     */
    public static function stale(): Builder
    {
        return static::query()->where(static fn(Builder $query) => self::whereStale($query));
    }

    /**
     * Paid, and not delivered — the queue that waits on support, the orders it can act on: the delivery failed, or nobody
     * is delivering it, while the payment that paid it stands (a refunded one gave the money back: nothing is owed).
     * OrderActions::stuck() of one order — what its retry is offered on.
     *
     * @return Builder<static>
     */
    public static function stuck(): Builder
    {
        return static::query()
            ->where(static function (Builder $query): void {
                $query->where('status', OrderStatus::Failed->value)->orWhere(static fn(Builder $stale) => self::whereStale($stale));
            })
            ->whereHas('payments', static fn(Builder $payment) => $payment->where('status', PaymentStatus::Paid->value));
    }

    /**
     * Orders the customer may still pay: open, and no receipt of theirs waiting for support — that one is support's to
     * decide, and paying it again would pay twice.
     *
     * @return Builder<static>
     */
    public static function payable(): Builder
    {
        return static::query()
            ->where('status', OrderStatus::Pending->value)
            ->whereDoesntHave('payments', static fn(Builder $payment) => $payment->where('status', PaymentStatus::AwaitingReview->value));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * The attempts to pay it, the newest first — each knowing this order as its own (chaperone()): what is done to one of
     * them reads the order at hand, not a copy fetched again.
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderByDesc('id')->chaperone('order');
    }

    /** When it was paid: the paying payment's moment (refunded since or not) — its payments loaded; null while unpaid. */
    public function paidAt(): ?Carbon
    {
        return $this->payments->first(static fn(Payment $payment): bool => $payment->paid_at !== null)?->paid_at;
    }

    /** The payment that paid it, while that payment stands — not refunded since; null for one nobody paid. Its payments loaded. */
    public function paidBy(): ?Payment
    {
        return $this->payments->first(static fn(Payment $payment): bool => $payment->isPaid());
    }

    /**
     * @template TModel of Model
     * @param Builder<TModel> $query
     */
    private static function whereStale(Builder $query): void
    {
        $query->whereIn('status', [OrderStatus::Paid->value, OrderStatus::Processing->value])
            ->where('updated_at', '<=', now()->subMinutes(OrderService::STALE_PROCESSING_MINUTES));
    }
}
