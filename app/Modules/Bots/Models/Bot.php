<?php

declare(strict_types=1);

namespace App\Modules\Bots\Models;

use App\Core\Database\Casts\Encrypted;
use App\Modules\Agency\Exceptions\TrafficShortException;
use App\Modules\Agency\Models\TrafficTransaction;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Enums\BotStatus;
use App\Modules\Bots\Enums\SellingSuspension;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Telegram\Messages;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * A Telegram bot with a shop of its own. #1 is the main bot (its token and @username live in config.php); every other
 * row is an agent's («نماینده»): the agent hands over their bot's token in the main bot, and the installation runs it
 * while they have an agency (status()). Each bot's customers, plans, payment methods, orders, texts, keyboards and
 * settings are its own (rows carry `bot_id`: Concerns\BelongsToBot); the servers are shared, and what an agent's bot
 * sells is drawn from the agent's prepaid traffic (trafficBalance(), Agency\Services\TrafficPool).
 *
 * The owner may keep servers from an agent's shop (hiddenServerIds()) and suspend its selling (sells(),
 * Agency\Services\AgencyStanding: an expiry, a monthly minimum, or by hand) — never through status(), which would stop
 * the bot, its panel and its tasks too.
 *
 * @property int $id
 * @property int|null $user_id The agent who owns it — a customer of the main bot; null for the main bot
 * @property string|null $token Encrypted at rest; null for the main bot (its token is config.php's) and before one is handed over
 * @property int|null $telegram_id The bot's own Telegram user id (the number before the token's ":")
 * @property string|null $username Its @username, without the "@"
 * @property string|null $title The name Telegram shows for it
 * @property string|null $problem What keeps it from running (Telegram refused its token…), for the agent and the owner
 * @property string|null $webhook_secret The secret of its webhook URL and header, made when one is set; encrypted, so a copy of the database forges no update
 * @property int $panel_epoch Raised whenever the agent's panel sessions must end
 * @property string|null $login_code The sha256 of the one-time code of the agent's last panel login link
 * @property Carbon|null $login_code_expires_at
 * @property Carbon|null $connected_at When its token was handed over
 * @property Carbon|null $agency_expires_at When the agent's agency ends — its selling then; null: never
 * @property Carbon|null $expiry_warned_at When the agent was warned of that end, once per date
 * @property string|null $monthly_minimum The Toman of traffic the agent buys each Jalali month at least; null: none
 * @property string|null $minimum_checked_month The last Jalali month («1405-07») judged against it, or the one it was set in; behind last month while a passed end holds the shop (its judgment waits for the end to lift)
 * @property string|null $minimum_warned_month The Jalali month the agent was warned of falling short in
 * @property Carbon|null $selling_suspended_at Since when its shop sells nothing new; null while it sells
 * @property SellingSuspension|null $suspension_reason Why
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $agent
 * @property-read int|null $customers_count
 * @property-read int|null $sold_count
 * @property-read int|null $active_count
 */
class Bot extends Model
{
    /** The main bot's id. */
    public const MAIN = 1;

    /** The alias trafficBalanceColumn() reads the balance under. */
    private const TRAFFIC = 'traffic_left';

    /** The aliases hiddenCountColumns() reads under. */
    private const HIDDEN_SERVERS = 'hidden_servers_count';
    private const HIDDEN_PLANS = 'hidden_plans_count';

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
        'agency_expires_at' => 'datetime',
        'expiry_warned_at' => 'datetime',
        'monthly_minimum' => 'decimal:2',
        'selling_suspended_at' => 'datetime',
        'suspension_reason' => SellingSuspension::class,
    ];

    /** @var list<int>|null hiddenServerIds(), read once for this row */
    private ?array $hiddenServers = null;

    public function isMain(): bool
    {
        return $this->id === self::MAIN;
    }

    /**
     * Whether its shop sells anything new now — always, the main bot's; an agent's while its selling is not suspended
     * (sellingSuspension()). A suspended shop's bot, its panel and the services it sold run on, and paid orders are
     * delivered.
     */
    public function sells(): bool
    {
        return $this->sellingSuspension() === null;
    }

    /**
     * sells() as the database has it now, not as this copy was read: a bot's polling process keeps its shop's row for
     * seconds, and a suspension holds from the moment it is written (the owner's, the hourly task's). An agent's row is
     * read again without a lock — a free test locks the shop's row before its customer's, so a caller holding a
     * customer's lock must not take it.
     */
    public function sellsNow(): bool
    {
        return $this->isMain() || (self::query()->find($this->id) ?? $this)->sells();
    }

    /**
     * Why an agent's selling is suspended (`selling_suspended_at`, Agency\Services\AgencyStanding) — or its agency's date
     * passed, which counts at once, before the hourly task writes it down; null while it sells.
     */
    public function sellingSuspension(): ?SellingSuspension
    {
        if ($this->isMain()) {
            return null;
        }
        if ($this->selling_suspended_at !== null) {
            return $this->suspension_reason ?? SellingSuspension::Manual;
        }

        return $this->agency_expires_at?->lessThanOrEqualTo(now()) === true ? SellingSuspension::Expired : null;
    }

    /**
     * The servers the owner keeps from an agent's shop (`bot_hidden_servers`): its plans are not sold there, nor its tests
     * given, nor its services moved there by its staff — the services it has there run on, and the owner's own moves of a
     * server's services carry them there too. None for the main bot; read once per row.
     *
     * @return list<int>
     */
    public function hiddenServerIds(): array
    {
        if ($this->isMain()) {
            return [];
        }

        return $this->hiddenServers ??= array_values(array_map(intval(...), $this->getConnection()->table('bot_hidden_servers')->where('bot_id', $this->id)->orderBy('server_id')->pluck('server_id')->all()));
    }

    /** Whether the owner keeps the server from its shop (hiddenServerIds()). */
    public function hidesServer(int $serverId): bool
    {
        return in_array($serverId, $this->hiddenServerIds(), true);
    }

    /**
     * Keeps the servers from its shop, in the caller's transaction: these and no other.
     *
     * @param list<int> $serverIds
     */
    public function hideServers(array $serverIds): void
    {
        $serverIds = array_values(array_unique($serverIds));
        sort($serverIds);
        $table = $this->getConnection()->table('bot_hidden_servers');
        (clone $table)->where('bot_id', $this->id)->whereNotIn('server_id', $serverIds)->delete();
        $kept = (clone $table)->where('bot_id', $this->id)->pluck('server_id')->map(intval(...))->all();
        $new = array_values(array_diff($serverIds, $kept));
        if ($new !== []) {
            // Two saves at once may both find a server new: the one that comes second keeps the first's row.
            $table->insertOrIgnore(array_map(fn(int $server): array => ['bot_id' => $this->id, 'server_id' => $server], $new));
        }
        $this->hiddenServers = $serverIds;
    }

    /**
     * What the owner keeps from an agent's shop, counted: servers (hiddenServerIds()) and its plans kept from sale
     * (`plans.hidden_by_owner`) — read with the row when a list asked (hiddenCountColumns()), otherwise now.
     *
     * @return array{servers: int, plans: int}
     */
    public function hiddenCounts(): array
    {
        if (array_key_exists(self::HIDDEN_SERVERS, $this->attributes)) {
            return ['servers' => (int) $this->attributes[self::HIDDEN_SERVERS], 'plans' => (int) $this->attributes[self::HIDDEN_PLANS]];
        }

        return ['servers' => count($this->hiddenServerIds()), 'plans' => $this->getConnection()->table('plans')->where('bot_id', $this->id)->where('hidden_by_owner', true)->count()];
    }

    /**
     * A list's `addSelect()` that counts hiddenCounts() with each bot's row: a subquery each instead of queries per row.
     *
     * @return array<string, QueryBuilder>
     */
    public static function hiddenCountColumns(): array
    {
        $db = self::query()->getQuery()->getConnection();

        return [
            self::HIDDEN_SERVERS => $db->table('bot_hidden_servers')->selectRaw('count(*)')->whereColumn('bot_hidden_servers.bot_id', 'bots.id'),
            self::HIDDEN_PLANS => $db->table('plans')->selectRaw('count(*)')->whereColumn('plans.bot_id', 'bots.id')->where('plans.hidden_by_owner', true),
        ];
    }

    /** Switched on: the main bot always, an agent's while its agent has an agency (nothing stored). */
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
     * What the agent's bot may still sell, in bytes: the last line of its traffic ledger (0 before the first) — the value
     * a list read with the row (trafficBalanceColumn()), otherwise read now, since another process may have drawn on it.
     */
    public function trafficBalance(): int
    {
        $balance = array_key_exists(self::TRAFFIC, $this->attributes)
            ? $this->attributes[self::TRAFFIC]
            : self::lastLine()->where('bot_id', $this->id)->value('balance_after');

        return is_numeric($balance) ? (int) $balance : 0;
    }

    /**
     * Why `$balance` bytes of an agent's pool (trafficBalance()) do not pay for a sale of `$bytes` — no quota (unlimited),
     * which prepaid traffic cannot pay for, or too little left —, in Agency's TrafficShortException's words; null when
     * they do. A delivery draws by it (Agency\Services\TrafficPool::draw()); a plan offered (Catalog's ServerSelector) and
     * an order support makes by hand ask it first.
     */
    public static function trafficRefusal(int $bytes, int $balance): ?string
    {
        return match (true) {
            $bytes <= 0 => TrafficShortException::UNLIMITED,
            $balance < $bytes => sprintf(TrafficShortException::SHORT, Messages::bytes($bytes), Messages::bytes(max(0, $balance))),
            default => null,
        };
    }

    /**
     * A list's `addSelect()` that reads each bot's traffic balance with its row: one subquery on the ledger's
     * (bot_id, id) index instead of a query per row.
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
     * What the bot's shop has done: its customers, the services it sold (purchases delivered) and how many of its
     * services run now — counted with the row by a list (statCounts()), otherwise now.
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
     * The counts stats() reads, for a list's `withCount()`: one subquery each instead of queries per row.
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
     * A `select()` column for the number of services its shop sold, alone — the agents list sorts by it.
     *
     * @return array<string, Builder<Order>>
     */
    public static function soldColumn(): array
    {
        return ['sold_count' => self::sales(Order::query()->withoutGlobalScope(CurrentBot::SCOPE)->selectRaw('count(*)')->whereColumn('orders.bot_id', 'bots.id'))];
    }

    /**
     * Narrow orders to what a shop sold: delivered purchases.
     *
     * @template TOrder of Model
     * @param Builder<TOrder> $orders
     * @return Builder<TOrder>
     */
    private static function sales(Builder $orders): Builder
    {
        return $orders->where('type', OrderType::Purchase->value)->where('status', OrderStatus::Fulfilled->value);
    }

    /** @return HasMany<User, $this> The customers of its shop, whichever shop the code runs in. */
    public function customers(): HasMany
    {
        return self::acrossShops($this->hasMany(User::class));
    }

    /** @return HasMany<Order, $this> The orders of its shop, whichever shop the code runs in. */
    public function orders(): HasMany
    {
        return self::acrossShops($this->hasMany(Order::class));
    }

    /** @return HasMany<Subscription, $this> The services its shop sold, whichever shop the code runs in. */
    public function subscriptions(): HasMany
    {
        return self::acrossShops($this->hasMany(Subscription::class));
    }

    /**
     * A relation to this bot's shop's rows without the current shop's scope, which would hide them while the code runs in
     * another shop (the owner's agents page lists every agent's bot from the main shop).
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

    /** @return BelongsTo<User, $this> The agent — a customer of the main bot, whichever shop the code runs in. */
    public function agent(): BelongsTo
    {
        $agent = $this->belongsTo(User::class, 'user_id');
        $agent->withoutGlobalScope(CurrentBot::SCOPE);

        return $agent;
    }
}
