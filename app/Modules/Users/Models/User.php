<?php

declare(strict_types=1);

namespace App\Modules\Users\Models;

use App\Core\Database\Casts\Encrypted;
use App\Core\Database\Page;
use App\Modules\Agency\Models\AgencyLevel;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Bots\Models\Concerns\BelongsToBot;
use App\Modules\Orders\Models\Order;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Enums\UserStatus;
use App\Support\Input;
use App\Support\Money;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Carbon;

/**
 * A customer of the shop, from the bot or the website (Services\Customers). Each way in — a Telegram account, a proven
 * email with a password, a Google account — is unique in the shop and may be missing. `role` admin marks the shop's
 * people; the panel's login is not a user (it lives in config.php). An agent has a level and may spend their wallet down
 * to minus `credit_limit`. The balance is the wallet ledger's last line (balance()).
 *
 * @property int $id
 * @property int|null $telegram_id Null: no Telegram account — they signed up on the website
 * @property string|null $email Lower case; only ever kept once proven (a code sent to it typed back, or Google's word)
 * @property string|null $password_hash Bcrypt, only with an email
 * @property string|null $google_sub Their Google account's id
 * @property string|null $totp_secret Their authenticator app's secret (base32), encrypted; kept while two-factor sign-in is on
 * @property string|null $totp_recovery_codes The keyed hashes of the recovery codes not used yet (JSON), encrypted
 * @property Carbon|null $totp_enabled_at Since when the password sign-in asks the app's code; null: it does not
 * @property int|null $totp_last_step The time step of the last code taken: no code of it, or of one before it, is taken again
 * @property string|null $username The Telegram handle, without the @
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $phone E.164 ("+98912…"), the number the customer shared with their own contact card
 * @property UserStatus $status
 * @property UserRole $role
 * @property bool $bot_blocked Telegram said so: the customer blocked the bot
 * @property int|null $agency_level_id An agent's level; null for everyone else
 * @property string $credit_limit How far below zero an agent's wallet may go
 * @property int $discount_percent Their own discount («تخفیف اختصاصی»), a percent off what they buy; 0 none
 * @property string|null $referral_code The customer's own invite code (ReferralService::codeFor())
 * @property int|null $referred_by The customer whose link brought this one — set on their first update, never changed
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Order> $orders
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Subscription> $subscriptions
 * @property-read User|null $referrer
 * @property-read AgencyLevel|null $agencyLevel
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CustomerGroup> $groups
 * @property-read Bot|null $ownBot
 */
class User extends Model
{
    use BelongsToBot;

    protected $table = 'users';

    /** What balanceColumn() names the balance it reads with the row. */
    private const BALANCE = 'wallet_balance';

    protected $fillable = [
        'telegram_id',
        'email',
        'password_hash',
        'google_sub',
        'username',
        'first_name',
        'last_name',
        'phone',
        'status',
        'role',
        'bot_blocked',
        'agency_level_id',
        'credit_limit',
        'discount_percent',
        'referral_code',
        'referred_by',
        'last_seen_at',
    ];

    protected $hidden = ['password_hash', 'totp_secret', 'totp_recovery_codes'];

    /** @var array<string, string> */
    protected $casts = [
        'telegram_id' => 'integer',
        'totp_secret' => Encrypted::class,
        'totp_recovery_codes' => Encrypted::class,
        'totp_enabled_at' => 'datetime',
        'totp_last_step' => 'integer',
        'referred_by' => 'integer',
        'status' => UserStatus::class,
        'role' => UserRole::class,
        'bot_blocked' => 'boolean',
        'agency_level_id' => 'integer',
        'credit_limit' => 'decimal:2',
        'discount_percent' => 'integer',
        'last_seen_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => UserStatus::Active->value,
        'role' => UserRole::Customer->value,
        'credit_limit' => '0.00',
        'discount_percent' => 0,
    ];

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /** An agent («نماینده»): on a level, whose price per GB their bot's traffic is bought at. */
    public function isAgent(): bool
    {
        return $this->agency_level_id !== null;
    }

    /** Whether plans' agent prices are theirs: an agent, or in a «همکار» group (asked only while a plan has one). */
    public function paysAgentPrices(): bool
    {
        return $this->isAgent() || $this->groups()->where('agent_pricing', true)->exists();
    }

    /** How far below zero the wallet may go: an agent's credit, none for anyone else. */
    public function credit(): string
    {
        return Money::normalize($this->isAgent() ? $this->credit_limit : 0);
    }

    /** What the wallet can pay now: the balance, and an agent's credit on top. */
    public function spendable(): string
    {
        return Money::add($this->balance(), $this->credit());
    }

    public function isBanned(): bool
    {
        return $this->status === UserStatus::Banned;
    }

    /** Their password sign-in asks a second step — a code of their authenticator app, or one of their recovery codes. */
    public function hasTwoFactor(): bool
    {
        return $this->totp_enabled_at !== null && $this->totp_secret !== null;
    }

    /** A phone is only ever stored from the customer's own contact card (verifyPhone()): one on the row is verified. */
    public function hasVerifiedPhone(): bool
    {
        return $this->phone !== null;
    }

    /** Record the number the customer shared with their own contact card: digits only, with the international "+". */
    public function verifyPhone(string $number): void
    {
        $this->forceFill(['phone' => '+' . preg_replace('/\D+/', '', $number)])->save();
    }

    /** The wallet balance: the last ledger line's ("0.00" before any), read with the row when a list asked (balanceColumn()). */
    public function balance(): string
    {
        $balance = array_key_exists(self::BALANCE, $this->attributes)
            ? $this->attributes[self::BALANCE]
            : self::lastLine()->where('user_id', $this->id)->value('balance_after');

        return Money::normalize(is_numeric($balance) ? (string) $balance : '0');
    }

    /**
     * For a list's `addSelect()`: each customer's balance read with their row by one subquery on the ledger's index.
     *
     * @return array<string, Builder<WalletTransaction>>
     */
    public static function balanceColumn(): array
    {
        return [self::BALANCE => self::lastLine()->whereColumn('wallet_transactions.user_id', 'users.id')];
    }

    /** The order-by expression for the balance read by balanceColumn(), a customer without a ledger at 0. */
    public static function balanceOrder(): ExpressionContract
    {
        return new Expression('COALESCE(' . self::BALANCE . ', 0)');
    }

    /**
     * The newest ledger line, without the shop scope (an agent's main-shop wallet is read from their own bot's panel).
     *
     * @return Builder<WalletTransaction>
     */
    private static function lastLine(): Builder
    {
        return WalletTransaction::query()->withoutGlobalScope(CurrentBot::SCOPE)->select('balance_after')->latest('id')->limit(1);
    }

    /** The name ("first last") — as Telegram gave it, or as they signed up with —, or null when there is none worth showing. */
    public function name(): ?string
    {
        $name = trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));

        return $name === '' ? null : $name;
    }

    /** The Telegram handle, without the @; null when the account shows none. */
    public function handle(): ?string
    {
        return ($this->username ?? '') !== '' ? $this->username : null;
    }

    /**
     * Who they are as another customer can tell — whom a service or money goes to, or came from: «Sara Ahmadi (@sara)»,
     * the name or the handle alone, else the Telegram id, else «#12», their number in the shop. Never their email. The
     * handle after the name has a left-to-right mark (U+200E) before it — after a Persian name it would read «(sara@)»;
     * one that starts the value gets its mark where it is filled in, only when words come before it there
     * (Telegram\Texts\BotTexts, the wallet's lines).
     */
    public function shownAs(): string
    {
        $name = $this->name();
        $handle = $this->handle() === null ? null : '@' . $this->handle();

        return match (true) {
            $name !== null && $handle !== null => "{$name} (\u{200E}{$handle})",
            default => $name ?? $handle ?? ($this->telegram_id !== null ? (string) $this->telegram_id : "#{$this->id}"),
        };
    }

    /**
     * Narrows `$query` to customers matching a search term (already normalised, Page::term()): name, handle (a leading
     * "@" allowed) and email by substring, a Telegram id exactly. Every list searches customers this way.
     *
     * @param Builder<self> $query
     */
    public static function matching(Builder $query, string $term): void
    {
        $handle = ltrim($term, '@');
        $telegramId = Input::telegramIdOf($term);

        $query->where(static function (Builder $q) use ($term, $handle, $telegramId): void {
            Page::whereContains($q, 'first_name', $term);
            Page::orWhereContains($q, 'last_name', $term);
            Page::orWhereContains($q, 'username', $handle);
            // An email is kept in lower case: what was typed is looked for the same way.
            Page::orWhereContains($q, 'email', mb_strtolower($term));
            if ($telegramId !== null) {
                $q->orWhere('telegram_id', $telegramId);
            }
        });
    }

    /**
     * The ids of the customers a term finds, read once (Page::matches()), for another list to narrow itself by with
     * whereIn(); `$listed` false returns the subquery instead, for short lists.
     *
     * @return list<int|string>|Builder<self>
     */
    public static function idsMatching(string $term, bool $listed = true): array|Builder
    {
        $users = User::query()->select('id');
        self::matching($users, $term);

        return $listed ? Page::matches($users) : $users;
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** @return BelongsTo<User, $this> The customer whose link brought this one. */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'referred_by');
    }

    /** @return BelongsTo<AgencyLevel, $this> An agent's level. */
    public function agencyLevel(): BelongsTo
    {
        return $this->belongsTo(AgencyLevel::class);
    }

    /** @return BelongsToMany<CustomerGroup, $this> The admin's groups the customer is in, in the admin's order. */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(CustomerGroup::class, 'customer_group_user')->orderBy('customer_groups.sort')->orderBy('customer_groups.id');
    }

    /** @return HasMany<User, $this> The customers this one's link brought. */
    public function referrals(): HasMany
    {
        return $this->hasMany(self::class, 'referred_by');
    }

    /**
     * An agent's own bot (their shop), opened on approval; bot() is the shop this customer belongs to.
     *
     * @return HasOne<Bot, $this>
     */
    public function ownBot(): HasOne
    {
        return $this->hasOne(Bot::class);
    }
}
