<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use App\Modules\Catalog\DTO\Package;
use App\Support\Money;
use App\Support\Traffic;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A sellable package — term, traffic, price — offered on one or more servers (PlanServer); the customer picks the server
 * when buying.
 *
 * @property int $id
 * @property int|null $category_id
 * @property string $name
 * @property string|null $description
 * @property string|null $checkout_note What the bot's checkout of it says under it («یادداشت پیش‌فاکتور»): its purchase's
 *                                      and its renewal's; null — none
 * @property string $price
 * @property string|null $first_purchase_price What a customer pays for it as their first purchase in the shop («قیمت خرید
 *                                             اول»), below the price; null — none (firstPurchasePrice())
 * @property string|null $agent_price What an agent, or a customer of a group with agent prices, pays for it («قیمت
 *                                    نماینده»), below the price; null — none (agentPrice())
 * @property int $duration_hours the term in hours, counted from the first connection — an hourly plan's, or a daily
 *                               one's days × 24; 0 = no time limit
 * @property float $traffic_gb 0 = unlimited
 * @property int $ip_limit 0 = unlimited
 * @property array<string, mixed>|null $extras Its own prices of the extra traffic, time and devices its services buy
 *                                             (Subscriptions\Services\ExtrasSettings::planPrices()); null — the shop's
 * @property string|null $button_style Its button's colour in the bot's plan list, one of Telegram's
 *                                     (KeyboardLayout::STYLES); null — the client's own
 * @property int $button_row How many buttons share its button's row there: 1 — a row of its own, 2 or 3
 * @property bool $is_active
 * @property int $sort
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, PlanServer> $servers
 * @property-read PlanCategory|null $category
 */
class Plan extends Model
{
    use BelongsToBot;

    protected $table = 'plans';

    protected $fillable = [
        'category_id',
        'name',
        'description',
        'checkout_note',
        'price',
        'first_purchase_price',
        'agent_price',
        'duration_hours',
        'traffic_gb',
        'ip_limit',
        'extras',
        'button_style',
        'button_row',
        'is_active',
        'sort',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'category_id' => 'integer',
        'price' => 'decimal:2',
        'first_purchase_price' => 'decimal:2',
        'agent_price' => 'decimal:2',
        'duration_hours' => 'integer',
        'traffic_gb' => 'float',
        'ip_limit' => 'integer',
        'extras' => 'array',
        'button_row' => 'integer',
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    /** @return BelongsTo<PlanCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(PlanCategory::class, 'category_id');
    }

    /** Its first-purchase price («قیمت خرید اول») while below its price; null otherwise (Orders\Services\Pricing). */
    public function firstPurchasePrice(): ?string
    {
        return self::below($this->first_purchase_price, $this->price);
    }

    /** Its agent price («قیمت نماینده») while below its price; null otherwise. */
    public function agentPrice(): ?string
    {
        return self::below($this->agent_price, $this->price);
    }

    /** `$amount` while it is below `$price` (a discount); null for none. */
    private static function below(?string $amount, string $price): ?string
    {
        return $amount !== null && Money::compare($amount, $price) < 0 ? Money::normalize($amount) : null;
    }

    /** The quota as the panel takes it: binary gigabytes, 0 = unlimited. */
    public function trafficBytes(): int
    {
        return Traffic::bytesOfGb($this->traffic_gb);
    }

    /** What the plan gives a service: its term, traffic and devices. */
    public function package(): Package
    {
        return new Package($this->duration_hours, $this->trafficBytes(), $this->ip_limit);
    }

    /**
     * Plans customers can currently buy, in display order.
     *
     * @return Builder<static>
     */
    public static function active(): Builder
    {
        return static::query()->where('is_active', true)->oldest('sort')->oldest('id');
    }

    /**
     * Where the plan is sold, in the order the customer sees the servers.
     *
     * @return HasMany<PlanServer, $this>
     */
    public function servers(): HasMany
    {
        return $this->hasMany(PlanServer::class)->orderBy('sort')->orderBy('id');
    }
}
