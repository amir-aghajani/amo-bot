<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Broadcasts;

use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\CustomerGroup;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Whom a broadcast goes to — always the current bot's customers who are not banned and have a Telegram account (one
 * who signed up on the website alone has no chat to send to, and is neither counted nor reached): everyone; buyers (a
 * service bought: a purchase sold) or non-buyers (none); the inactive ones («غیرفعال‌ها»: no service running now — never
 * bought, or every one ended or switched off); one of the admin's customer groups; the agents (the main bot's only); or
 * the customers with a running service on one server — of the servers this bot's customers are on, so an agent's bot
 * never learns of the shop's other servers. A run takes them in id order past its cursor, so a customer who joins the
 * audience meanwhile may still get it and one who leaves it may not; an audience whose group or server is gone reaches
 * nobody.
 */
final class Audience
{
    public const ALL = 'all';
    public const BUYERS = 'buyers';
    public const NON_BUYERS = 'non_buyers';
    public const INACTIVE = 'inactive';
    public const GROUP = 'group';
    public const AGENTS = 'agents';
    public const SERVER = 'server';

    /** Every audience, in the order the bot offers them, with its words. */
    public const LABELS = [
        self::ALL => 'همه کاربران',
        self::BUYERS => 'خریداران',
        self::NON_BUYERS => 'غیرخریداران',
        self::INACTIVE => 'غیرفعال‌ها (بدون سرویس فعال)',
        self::GROUP => 'یک گروه',
        self::AGENTS => 'نماینده‌ها',
        self::SERVER => 'مشتری‌های یک سرور',
    ];

    /** The audiences that name something (a group, a server) by `audience_id`. */
    public const WITH_ID = [self::GROUP, self::SERVER];

    private function __construct(
        public readonly string $key,
        public readonly ?int $id,
        private readonly ?string $name,
    ) {}

    /**
     * The audience a key (and, for a group or a server, its id) names, as the current bot can reach it now; null for an
     * unknown key, a group or a server that is gone, or one that is not this bot's to name.
     */
    public static function of(string $key, ?int $id = null): ?self
    {
        return match ($key) {
            self::ALL, self::BUYERS, self::NON_BUYERS, self::INACTIVE, self::AGENTS => new self($key, null, null),
            self::GROUP => ($group = CustomerGroup::query()->find((int) $id)) !== null ? new self($key, $group->id, $group->name) : null,
            self::SERVER => ($server = self::serversQuery()->find((int) $id)) !== null ? new self($key, $server->id, $server->name) : null,
            default => null,
        };
    }

    /**
     * The words for an audience as a run recorded it — one whose group or server is gone since says so.
     *
     * @param array<string, string> $names Names already looked up, by "key:id" — a list shows each one once
     */
    public static function labelOf(string $key, ?int $id, array &$names = []): string
    {
        if (!in_array($key, self::WITH_ID, true)) {
            return self::LABELS[$key] ?? $key;
        }

        $name = $names["{$key}:{$id}"] ??= (string) ($key === self::GROUP ? CustomerGroup::query()->find((int) $id)?->name : Server::query()->find((int) $id)?->name);

        return match (true) {
            $name === '' && $key === self::GROUP => 'گروهی که حذف شده',
            $name === '' => 'مشتری‌های سروری که حذف شده',
            default => self::named($key, $name),
        };
    }

    /** @return Collection<int, Server> The servers whose customers a broadcast of this bot can reach: those its customers have a running service on. */
    public static function servers(): Collection
    {
        return self::serversQuery()->oldest('id')->get();
    }

    /** «خریداران», «گروه «VIP»», «مشتری‌های سرور «آلمان»». */
    public function label(): string
    {
        return $this->name === null ? self::LABELS[$this->key] : self::named($this->key, $this->name);
    }

    /** What a list of groups or servers to pick from shows: «VIP», «آلمان». */
    public function name(): string
    {
        return $this->name ?? self::LABELS[$this->key];
    }

    /** @return Builder<User> The customers it reaches now: the bot's chats — a customer without Telegram has none. */
    public function users(): Builder
    {
        $users = User::query()->where('status', UserStatus::Active->value)->whereNotNull('telegram_id');
        // A buyer: a purchase of theirs sold — a wallet top-up alone is no purchase.
        $buyers = Order::sold()->where('type', OrderType::Purchase->value)->select('user_id');

        return match ($this->key) {
            self::BUYERS => $users->whereIn('id', $buyers),
            self::NON_BUYERS => $users->whereNotIn('id', $buyers),
            self::INACTIVE => $users->whereNotIn('id', Subscription::active()->select('user_id')),
            self::GROUP => $users->whereHas('groups', fn(Builder $groups) => $groups->whereKey($this->id)),
            self::AGENTS => $users->whereNotNull('agency_level_id'),
            self::SERVER => $users->whereIn('id', Subscription::active()->where('server_id', $this->id)->select('user_id')),
            default => $users,
        };
    }

    /**
     * How many it reaches, and how many of them blocked the bot (counted, never sent to).
     *
     * @return array{total: int, blocked: int}
     */
    public function size(): array
    {
        return ['total' => $this->users()->count(), 'blocked' => $this->users()->where('bot_blocked', true)->count()];
    }

    /** @return Builder<Server> */
    private static function serversQuery(): Builder
    {
        return Server::query()->whereIn('id', Subscription::active()->select('server_id'));
    }

    private static function named(string $key, string $name): string
    {
        return $key === self::GROUP ? "گروه «{$name}»" : "مشتری‌های سرور «{$name}»";
    }
}
