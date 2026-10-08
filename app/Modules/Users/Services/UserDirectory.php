<?php

declare(strict_types=1);

namespace App\Modules\Users\Services;

use App\Core\Database\Page;
use App\Core\Database\PageRequest;
use App\Modules\Auth\Services\Reviewers;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\CustomerGroup;
use App\Modules\Users\Models\User;
use App\Modules\Users\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Builder;

/**
 * The admin's view of the customer base ("کاربران"): a searchable, filterable page of the bot's users with the counts
 * the table shows and the admin's groups each is in (filterable by one), and the customer's wallet ledger — who wrote a
 * line by hand as its reader may see it (Reviewers). What is decided about a customer is UserActions'.
 */
final class UserDirectory
{
    public const LEDGER_LINES = 50;
    /** Digits below this length search ids only, not phone numbers. */
    private const PHONE_MIN = 4;

    public function __construct(
        private readonly WalletService $wallet,
        private readonly Reviewers $reviewers,
    ) {}

    /**
     * The customers — newest first, or in the order the table's headers ask for (`sort`: when they joined, when they
     * were last seen, their balance, their orders, their running services) —: by status, by role (the bot's admins), in
     * one of the admin's groups, searched.
     */
    public function search(PageRequest $list): Page
    {
        $query = User::query()->withCount(self::counts())->addSelect(User::balanceColumn())->with('groups');

        $status = $list->enum('status', UserStatus::class);
        if ($status !== null) {
            $query->where('status', $status->value);
        }
        $role = $list->enum('role', UserRole::class);
        if ($role !== null) {
            $query->where('role', $role->value);
        }
        $group = $list->id('group');
        if ($group !== null) {
            $query->whereHas('groups', static fn(Builder $groups) => $groups->whereKey($group));
        }
        $list->search($query, self::applySearch(...));

        return Page::fetch($query, $list, $this->present(...), $list->sort([
            'joined' => 'id',
            'last_seen' => 'last_seen_at',
            'balance' => User::balanceOrder(),
            'orders' => 'orders_count',
            'services' => 'active_subscriptions_count',
        ], 'joined'));
    }

    /** @return list<array<string, mixed>> The customer's latest ledger lines, newest first. */
    public function ledger(User $user): array
    {
        return $this->wallet->lines($user, self::LEDGER_LINES)->map($this->presentTransaction(...))->values()->all();
    }

    /** @return array<string, mixed> */
    public function presentTransaction(WalletTransaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'type' => $transaction->type->value,
            'amount' => $transaction->amount,
            'balance_after' => $transaction->balance_after,
            'description' => $transaction->description,
            'reviewer' => $this->reviewers->present($transaction->reviewer),
            'created_at' => $transaction->created_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> One row with its counts loaded now (after an edit of a single user). */
    public function presentWithCounts(User $user): array
    {
        return $this->present($user->loadCount(self::counts())->load('groups'));
    }

    /**
     * The customer as every other screen points at them (a payment's, an order's): the three
     * Telegram identifiers, kept apart — none but the name for a customer without Telegram —, their
     * email, plus our id.
     *
     * @return array{id: int, name: string|null, username: string|null, telegram_id: int|null, email: string|null}
     */
    public static function presentRef(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name(),
            'username' => $user->username,
            'telegram_id' => $user->telegram_id,
            'email' => $user->email,
        ];
    }

    /** @return array<string, mixed> One table row, its counts read with it (withCount()/loadCount()). */
    public function present(User $user): array
    {
        return self::presentRef($user) + [
            'phone' => $user->phone,
            'status' => $user->status->value,
            'role' => $user->role->value,
            'balance' => $user->balance(),
            'counts' => [
                'orders' => (int) $user->getAttribute('orders_count'),
                'subscriptions' => (int) $user->getAttribute('subscriptions_count'),
                'active_subscriptions' => (int) $user->getAttribute('active_subscriptions_count'),
            ],
            'groups' => $user->groups->map(static fn(CustomerGroup $group): array => CustomerGroups::presentRef($group))->values()->all(),
            'created_at' => $user->created_at->toIso8601String(),
            'last_seen_at' => $user->last_seen_at?->toIso8601String(),
        ];
    }

    /** @return array<int|string, string|\Closure> withCount()/loadCount() argument: plain counts plus the active-subscriptions one. */
    private static function counts(): array
    {
        return [
            'orders',
            'subscriptions',
            'subscriptions as active_subscriptions_count' => static fn(Builder $q) => $q->where('status', SubscriptionStatus::Active->value),
        ];
    }

    /**
     * The customer the way every directory finds one (User::matching(): the name, the handle, the email, the Telegram
     * id), plus what only this table knows: the phone by substring — a leading "+" is what people paste — and our own
     * id, a bare number. («#12» is the customer numbered 12 alone: PageRequest::search().)
     *
     * @param Builder<User> $builder
     */
    private static function applySearch(Builder $builder, string $term, ?int $number): void
    {
        $digits = ltrim($term, '+');

        $builder->where(static function (Builder $q) use ($term, $digits, $number): void {
            User::matching($q, $term);

            // A couple of digits are an id, not a piece of every phone number.
            if (strlen($digits) >= self::PHONE_MIN || !ctype_digit($digits)) {
                Page::orWhereContains($q, 'phone', $digits);
            }
            if ($number !== null) {
                $q->orWhere('id', $number);
            }
        });
    }
}
