<?php

declare(strict_types=1);

namespace App\Modules\Providers\Models;

use App\Core\Database\Casts\Encrypted;
use App\Modules\Bots\CurrentBot;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Telegram\Keyboard\KeyboardLayout;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A VPN panel (one 3x-ui or PasarGuard installation) the shop creates clients on. Servers belong to the installation,
 * not a shop: every bot sells on them. `driver` names its connector (Contracts\PanelDriver); the connector's form is
 * kept in the connection columns and `meta` (Services\ServerService).
 *
 * @property int $id
 * @property string $name
 * @property string $driver
 * @property string $base_url Panel URL including any base path, no trailing slash
 * @property string|null $username Session login; optional when an API token is set
 * @property string|null $password Stored encrypted, transparently decrypted
 * @property string|null $api_token Panel API token, encrypted — preferred over username/password
 * @property string|null $totp_secret Base32 2FA secret for session logins on panels with 2FA, encrypted
 * @property bool $proxy_enabled whether the panel is reached through a SOCKS5 proxy (Support\PanelConnection::$proxy)
 * @property string|null $proxy_host the proxy's host name or IP address
 * @property int|null $proxy_port
 * @property string|null $proxy_username optional
 * @property string|null $proxy_password optional, only with a username; encrypted
 * @property bool $is_active
 * @property int|null $capacity Max active subscriptions, null = unlimited
 * @property int $sort
 * @property string|null $notes
 * @property Carbon|null $last_checked_at when the shop last talked to the panel (a check, a sync, any panel work)
 * @property string|null $last_error why that last contact failed, worded for the owner; null when it answered
 * @property bool|null $serves_subscriptions Whether the panel handed out subscription links as of the last check; null = never asked
 * @property array<string, mixed>|null $meta
 * @property bool $test_enabled whether the panel gives free test accounts («اکانت تست»)
 * @property int $test_traffic_bytes a test's traffic
 * @property int $test_duration_hours a test's term
 * @property bool $test_on_hold whether a test's term counts from its first connection, else from the moment it is made
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, ServerInbound> $inbounds
 * @property-read Collection<int, Subscription> $subscriptions
 */
class Server extends Model
{
    /** How long the shop leaves a panel alone after it failed: every try costs a connect timeout, and the bot waits it. */
    public const BACKOFF_MINUTES = 10;

    protected $table = 'servers';

    protected $fillable = [
        'name',
        'driver',
        'base_url',
        'username',
        'password',
        'api_token',
        'totp_secret',
        'proxy_enabled',
        'proxy_host',
        'proxy_port',
        'proxy_username',
        'proxy_password',
        'is_active',
        'capacity',
        'sort',
        'notes',
        'last_checked_at',
        'last_error',
        'serves_subscriptions',
        'meta',
        'test_enabled',
        'test_traffic_bytes',
        'test_duration_hours',
        'test_on_hold',
    ];

    protected $hidden = ['password', 'api_token', 'totp_secret', 'proxy_password'];

    /** @var array<string, string> */
    protected $casts = [
        'password' => Encrypted::class,
        'api_token' => Encrypted::class,
        'totp_secret' => Encrypted::class,
        'proxy_enabled' => 'boolean',
        'proxy_port' => 'integer',
        'proxy_password' => Encrypted::class,
        'is_active' => 'boolean',
        'capacity' => 'integer',
        'sort' => 'integer',
        'last_checked_at' => 'datetime',
        'serves_subscriptions' => 'boolean',
        'meta' => 'array',
        'test_enabled' => 'boolean',
        'test_traffic_bytes' => 'integer',
        'test_duration_hours' => 'integer',
        'test_on_hold' => 'boolean',
    ];

    /**
     * Its button's colour among a plan's locations in the bot (one of Telegram's styles, the form's `button_style` kept in
     * `meta`); null for the client's default look.
     */
    public function buttonStyle(): ?string
    {
        $style = $this->meta['button_style'] ?? null;

        return is_string($style) && $style !== '' ? $style : null;
    }

    /** How many location buttons share its button's row in the bot (the server form's `button_row`): 1, 2 or 3. */
    public function buttonRow(): int
    {
        return max(1, min(KeyboardLayout::ROW_MOST, (int) ($this->meta['button_row'] ?? 1)));
    }

    /**
     * Whether the panel hands out subscription links, as of the last check — the one thing a customer gets, so a server
     * without them (or never checked) cannot be sold.
     */
    public function servesSubscriptions(): bool
    {
        return $this->serves_subscriptions === true;
    }

    /** Room for one more client: no capacity set, or fewer active services on it than it. */
    public function hasCapacity(): bool
    {
        return $this->capacity === null || $this->activeServices() < $this->capacity;
    }

    /** Its active services, every bot's: the count a list read with it (activeServicesCount()), else counted now. */
    public function activeServices(): int
    {
        return array_key_exists('active_subscriptions_count', $this->attributes)
            ? (int) $this->attributes['active_subscriptions_count']
            : Subscription::active($this->subscriptions()->getQuery())->count();
    }

    /**
     * The count activeServices() reads, for a list's `withCount()`: one subquery for the whole list.
     *
     * @return array<string, \Closure(Builder<Subscription>): mixed>
     */
    public static function activeServicesCount(): array
    {
        return ['subscriptions as active_subscriptions_count' => static fn(Builder $services) => Subscription::active($services)];
    }

    /**
     * Whether the shop leaves the panel alone for now: its last contact failed less than BACKOFF_MINUTES ago. The tasks
     * skip it and a customer's screen says it could not read the service, instead of waiting out a timeout; the first
     * contact after that, or the admin's own check, finds out whether it is back.
     */
    public function isBackingOff(): bool
    {
        return $this->last_error !== null && $this->last_checked_at !== null && $this->last_checked_at->isAfter(now()->subMinutes(self::BACKOFF_MINUTES));
    }

    /**
     * The servers the current shop deals in: every one, but those the owner keeps from an agent's shop
     * (Bot::hiddenServerIds()) — none of its plan form's, discount codes', website's or free tests' choices.
     *
     * @return Builder<static>
     */
    public static function shown(): Builder
    {
        return static::query()->whereKeyNot(CurrentBot::get()->hiddenServerIds());
    }

    /** @return HasMany<ServerInbound, $this> */
    public function inbounds(): HasMany
    {
        return $this->hasMany(ServerInbound::class);
    }

    /**
     * What a whole-server sale gets: the inbounds marked sellable and enabled, first seen first — from the loaded
     * `inbounds` when a list read them, otherwise read now.
     *
     * @return Collection<int, ServerInbound>
     */
    public function sellableInbounds(): Collection
    {
        $inbounds = $this->relationLoaded('inbounds') ? $this->inbounds : $this->inbounds()->get();

        return $inbounds->filter(static fn(ServerInbound $inbound): bool => $inbound->is_selectable && $inbound->enabled)->sortBy('id')->values();
    }

    /**
     * The services on it, every bot's, whatever shop the code works in: a panel's clients are the server's, whichever
     * shop sold them.
     *
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        $subscriptions = $this->hasMany(Subscription::class);
        $subscriptions->withoutGlobalScope(CurrentBot::SCOPE);

        return $subscriptions;
    }
}
