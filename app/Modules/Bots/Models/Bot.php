<?php

declare(strict_types=1);

namespace App\Modules\Bots\Models;

use App\Core\Database\Casts\Encrypted;
use App\Modules\Agency\Models\TrafficTransaction;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Enums\BotStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A Telegram bot with a shop of its own. #1 is the main bot — the shop's own, its token in config.php (TELEGRAM_BOT_TOKEN) —
 * and every other row an agent's («نماینده»): the agent hands the shop their bot's token from the main bot, and this
 * installation runs it beside the main one while they have an agency (status()). Each bot's customers, plans,
 * categories, payment methods, orders, texts, keyboards and settings are its own (the rows carry `bot_id`, see
 * Concerns\BelongsToBot); the servers are the shop's, and what an agent's bot sells is drawn from the agent's prepaid
 * traffic (trafficBalance(), Agency\Services\TrafficPool).
 *
 * @property int $id
 * @property int|null $user_id The agent who owns it — a customer of the main bot; null for the main bot
 * @property string|null $token Encrypted at rest; null for the main bot (its token is config.php's) and before one is handed over
 * @property int|null $telegram_id The bot's own Telegram user id (the number before the token's ":")
 * @property string|null $username Its @username, without the "@"
 * @property string|null $title The name Telegram shows for it
 * @property string|null $problem What keeps it from running (Telegram refused its token…), for the agent and the owner
 * @property string|null $webhook_secret The secret of its webhook URL and header, made when one is set; encrypted at rest — a copy of the database forges no update
 * @property int $panel_epoch Raised whenever the agent's panel sessions must end
 * @property string|null $login_code The sha256 of the one-time code of the agent's last panel login link
 * @property Carbon|null $login_code_expires_at
 * @property Carbon|null $connected_at When its token was handed over
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $agent
 * @property-read int|null $customers_count
 * @property-read int|null $sold_count
 * @property-read int|null $active_count
 */
class Bot extends Model
{
    /** The shop's own bot. */
    public const MAIN = 1;

    /** What trafficBalanceColumn() names the balance it reads with the row. */
    private const TRAFFIC = 'traffic_left';

    protected $table = 'bots';

    protected $fillable = [
        'user_id',
        'token',
        'telegram_id',
        'username',
        'title',
        'problem',
        'webhook_secret',
        'panel_epoch',
        'login_code',
        'login_code_expires_at',
        'connected_at',
    ];

    protected $hidden = ['token', 'webhook_secret', 'login_code'];

    /** @var array<string, string> */
    protected $casts = [
        'user_id' => 'integer',
        'token' => Encrypted::class,
        'webhook_secret' => Encrypted::class,
        'telegram_id' => 'integer',
        'panel_epoch' => 'integer',
        'login_code_expires_at' => 'datetime',
        'connected_at' => 'datetime',
    ];

    public function isMain(): bool
    {
        return $this->id === self::MAIN;
    }

    /** Switched on: the main bot always, an agent's while its agent has an agency — revoking it switches the bot off. */
    public function status(): BotStatus
    {
        return $this->isMain() || ($this->agent?->isAgent() ?? false) ? BotStatus::Active : BotStatus::Disabled;
    }

    /** Whether it runs: switched on, and — an agent's — with a token to run on. */
    public function isServing(): bool
    {
        return $this->status() === BotStatus::Active && ($this->isMain() || ($this->token ?? '') !== '');
    }

    /**
     * What the agent's bot may still sell, in bytes: the last line of its traffic ledger (Agency\Services\TrafficPool
     * writes it) — 0 before the first. The value read with the row when a list asked for it (trafficBalanceColumn()),
     * otherwise read now: a sale in another process may have drawn on it a moment ago.
     */
    public function trafficBalance(): int
    {
        $balance = array_key_exists(self::TRAFFIC, $this->attributes)
            ? $this->attributes[self::TRAFFIC]
            : self::lastLine()->where('bot_id', $this->id)->value('balance_after');

        return is_numeric($balance) ? (int) $balance : 0;
    }

    /**
     * The column that reads each bot's traffic balance (trafficBalance()) with its row, for a list's `addSelect()`: one
     * subquery on the ledger's (bot_id, id) index, not a query a row.
     *
     * @return array<string, Builder<TrafficTransaction>>
     */
    public static function trafficBalanceColumn(): array
    {
        return [self::TRAFFIC => self::lastLine()->whereColumn('traffic_transactions.bot_id', 'bots.id')];
    }

    /** @return Builder<TrafficTransaction> The newest line of a traffic ledger, for its balance. */
    private static function lastLine(): Builder
    {
        return TrafficTransaction::query()->select('balance_after')->latest('id')->limit(1);
    }

    /**
     * What the bot's shop has done — its customers, the services it sold (purchases delivered) and how many of those
     * run now: the counts read with the row when a list asked for them (statCounts()), otherwise now.
     *
     * @return array{customers: int, sold: int, active: int}
     */
    public function stats(): array
    {
        if (!array_key_exists('customers_count', $this->attributes)) {
            $this->loadCount(self::statCounts());
        }

        return ['customers' => (int) $this->customers_count, 'sold' => (int) $this->sold_count, 'active' => (int) $this->active_count];
    }

    /**
     * The counts stats() reads, for a list's `withCount()`: one subquery each, not a few queries a row.
     *
     * @return array<int|string, string|\Closure(Builder<Model>): mixed>
     */
    public static function statCounts(): array
    {
        return [
            'customers',
            'orders as sold_count' => self::sales(...),
            'subscriptions as active_count' => static fn(Builder $services) => $services->where('status', SubscriptionStatus::Active->value),
        ];
    }

    /**
     * The column that reads the services its shop sold (stats()) with the bot's row, for a `select()` that wants that
     * count alone — the agents list's order.
     *
     * @return array<string, Builder<Order>>
     */
    public static function soldColumn(): array
    {
        return ['sold_count' => self::sales(Order::query()->withoutGlobalScope(CurrentBot::SCOPE)->selectRaw('count(*)')->whereColumn('orders.bot_id', 'bots.id'))];
    }

    /**
     * What its shop sold, of its orders: the purchases delivered.
     *
     * @template TOrder of Model
     * @param Builder<TOrder> $orders
     * @return Builder<TOrder>
     */
    private static function sales(Builder $orders): Builder
    {
        return $orders->where('type', OrderType::Purchase->value)->where('status', OrderStatus::Fulfilled->value);
    }

    /** @return HasMany<User, $this> The customers of its shop, whichever shop is being worked in. */
    public function customers(): HasMany
    {
        return self::acrossShops($this->hasMany(User::class));
    }

    /** @return HasMany<Order, $this> The orders of its shop, whichever shop is being worked in. */
    public function orders(): HasMany
    {
        return self::acrossShops($this->hasMany(Order::class));
    }

    /** @return HasMany<Subscription, $this> The services its shop sold, whichever shop is being worked in. */
    public function subscriptions(): HasMany
    {
        return self::acrossShops($this->hasMany(Subscription::class));
    }

    /**
     * A relation to the rows of this bot's shop, which the current shop's scope would hide when another shop is being
     * worked in (the owner's agents page lists every agent's bot from the main shop).
     *
     * @template TRelated of Model
     * @param HasMany<TRelated, $this> $relation
     * @return HasMany<TRelated, $this>
     */
    private static function acrossShops(HasMany $relation): HasMany
    {
        $relation->withoutGlobalScope(CurrentBot::SCOPE);

        return $relation;
    }

    /** @return Builder<static> The agents' bots, oldest first. */
    public static function agents(): Builder
    {
        return static::query()->where('id', '!=', self::MAIN)->orderBy('id');
    }

    /** @return Builder<static> The agents' bots switched on — their agent has an agency —, oldest first. */
    public static function activeAgents(): Builder
    {
        return static::agents()->whereHas('agent', static fn(Builder $agent) => $agent->whereNotNull('agency_level_id'));
    }

    /** @return BelongsTo<User, $this> The agent — a customer of the main bot, whichever shop is being worked in. */
    public function agent(): BelongsTo
    {
        $agent = $this->belongsTo(User::class, 'user_id');
        $agent->withoutGlobalScope(CurrentBot::SCOPE);

        return $agent;
    }
}
