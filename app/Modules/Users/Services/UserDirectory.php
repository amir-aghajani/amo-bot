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
use App\Modules\Users\Models\WalletTransfer;
use Illuminate\Database\Eloquent\Builder;

/**
 * The customers screen («کاربران»): a filterable, searchable page with each customer's counts and groups, and a
 * customer's wallet ledger. Decisions about a customer are UserActions'.
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

    /** A page of customers by status, role and group, searched, newest first or by a column. */
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

    /**
     * @return list<array<string, mixed>> The customer's latest ledger lines, newest first — a wallet transfer's with the
     *                                    other customer (WalletTransfers), and the names of the shop's admins who
     *                                    wrote lines by hand (Reviewers::names()), read for the page at once
     */
    public function ledger(User $user): array
    {
        $lines = $this->wallet->lines($user, self::LEDGER_LINES);
        $others = [];
        if ($lines->isNotEmpty()) {
            $ids = $lines->modelKeys();
            $transfers = WalletTransfer::query()->with(['sender', 'recipient'])->where(static fn(Builder $made) => $made->whereIn('debit_id', $ids)->orWhereIn('credit_id', $ids))->get();
            foreach ($transfers as $transfer) {
                $others[$transfer->debit_id] = $transfer->recipient;
                $others[$transfer->credit_id] = $transfer->sender;
            }
        }
        $names = $this->reviewers->names($lines->pluck('reviewer'));

        return $lines->map(fn(WalletTransaction $line): array => $this->line($line, $others[$line->id] ?? null, $names[$line->reviewer ?? ''] ?? null))->values()->all();
    }

    /** @return array<string, mixed> A line written now, with the name of the admin who wrote it. */
    public function presentTransaction(WalletTransaction $transaction): array
    {
        return $this->line($transaction, null, $this->reviewers->names([$transaction->reviewer])[$transaction->reviewer ?? ''] ?? null);
    }

    /**
     * @param User|null $counterpart A wallet transfer's line: whom the money went to, or came from
     * @param string|null $reviewerName The name of the admin who wrote it by hand (Reviewers::names())
     * @return array<string, mixed>
     */
    private function line(WalletTransaction $transaction, ?User $counterpart, ?string $reviewerName): array
    {
        return [
            'id' => $transaction->id,
            'type' => $transaction->type->value,
            'amount' => $transaction->amount,
            'balance_after' => $transaction->balance_after,
            'description' => $transaction->description,
            'reviewer' => $this->reviewers->present($transaction->reviewer),
            'reviewer_name' => $reviewerName,
            'counterpart' => $counterpart === null ? null : self::presentRef($counterpart),
            'created_at' => $transaction->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> One row with its counts loaded now (after an edit of a single user). */
    public function presentWithCounts(User $user): array
    {
        return $this->present($user->loadCount(self::counts())->load('groups'));
    }

    /**
     * The customer as other screens refer to them: name, handle, Telegram id and email kept apart, plus our id.
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
            // Their own discount («تخفیف اختصاصی»), a percent off what they buy; 0 none.
            'discount_percent' => $user->discount_percent,
            'counts' => [
                'orders' => (int) $user->getAttribute('orders_count'),
                'subscriptions' => (int) $user->getAttribute('subscriptions_count'),
                'active_subscriptions' => (int) $user->getAttribute('active_subscriptions_count'),
            ],
            'groups' => $user->groups->map(static fn(CustomerGroup $group): array => CustomerGroups::presentRef($group))->values()->all(),
            'created_at' => $user->created_at?->toIso8601String(),
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
     * A search as every list finds customers (User::matching()), plus the phone (four digits or more) and our own id.
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
