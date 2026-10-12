<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use App\Modules\Agency\Exceptions\TrafficShortException;
use App\Modules\Agency\Services\TrafficPool;
use App\Modules\Bots\CurrentBot;
use App\Modules\Orders\Models\Order;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Models\ServerInbound;
use App\Modules\Subscriptions\DTO\TestTerms;
use App\Modules\Subscriptions\Enums\TestRefusal;
use App\Modules\Subscriptions\Exceptions\TestAccountRefusedException;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Models\TestAccount;
use App\Modules\Users\Models\User;
use App\Support\LocalTime;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Free test accounts («اکانت تست»): which servers a customer may take one on now (offer()) and taking one (issue()).
 *
 * The quota: test accounts switched on; newcomers only, if the shop says so (a purchase with a price —
 * Order::paidPurchases() — makes a buyer); at most perUser() tests a customer, for good or within periodDays(); at most
 * dailyLimit() a day of the shop's clock (an admin's tests count there too). A bot admin is held to none but the switch.
 * A test is judged under a lock — the shop's bot row while there is a cap a day, else the customer's row — and claimed
 * with a `test_accounts` row before the panel is asked, so two taps at once never take more than the quota; one that
 * fails gives its claim back. What a test gets is its server's terms (TestServers), and in an agent's shop its traffic
 * comes out of the agent's pool as it is made.
 */
final class TestAccounts
{
    public function __construct(
        private readonly TestAccountSettings $settings,
        private readonly TestServers $servers,
        private readonly ProvisioningService $provisioning,
        private readonly TrafficPool $pool,
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * The servers the customer may take a test on now, in the servers' order, each with its terms.
     *
     * @return list<array{server: Server, terms: TestTerms, inbounds: Collection<int, ServerInbound>}>
     * @throws TestAccountRefusedException
     */
    public function offer(User $user): array
    {
        $this->admit($user);

        return $this->servers->choices() ?: throw TestAccountRefusedException::because(TestRefusal::NoServer);
    }

    /**
     * Take a test on the server for the customer: the quota judged again and claimed under the lock, then the client made
     * on the panel, and the service, the claim issued and — in an agent's shop — the traffic drawn from the agent's pool
     * written in one transaction. A failure gives the claim back (the client is taken back off the panel) and is thrown
     * as it came; an agent's pool short at that moment is a refusal.
     *
     * @throws TestAccountRefusedException before anything was made
     * @throws \Throwable what the panel or the provisioning threw (ProviderException, NoServerAvailableException …)
     */
    public function issue(User $user, int $serverId): Subscription
    {
        $this->admit($user);
        ['server' => $server, 'terms' => $terms, 'inbounds' => $inbounds] = $this->servers->choice($serverId)
            ?? throw TestAccountRefusedException::because(TestRefusal::NoServer);
        $claim = $this->claim($user, $server, $terms);
        $shop = CurrentBot::get();

        try {
            return $this->provisioning->provisionTest($user, $server, $inbounds, $terms, function (Subscription $subscription) use ($claim, $shop, $terms): void {
                if (!$shop->isMain()) {
                    $this->pool->drawTest($shop, $terms->bytes, $subscription->remote_name);
                }
                $claim->forceFill(['subscription_id' => $subscription->id, 'issued_at' => now()])->save();
            });
        } catch (\Throwable $e) {
            $claim->newQuery()->whereKey($claim->id)->whereNull('issued_at')->delete();

            throw $e instanceof TrafficShortException ? TestAccountRefusedException::because(TestRefusal::NoServer) : $e;
        }
    }

    /**
     * Claim a test under the lock, its quota judged again there.
     *
     * @throws TestAccountRefusedException
     */
    private function claim(User $user, Server $server, TestTerms $terms): TestAccount
    {
        return $this->db->transaction(function () use ($user, $server, $terms): TestAccount {
            // One judge at a time: every test of the shop while it caps a day, else the customer's own.
            if ($this->settings->dailyLimit() > 0) {
                $this->db->table('bots')->where('id', CurrentBot::get()->id)->lockForUpdate()->value('id');
            } else {
                $this->db->table('users')->where('id', $user->id)->lockForUpdate()->value('id');
            }
            $this->admit($user);

            return TestAccount::query()->create([
                'user_id' => $user->id,
                'server_id' => $server->id,
                'traffic_bytes' => $terms->bytes,
                'duration_hours' => $terms->hours,
                'on_hold' => $terms->onHold,
            ]);
        });
    }

    /**
     * Whether the customer may take a test now, by the switch and the quota.
     *
     * @throws TestAccountRefusedException
     */
    private function admit(User $user): void
    {
        if (!$this->settings->enabled()) {
            throw TestAccountRefusedException::because(TestRefusal::Off);
        }
        if ($user->isAdmin()) {
            return;
        }
        if ($this->settings->newcomersOnly() && Order::paidPurchases()->where('user_id', $user->id)->exists()) {
            throw TestAccountRefusedException::because(TestRefusal::NewcomersOnly);
        }

        $days = $this->settings->periodDays();
        $theirs = TestAccount::query()->where('user_id', $user->id)
            ->when($days > 0, static fn(Builder $tests) => $tests->where('created_at', '>', now()->subDays($days)));
        $taken = (clone $theirs)->count();
        $allowed = $this->settings->perUser();
        if ($taken >= $allowed) {
            // Free again once enough of them leave the window: the one that leaves last, a window after it was taken.
            $leaves = $days > 0 ? $theirs->orderBy('created_at')->orderBy('id')->skip($taken - $allowed)->value('created_at') : null;

            throw TestAccountRefusedException::used($leaves instanceof Carbon ? $leaves->copy()->addDays($days) : null);
        }

        $cap = $this->settings->dailyLimit();
        if ($cap > 0 && TestAccount::query()->where('created_at', '>=', self::today())->count() >= $cap) {
            throw TestAccountRefusedException::because(TestRefusal::DailyLimit);
        }
    }

    /** When today began on the shop's clock, in UTC. */
    private static function today(): Carbon
    {
        return Carbon::instance(LocalTime::of(now())->setTime(0, 0))->utc();
    }
}
