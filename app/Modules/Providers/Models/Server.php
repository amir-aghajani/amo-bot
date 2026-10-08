<?php

declare(strict_types=1);

namespace App\Modules\Providers\Models;

use App\Core\Database\Casts\Encrypted;
use App\Modules\Bots\CurrentBot;
use App\Modules\Subscriptions\Models\Subscription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A VPN panel instance (one 3x-ui or PasarGuard installation) the shop provisions clients on — the shop's as a whole:
 * every bot sells on it. `driver` names its connector (Contracts\PanelDriver), whose form its connection columns and
 * `meta` — the connection options (Support\PanelConnection) — are kept from (Services\ServerService).
 *
 * @property int $id
 * @property string $name
 * @property string $driver
 * @property string $base_url Panel URL including any base path, no trailing slash
 * @property string|null $username Session login; optional when an API token is set
 * @property string|null $password Stored encrypted, transparently decrypted
 * @property string|null $api_token Panel API token, encrypted — preferred over username/password
 * @property string|null $totp_secret Base32 2FA secret for session logins on panels with 2FA, encrypted
 * @property bool $is_active
 * @property int|null $capacity Max active subscriptions, null = unlimited
 * @property int $sort
 * @property string|null $notes
 * @property Carbon|null $last_checked_at when the shop last talked to the panel (a check, a sync, any panel work)
 * @property string|null $last_error why that last contact failed, in the owner's words; null when it answered
 * @property bool|null $serves_subscriptions Whether the panel handed out subscription links as of the last check; null = never asked
 * @property array<string, mixed>|null $meta
 * @property Carbon $created_at
 * @property Carbon $updated_at
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
        'is_active',
        'capacity',
        'sort',
        'notes',
        'last_checked_at',
        'last_error',
        'serves_subscriptions',
        'meta',
    ];

    protected $hidden = ['password', 'api_token', 'totp_secret'];

    /** @var array<string, string> */
    protected $casts = [
        'password' => Encrypted::class,
        'api_token' => Encrypted::class,
        'totp_secret' => Encrypted::class,
        'is_active' => 'boolean',
        'capacity' => 'integer',
        'sort' => 'integer',
        'last_checked_at' => 'datetime',
        'serves_subscriptions' => 'boolean',
        'meta' => 'array',
    ];

    /**
     * Whether the panel hands out subscription links — what a customer gets, and all they get — so a server without them
     * (or one never checked) cannot be sold: not offered to a plan, not chosen at purchase.
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

    /** Its active services, every bot's: as a list counted them with it (activeServicesCount()), or counted now. */
    public function activeServices(): int
    {
        return array_key_exists('active_subscriptions_count', $this->attributes)
            ? (int) $this->attributes['active_subscriptions_count']
            : Subscription::active($this->subscriptions()->getQuery())->count();
    }

    /**
     * The count activeServices() reads, for a list's `withCount()` — of the servers or of a relation to them: one
     * subquery for the whole list.
     *
     * @return array<string, \Closure(Builder<Subscription>): mixed>
     */
    public static function activeServicesCount(): array
    {
        return ['subscriptions as active_subscriptions_count' => static fn(Builder $services) => Subscription::active($services)];
    }

    /**
     * Whether the shop leaves the panel alone for now: its last contact failed less than BACKOFF_MINUTES ago. The tasks
     * skip it and the customer's screen shows what the shop last knew, instead of waiting for a panel that does not
     * answer; the first contact after the time is up — or the admin's own check — finds out whether it is back.
     */
    public function isBackingOff(): bool
    {
        return $this->last_error !== null && $this->last_checked_at !== null && $this->last_checked_at->isAfter(now()->subMinutes(self::BACKOFF_MINUTES));
    }

    /** @return HasMany<ServerInbound, $this> */
    public function inbounds(): HasMany
    {
        return $this->hasMany(ServerInbound::class);
    }

    /**
     * What a whole-server sale gets: the inbounds marked sellable and enabled, first seen first — out of the inbounds a
     * list read with the server (`inbounds`), otherwise read now.
     *
     * @return Collection<int, ServerInbound>
     */
    public function sellableInbounds(): Collection
    {
        $inbounds = $this->relationLoaded('inbounds') ? $this->inbounds : $this->inbounds()->get();

        return $inbounds->filter(static fn(ServerInbound $inbound): bool => $inbound->is_selectable && $inbound->enabled)->sortBy('id')->values();
    }

    /**
     * The services on it, every bot's — a panel's clients are the server's, whichever shop sold them —, whatever shop
     * the code works in.
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
