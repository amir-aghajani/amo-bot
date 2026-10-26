<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use App\Core\Database\Page;
use App\Core\Database\PageRequest;
use App\Modules\Orders\Models\Order;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Enums\TestState;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Models\TestAccount;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\UserDirectory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The free test accounts' page («اکانت تست», both panels): the shop's tests, newest first — each with its customer, its
 * server, the terms it was given, where it stands (TestState) and whether the customer bought after it —, and every
 * server with its test terms and whether it would give a test in this shop now.
 */
final class TestAccountDirectory
{
    public function __construct(private readonly TestServers $servers) {}

    /**
     * The page asked for, narrowed by `status` (a TestState), `user` and the search box (the customer; «#12» is test 12),
     * newest first. Whether the customer bought after each test is read with the page, one subquery: a purchase counts
     * from when it was delivered, else from when it was made — a card order opened just before the test and approved
     * after it is a conversion.
     */
    public function search(PageRequest $list): Page
    {
        $now = now();
        $query = TestAccount::query()->select('test_accounts.*')->selectSub(
            Order::paidPurchases()->selectRaw('1')
                ->whereColumn('orders.user_id', 'test_accounts.user_id')
                ->whereRaw('COALESCE(orders.fulfilled_at, orders.created_at) > test_accounts.created_at')
                ->limit(1),
            'bought_after',
        );
        $state = $list->enum('status', TestState::class);
        if ($state !== null) {
            self::whereState($query, $state, $now);
        }
        $user = $list->id('user');
        if ($user !== null) {
            $query->where('user_id', $user);
        }
        $list->search($query, static function (Builder $query, string $term): void {
            $query->whereIn('user_id', User::idsMatching($term));
        });

        return Page::fetch(
            $query->with(['user', 'server', 'subscription'])->orderByDesc('id'),
            $list,
            static fn(TestAccount $test): array => self::present($test, $now),
        );
    }

    /**
     * Every server of the installation, in the servers' order, with its test terms (TestServers::present()) and whether
     * it would give a test in this shop now (TestServers::choices()) — the shop's own switch and quota aside.
     *
     * @return list<array<string, mixed>>
     */
    public function servers(): array
    {
        $giving = array_map(static fn(array $choice): int => $choice['server']->id, $this->servers->choices());

        // A server the owner keeps from the shop is none of its business.
        return array_values(Server::shown()->orderBy('sort')->orderBy('id')->get()->map(static fn(Server $server): array => [
            'id' => $server->id,
            'name' => $server->name,
            ...TestServers::present($server),
            'available' => in_array($server->id, $giving, true),
        ])->all());
    }

    /** @return array<string, mixed> One test, its customer, server and service loaded with it. */
    private static function present(TestAccount $test, Carbon $now): array
    {
        $service = $test->subscription;

        return [
            'id' => $test->id,
            'customer' => UserDirectory::presentRef($test->user),
            'server' => $test->server === null ? null : ['id' => $test->server->id, 'name' => $test->server->name],
            'traffic_bytes' => $test->traffic_bytes,
            'duration_hours' => $test->duration_hours,
            'on_hold' => $test->on_hold,
            'state' => self::state($test, $now)->value,
            'subscription' => $service === null ? null : [
                'id' => $service->id,
                'name' => $service->remote_name,
                'status' => $service->status->value,
                'traffic' => ['limit' => $service->traffic_limit_bytes, 'used' => $service->usedBytes()],
                'starts_at' => $service->starts_at?->toIso8601String(),
                'expires_at' => $service->expires_at?->toIso8601String(),
            ],
            'bought_after' => (bool) $test->getAttribute('bought_after'),
            'created_at' => $test->created_at?->toIso8601String(),
            'issued_at' => $test->issued_at?->toIso8601String(),
            'ended_at' => $service?->ended_at?->toIso8601String(),
        ];
    }

    /** Where one test stands, by its service as last seen — the same rule whereState() asks the database. */
    private static function state(TestAccount $test, Carbon $now): TestState
    {
        $service = $test->subscription;
        if ($test->issued_at === null) {
            return TestState::Making;
        }
        if ($service === null) {
            return TestState::Removed;
        }

        return match (true) {
            $service->status === SubscriptionStatus::Disabled => TestState::Disabled,
            $service->status !== SubscriptionStatus::Active, $service->expires_at?->lte($now) === true => TestState::Ended,
            $service->expires_at === null => TestState::Waiting,
            default => TestState::Running,
        };
    }

    /**
     * Narrow to the tests in `$state` (the list's tabs), by state() asked of the database.
     *
     * @param Builder<TestAccount> $tests
     */
    private static function whereState(Builder $tests, TestState $state, Carbon $now): void
    {
        $service = static fn(\Closure $narrow): Builder => $tests->whereHas('subscription', $narrow);
        $active = SubscriptionStatus::Active->value;

        match ($state) {
            TestState::Making => $tests->whereNull('issued_at'),
            TestState::Removed => $tests->whereNotNull('issued_at')->whereNull('subscription_id'),
            TestState::Disabled => $service(static fn(Builder $row) => $row->where('status', SubscriptionStatus::Disabled->value)),
            TestState::Ended => $service(static fn(Builder $row) => Subscription::over($row, $now)),
            TestState::Waiting => $service(static fn(Builder $row) => $row->where('status', $active)->whereNull('expires_at')),
            TestState::Running => $service(static fn(Builder $row) => $row->where('status', $active)->where('expires_at', '>', $now)),
        };
    }
}
