<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Discounts\DTO\DiscountLine;
use App\Modules\Discounts\Enums\DiscountSource;
use App\Modules\Orders\Enums\OrderPricing;
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
 * What a customer buys. It is paid by one of its Payment attempts and then delivered (Services\OrderService): a service
 * made, renewed or given extra traffic or time, the wallet credited, or an agent's traffic added. Its price and the
 * discounts taken are kept on it (Services\Pricing, DTO\Quote).
 *
 * @property int $id
 * @property int $user_id
 * @property OrderType $type
 * @property OrderStatus $status
 * @property int|null $plan_id
 * @property int|null $server_id The server the customer chose (purchase orders); a renewal's is its service's
 * @property int|null $subscription_id
 * @property int|null $traffic_bytes The traffic an order brings: an agent's (traffic orders), a service's extra (extra traffic)
 * @property int|null $days The days an extra time order brings its service
 * @property string $amount
 * @property OrderPricing $pricing Which of the plan's prices it was made at
 * @property string $discount_amount The Toman all its discounts took off
 * @property list<array<string, mixed>>|null $discounts Its discount lines as kept (discountLines())
 * @property int|null $discount_code_id The code it was made with: one of the code's uses while it is not cancelled
 * @property string|null $notes Why the delivery failed (safe for any reader of the shop) or why it was cancelled
 * @property string|null $diagnosis The owner-only detail of a panel that failed the delivery (OrderDirectory::notes())
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

    /** @var array<string, mixed> A new order is at its own price, nothing taken off, unless it says otherwise. */
    protected $attributes = ['pricing' => 'regular', 'discount_amount' => '0.00'];

    protected $fillable = [
        'user_id',
        'type',
        'status',
        'plan_id',
        'server_id',
        'subscription_id',
        'traffic_bytes',
        'days',
        'amount',
        'pricing',
        'discount_amount',
        'discounts',
        'discount_code_id',
        'notes',
        'diagnosis',
        'fulfilled_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'type' => OrderType::class,
        'status' => OrderStatus::class,
        'amount' => 'decimal:2',
        'pricing' => OrderPricing::class,
        'discount_amount' => 'decimal:2',
        'discounts' => 'array',
        'discount_code_id' => 'integer',
        'traffic_bytes' => 'integer',
        'days' => 'integer',
        'fulfilled_at' => 'datetime',
    ];

    /** A sale: paid, and delivered or being delivered. */
    public const SOLD = [OrderStatus::Paid->value, OrderStatus::Processing->value, OrderStatus::Fulfilled->value];

    /** Paid and not given back: a sale, or a failed delivery waiting for support's retry. */
    public const KEPT = [...self::SOLD, OrderStatus::Failed->value];

    /** Paid at some point, whatever happened since: KEPT, or refunded. */
    private const EVER_PAID = [...self::KEPT, OrderStatus::Refunded->value];

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
     * The customer's purchases that were ever paid: "has bought a service in the shop" for first-purchase prices and
     * codes — a free plan counts, a service given free does not. Rewards read paidPurchases() instead.
     *
     * @return Builder<static>
     */
    public static function boughtBy(int $userId): Builder
    {
        return static::bought()->where('user_id', $userId);
    }

    /**
     * Every customer's purchases that boughtBy() counts, for questions about many customers at once.
     *
     * @return Builder<static>
     */
    public static function bought(): Builder
    {
        return static::query()
            ->where('type', OrderType::Purchase->value)
            ->whereIn('status', self::EVER_PAID)
            ->where('pricing', '!=', OrderPricing::Gift->value);
    }

    /**
     * Purchases with a price that were ever paid: what money-like rewards count as "bought" (referral gifts, the
     * fake-referral alert, the wheel's buyers-only rule), since a free trial must not unlock them.
     *
     * @return Builder<static>
     */
    public static function paidPurchases(): Builder
    {
        return static::bought()->where('amount', '>', 0);
    }

    /**
     * What customers spent in the shop: orders bought for themselves (OrderType::FOR_THEMSELVES) and paid without being
     * given back (KEPT). Loyalty tiers and the nightly draw count these.
     *
     * @return Builder<static>
     */
    public static function spending(): Builder
    {
        return static::query()->whereIn('status', self::KEPT)->whereIn('type', OrderType::FOR_THEMSELVES);
    }

    /**
     * Paid, and nobody is delivering them: untouched for OrderService::STALE_PROCESSING_MINUTES while paid or processing
     * (OrderService::isStale() is the same rule for one order).
     *
     * @return Builder<static>
     */
    public static function stale(): Builder
    {
        return static::query()->where(static fn(Builder $query) => self::whereStale($query));
    }

    /**
     * Paid but not delivered — support's queue: the delivery failed or went stale while the paying payment still stands
     * (a refunded one owes nothing). OrderActions::stuck() is the same rule for one order.
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
     * Orders the customer may still pay: pending with no receipt waiting for support (paying again would pay twice).
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
     * The attempts to pay it, newest first, each given this very order (chaperone()) rather than a fresh copy.
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderByDesc('id')->chaperone('order');
    }

    /**
     * The discounts it took: the automatic one, then the code's.
     *
     * @return list<DiscountLine>
     */
    public function discountLines(): array
    {
        return DiscountLine::listOf($this->discounts);
    }

    /** The line of the code it was made with; null for none. */
    public function codeLine(): ?DiscountLine
    {
        foreach ($this->discountLines() as $line) {
            if ($line->source === DiscountSource::Code) {
                return $line;
            }
        }

        return null;
    }

    /** When it was paid (refunded since or not); null while unpaid. Its payments must be loaded. */
    public function paidAt(): ?Carbon
    {
        return $this->payments->first(static fn(Payment $payment): bool => $payment->paid_at !== null)?->paid_at;
    }

    /** The payment that paid it, unless refunded since; null otherwise. Its payments must be loaded. */
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
