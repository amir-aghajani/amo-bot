<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use App\Modules\Catalog\DTO\Package;
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
use App\Modules\Subscriptions\Enums\RenewalMethod;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Users\Models\User;
use App\Support\Persian;
use App\Support\Traffic;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * What a customer buys. It is paid by one of its Payment attempts and then delivered (Services\OrderService): a service
 * made, renewed or given extra traffic, time or devices, the wallet credited, or an agent's traffic added. Its price
 * and the discounts taken are kept on it (Services\Pricing, DTO\Quote).
 *
 * @property int $id
 * @property int $user_id
 * @property OrderType $type
 * @property OrderStatus $status
 * @property int|null $plan_id
 * @property int|null $server_id The server the customer chose (purchase orders); a renewal's is its service's
 * @property int|null $subscription_id
 * @property int|null $traffic_bytes The traffic an order brings: an agent's (traffic orders), a service's extra (extra traffic)
 * @property int|null $days The days an extra time order brings its service, or a built service's term
 * @property int|null $devices The devices an extra devices order adds to its service, or a built service's limit
 * @property string|null $client_name The client name a purchase's buyer asked for (typed, or drawn at random); null for the shop's pattern
 * @property RenewalMethod|null $renewal_method How a renewal adds its package; null for any other order
 * @property int|null $given_hours The hours a renewal added to its service's term, as delivered — a reset's, what it
 *                                 gave beyond what it replaced, less than nothing when it replaced more (its refund
 *                                 takes them back, or gives them back) —, with the hours of earlier orders handed on to
 *                                 it; extra days that started a new term keep theirs here too
 * @property int|null $given_bytes The traffic a renewal added to its service, as delivered — a reset's, beyond what was
 *                                 left, less than nothing when more was left (its refund takes it back, or gives it
 *                                 back) —, with the traffic of earlier orders handed on to it
 * @property bool $gave_plan The renewal gave its service its plan — one without (added by its link) —, which its refund takes back
 * @property bool $gave_term The renewal gave a term to a service that never ended: refunded, it never ends again
 * @property bool $gave_quota The renewal gave a quota to an unlimited service: refunded, it is unlimited again
 * @property int|null $term_was The term, in hours, a renewal onto a package without one replaced (null: it ended none):
 *                              refunded, the service has it again, with its deadline (`expires_was`)
 * @property Carbon|null $expires_was That term's deadline; null while it waited for the first connection
 * @property int|null $quota_was The traffic left a renewal onto an unlimited package replaced (null: it ended no quota):
 *                               refunded, the service has that much left again
 * @property bool $reset_term The renewal started its service's term afresh (nothing of the time left carried, or a
 *                            term ended), or extra days started a new one for a service whose time ran out: an earlier
 *                            order's refund takes none of it, and hands its hours on to this one's
 * @property bool $reset_traffic The renewal counted its service's traffic afresh (nothing of what was left carried): the same
 *                               for an earlier order's traffic
 * @property bool $reset_ip_limit The renewal put its service's device limit back to its package's at once: an earlier order's
 *                                devices went with it, handed on as gone from `ip_limit_was`
 * @property int|null $given_ip_limit The device limit a renewal gave its service, as delivered: set at once, or queued behind
 *                                    the period in use (`ip_limit_queued`); null for one delivered before it was kept
 * @property int|null $ip_limit_was What that replaced — the service's limit, or the limit queued before (null: none) —,
 *                                  which its refund puts back
 * @property bool $ip_limit_queued The renewal's device limit was queued behind the period in use
 * @property Carbon|null $ip_limit_reset_was For a limit set at once: when the service's limit had last gone back to a
 *                                           package's before it (null: never), which its refund puts back with the limit
 * @property string $amount
 * @property OrderPricing $pricing Which of the plan's prices it was made at
 * @property string $discount_amount The Toman all its discounts took off
 * @property list<array<string, mixed>>|null $discounts Its discount lines as kept (discountLines())
 * @property int|null $discount_code_id The code it was made with: one of the code's uses while it is not cancelled
 * @property string|null $notes Why the delivery failed (safe for any reader of the shop) or why it was cancelled
 * @property string|null $diagnosis The owner-only detail of a panel that failed the delivery (OrderDirectory::notes())
 * @property Carbon|null $fulfilled_at
 * @property Carbon|null $taken_back_at When a refund took back what the renewal or extra gave its service — once
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
        'devices',
        'client_name',
        'renewal_method',
        'given_hours',
        'given_bytes',
        'gave_plan',
        'gave_term',
        'gave_quota',
        'term_was',
        'expires_was',
        'quota_was',
        'reset_term',
        'reset_traffic',
        'reset_ip_limit',
        'given_ip_limit',
        'ip_limit_was',
        'ip_limit_queued',
        'ip_limit_reset_was',
        'amount',
        'pricing',
        'discount_amount',
        'discounts',
        'discount_code_id',
        'notes',
        'diagnosis',
        'fulfilled_at',
        'taken_back_at',
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
        'devices' => 'integer',
        'renewal_method' => RenewalMethod::class,
        'given_hours' => 'integer',
        'given_bytes' => 'integer',
        'gave_plan' => 'boolean',
        'gave_term' => 'boolean',
        'gave_quota' => 'boolean',
        'term_was' => 'integer',
        'expires_was' => 'datetime',
        'quota_was' => 'integer',
        'reset_term' => 'boolean',
        'reset_traffic' => 'boolean',
        'reset_ip_limit' => 'boolean',
        'given_ip_limit' => 'integer',
        'ip_limit_was' => 'integer',
        'ip_limit_queued' => 'boolean',
        'ip_limit_reset_was' => 'datetime',
        'fulfilled_at' => 'datetime',
        'taken_back_at' => 'datetime',
    ];

    /** A sale: paid, and delivered or being delivered. */
    public const SOLD = [OrderStatus::Paid->value, OrderStatus::Processing->value, OrderStatus::Fulfilled->value];

    /** Paid and not given back: a sale, or a failed delivery waiting for support's retry. */
    public const KEPT = [...self::SOLD, OrderStatus::Failed->value];

    /**
     * Not finished with: waiting for a payment, being delivered, or failed and waiting for support's retry. A service
     * such an order is about is neither renewed again by the timer nor removed by it.
     */
    public const OPEN = [OrderStatus::Pending->value, OrderStatus::Paid->value, OrderStatus::Processing->value, OrderStatus::Failed->value];

    /** Paid at some point, whatever happened since: KEPT, or refunded. */
    private const EVER_PAID = [...self::KEPT, OrderStatus::Refunded->value];

    /** A built service in words (builtName()): its GB, then its days. */
    private const BUILT = 'سرویس دلخواه · %s گیگ · %s روز';

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
     * fake-referral alert, the wheel's buyers-only rule), since a free plan must not unlock them — and what makes a
     * customer no newcomer for free test accounts (their newcomers-only rule, the tests list's «خرید کرد»).
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
     * What a purchase or a renewal gives its service: its plan's package, or — one the customer built, without a plan —
     * the gigabytes, days and devices it carries itself. Null for an order that gives no package.
     */
    public function package(): ?Package
    {
        return $this->isBuilt() ? new Package((int) $this->days * Package::HOURS_A_DAY, (int) $this->traffic_bytes, (int) $this->devices) : $this->plan?->package();
    }

    /** A service the customer built («سرویس دلخواه», Catalog\Services\CustomServices): no plan, its own GB and days. */
    public function isBuilt(): bool
    {
        return $this->plan_id === null && in_array($this->type, [OrderType::Purchase, OrderType::Renewal], true) && ($this->traffic_bytes !== null || $this->days !== null);
    }

    /**
     * A renewal or an extra whose service is no longer its customer's: handed to another since it was ordered (support's
     * transfer cancels the giver's unpaid orders, and leaves those paid or with a receipt in review). It is neither paid
     * nor delivered (Services\OrderService::carryOut()).
     */
    public function serviceChangedHands(): bool
    {
        return $this->type->forService() && $this->subscription !== null && (int) $this->subscription->user_id !== (int) $this->user_id;
    }

    /**
     * What a purchase or a renewal buys, in words — the one wording wherever an order's plan is named (the report group,
     * the panels' lists): its plan's name, or one the customer built as «سرویس دلخواه · ۵۰ گیگ · ۳۰ روز». An extra's is
     * its service's plan's name (the plan it carries, OrderService::openExtra()) — what the panels' «پلن» column shows —,
     * null for a service without one. Null for any other order.
     */
    public function packageName(): ?string
    {
        if ($this->plan !== null) {
            return $this->plan->name;
        }

        return $this->isBuilt() ? self::builtName(intdiv((int) $this->traffic_bytes, Traffic::GIGABYTE), (int) $this->days) : null;
    }

    /** A package the customer built, in words: «سرویس دلخواه · ۵۰ گیگ · ۳۰ روز» — an order's, or a checkout's before one. */
    public static function builtName(int $gb, int $days): string
    {
        return sprintf(self::BUILT, Persian::number($gb), Persian::number($days));
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
