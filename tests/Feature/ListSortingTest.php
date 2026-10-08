<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database\Page;
use App\Modules\Bots\CurrentBot;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Referrals\Models\ReferralCommission;
use App\Modules\Users\Models\User;
use App\Support\Traffic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Tests\HttpTestCase;

/**
 * The paged lists the panels sort by a column's header, on the server: each of a list's keys both ways — the other
 * direction exactly the reverse, rows that tie in the order of their ids —, the list's own order when the request names
 * no key of it, and the order said in the meta (closed in the API description, which every answer here is checked
 * against). What a key orders by is what the row shows: a balance or a traffic with no ledger at all is 0.
 */
final class ListSortingTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAsAdmin();
    }

    public function testTheUsersSortByWhenTheyJoinedWereLastSeenTheirBalanceTheirOrdersAndTheirServices(): void
    {
        $server = $this->fakeServer();
        $plan = $this->plan();
        $one = $this->wallet($this->customer(['telegram_id' => 1, 'last_seen_at' => now()->subDays(2)]), '50000.00');
        $two = $this->customer(['telegram_id' => 2]);
        $three = $this->wallet($this->customer(['telegram_id' => 3, 'last_seen_at' => now()->subHour()]), '-20000.00');
        $four = $this->wallet($this->wallet($this->customer(['telegram_id' => 4, 'last_seen_at' => now()->subDays(5)]), '10000.00'), '0.00');
        $this->purchaseOrder($one, $plan, $server);
        foreach (range(1, 3) as $n) {
            $this->purchaseOrder($two, $plan, $server);
        }
        $this->subscription($two, $plan, $server, 'two_1');
        $this->subscription($three, $plan, $server, 'three_1');
        $this->subscription($three, $plan, $server, 'three_2');
        [$one, $two, $three, $four] = self::ids($one, $two, $three, $four);

        $this->assertOrders('/api/admin/users', 'users', 'joined', [$four, $three, $two, $one]);
        $this->assertOrders('/api/admin/users', 'users', 'last_seen', [$three, $one, $four, $two]);
        $this->assertOrders('/api/admin/users', 'users', 'balance', [$one, $four, $two, $three]);
        $this->assertOrders('/api/admin/users', 'users', 'orders', [$two, $one, $four, $three]);
        $this->assertOrders('/api/admin/users', 'users', 'services', [$three, $two, $four, $one]);
    }

    public function testTheOrdersAndThePaymentsSortByWhenAndByHowMuch(): void
    {
        $customer = $this->customer();
        $server = $this->fakeServer();
        $plan = $this->plan();
        $card = $this->cardMethod();
        $orders = $payments = [];
        foreach (['30000.00', '10000.00', '30000.00', '50000.00'] as $amount) {
            $orders[] = $order = $this->purchaseOrder($customer, $plan, $server, ['amount' => $amount]);
            $payments[] = $this->cardPayment($order, $card);
        }
        [$o1, $o2, $o3, $o4] = self::ids(...$orders);
        [$p1, $p2, $p3, $p4] = self::ids(...$payments);

        $this->assertOrders('/api/admin/orders', 'orders', 'created', [$o4, $o3, $o2, $o1]);
        $this->assertOrders('/api/admin/orders', 'orders', 'amount', [$o4, $o3, $o1, $o2]);
        $this->assertOrders('/api/admin/payments', 'payments', 'created', [$p4, $p3, $p2, $p1]);
        $this->assertOrders('/api/admin/payments', 'payments', 'amount', [$p4, $p3, $p1, $p2]);
        $this->assertOrders('/api/admin/orders?status=pending', 'orders', 'amount', [$o4, $o3, $o1, $o2]);
    }

    public function testTheSubscriptionsSortByWhenTheyEndAndByTheTrafficUsed(): void
    {
        $customer = $this->customer();
        $server = $this->fakeServer();
        $plan = $this->plan();
        $later = $this->subscription($customer, $plan, $server, 'later', ['expires_at' => now()->addDays(10), 'upload_bytes' => Traffic::bytesOfGb(2), 'download_bytes' => Traffic::bytesOfGb(3)]);
        $soon = $this->subscription($customer, $plan, $server, 'soon', ['expires_at' => now()->addDays(2), 'download_bytes' => Traffic::bytesOfGb(1)]);
        $waiting = $this->subscription($customer, $plan, $server, 'waiting', ['starts_at' => null, 'expires_at' => null]);
        $never = $this->subscription($customer, $plan, $server, 'never', ['duration_days' => 0, 'expires_at' => null, 'download_bytes' => Traffic::bytesOfGb(3)]);
        [$later, $soon, $waiting, $never] = self::ids($later, $soon, $waiting, $never);

        $this->assertOrders('/api/admin/subscriptions', 'subscriptions', 'created', [$never, $waiting, $soon, $later]);
        // No deadline yet, or none ever: after every service that has one, read soonest first.
        $this->assertOrders('/api/admin/subscriptions', 'subscriptions', 'expires', [$never, $waiting, $later, $soon]);
        $this->assertOrders('/api/admin/subscriptions', 'subscriptions', 'used', [$later, $never, $soon, $waiting]);
    }

    public function testTheReferralListsSortByTheirColumns(): void
    {
        $this->referralProgram();
        $ali = $this->customer(['telegram_id' => 1, 'username' => 'ali']);
        $reza = $this->customer(['telegram_id' => 2, 'username' => 'reza']);
        $mina = $this->customer(['telegram_id' => 3, 'username' => 'mina']);
        Carbon::setTestNow(now()->subDays(10));
        $m1 = $this->customer(['telegram_id' => 31, 'referred_by' => $mina->id]);
        $m2 = $this->customer(['telegram_id' => 32, 'referred_by' => $mina->id]);
        Carbon::setTestNow(now()->addDays(5));
        $a1 = $this->customer(['telegram_id' => 11, 'referred_by' => $ali->id]);
        $a2 = $this->customer(['telegram_id' => 12, 'referred_by' => $ali->id]);
        Carbon::setTestNow(now()->addDays(4));
        $r1 = $this->customer(['telegram_id' => 21, 'referred_by' => $reza->id]);
        Carbon::setTestNow();
        // 10% of each: 10,000 for ali, 30,000 for mina, then 5,000 for ali.
        $c1 = $this->paidByCard($this->topUpOrder($a1, '100000'))->id;
        $c2 = $this->paidByCard($this->topUpOrder($m1, '300000'))->id;
        $c3 = $this->paidByCard($this->topUpOrder($a2, '50000'))->id;
        [$ali, $reza, $mina, $m1, $m2, $a1, $a2, $r1] = self::ids($ali, $reza, $mina, $m1, $m2, $a1, $a2, $r1);
        $commissions = fn(int ...$payments): array => array_map(fn(int $payment): int => $this->commissionOf($payment), $payments);

        $this->assertOrders('/api/admin/referrals/referrers', 'referrers', 'referrals', [$mina, $ali, $reza]);
        $this->assertOrders('/api/admin/referrals/referrers', 'referrers', 'earned', [$mina, $ali, $reza]);
        $this->assertOrders('/api/admin/referrals/referrers', 'referrers', 'last_referral', [$reza, $ali, $mina]);
        $this->assertOrders('/api/admin/referrals/invitees', 'invitees', 'joined', [$r1, $a2, $a1, $m2, $m1]);
        $this->assertOrders('/api/admin/referrals/invitees', 'invitees', 'earned', [$m1, $a1, $a2, $r1, $m2]);
        $this->assertOrders('/api/admin/referrals/commissions', 'commissions', 'created', $commissions($c3, $c2, $c1));
        $this->assertOrders('/api/admin/referrals/commissions', 'commissions', 'commission', $commissions($c2, $c1, $c3));
    }

    public function testTheAgentsSortByTheirWalletTheirBotsTrafficAndWhatItSold(): void
    {
        $server = $this->fakeServer();
        // Created in this order: an agent whose bot's traffic came back to 0, one whose bot never had any, one selling.
        $back = $this->agent(overrides: ['telegram_id' => 1]);
        $fresh = $this->wallet($this->agent(overrides: ['telegram_id' => 2]), '30000.00');
        $seller = $this->wallet($this->agent(credit: '10000', overrides: ['telegram_id' => 3]), '-5000.00');
        $this->traffic($this->traffic($back->ownBot ?? self::fail('no shop'), 10), 0);
        $this->traffic($seller->ownBot ?? self::fail('no shop'), 50);
        foreach ([[$fresh, 2], [$seller, 1]] as [$agent, $sold]) {
            CurrentBot::run($agent->ownBot ?? self::fail('no shop'), function () use ($server, $sold): void {
                $customer = $this->customer(['telegram_id' => 99]);
                $plan = $this->plan([], $server);
                foreach (range(1, $sold) as $n) {
                    $this->purchaseOrder($customer, $plan, $server, ['status' => OrderStatus::Fulfilled]);
                }
                $this->purchaseOrder($customer, $plan, $server);
            });
        }
        [$back, $fresh, $seller] = self::ids($back, $fresh, $seller);

        $this->assertOrders('/api/admin/agency/agents', 'agents', 'joined', [$seller, $fresh, $back]);
        $this->assertOrders('/api/admin/agency/agents', 'agents', 'balance', [$fresh, $back, $seller]);
        $this->assertOrders('/api/admin/agency/agents', 'agents', 'traffic', [$seller, $fresh, $back]);
        $this->assertOrders('/api/admin/agency/agents', 'agents', 'sold', [$fresh, $seller, $back]);
    }

    public function testTheAgencyRequestsComeNewestOrOldestFirst(): void
    {
        $requests = array_map(fn(int $telegramId): int => $this->agencyRequest($this->customer(['telegram_id' => $telegramId]))->id, [1, 2, 3]);

        $this->assertOrders('/api/admin/agency/requests', 'requests', 'created', array_reverse($requests));
    }

    public function testAnOrderTheListDoesNotHaveIsTheListsOwn(): void
    {
        $ids = self::ids(...array_map(fn(int $n): User => $this->wallet($this->customer(['telegram_id' => $n]), (string) (10_000 * $n)), [3, 1, 2]));
        $newest = array_reverse($ids);
        $byBalance = [$ids[0], $ids[2], $ids[1]];

        $cases = [
            '' => [$newest, 'joined', 'desc'],
            '?sort=nonsense' => [$newest, 'joined', 'desc'],
            '?sort=created' => [$newest, 'joined', 'desc'],
            '?sort[]=balance' => [$newest, 'joined', 'desc'],
            '?sort=nonsense&dir=asc' => [$ids, 'joined', 'asc'],
            '?dir=sideways' => [$newest, 'joined', 'desc'],
            '?sort=balance&dir=up' => [$byBalance, 'balance', 'desc'],
        ];
        foreach ($cases as $query => [$rows, $sort, $dir]) {
            if ($query !== '') {
                $this->unchecked(); // an order or a direction no panel asks for
            }
            $data = $this->decode($this->get('/api/admin/users' . $query));
            self::assertSame($rows, array_column($data['users'], 'id'), $query);
            self::assertSame([$sort, $dir], [$data['meta']['sort'], $data['meta']['dir']], $query);
        }
    }

    public function testRowsThatTieKeepOnePlaceFromPageToPage(): void
    {
        $ids = array_map(fn(int $n): int => $this->customer(['telegram_id' => 1000 + $n])->id, range(1, Page::PER_PAGE + 5));

        // Nobody has a balance: every row ties, and the pages read every row once, in the order of their ids.
        foreach (['desc' => array_reverse($ids), 'asc' => $ids] as $dir => $expected) {
            $read = [];
            foreach ([1, 2] as $page) {
                $read = [...$read, ...array_column($this->decode($this->get("/api/admin/users?sort=balance&dir={$dir}&page={$page}"))['users'], 'id')];
            }
            self::assertSame($expected, $read, $dir);
        }
    }

    public function testAnAgentsPanelSortsTheListsOfItsOwnShop(): void
    {
        $bot = $this->agentBot();
        $ids = CurrentBot::run($bot, fn(): array => self::ids(
            $this->wallet($this->customer(['telegram_id' => 1]), '20000.00'),
            $this->customer(['telegram_id' => 2]),
            $this->wallet($this->customer(['telegram_id' => 3]), '40000.00'),
        ));
        $this->loginAsAgent($bot);

        $this->assertOrders('/api/agent/users', 'users', 'balance', [$ids[2], $ids[0], $ids[1]]);
    }

    /**
     * The list read in `$key`'s order both ways — `$descending`, then exactly its reverse —, the meta saying so; and the
     * key alone, descending.
     *
     * @param list<int> $descending
     */
    private function assertOrders(string $path, string $rows, string $key, array $descending): void
    {
        $glue = str_contains($path, '?') ? '&' : '?';
        foreach (['desc' => $descending, 'asc' => array_reverse($descending)] as $dir => $ids) {
            $data = $this->decode($this->get("{$path}{$glue}sort={$key}&dir={$dir}"));
            self::assertSame($ids, array_column($data[$rows], 'id'), "{$path} by {$key}, {$dir}");
            self::assertSame([$key, $dir], [$data['meta']['sort'], $data['meta']['dir']], "{$path} by {$key}, {$dir}");
        }
        self::assertSame($descending, array_column($this->decode($this->get("{$path}{$glue}sort={$key}"))[$rows], 'id'), "{$path} by {$key}: descending unless the request says otherwise");
    }

    /** @return list<int> */
    private static function ids(Model ...$rows): array
    {
        return array_map(static fn(Model $row): int => (int) $row->getKey(), array_values($rows));
    }

    /** The commission a payment earned its customer's referrer. */
    private function commissionOf(int $payment): int
    {
        return (int) ReferralCommission::query()->where('payment_id', $payment)->value('id');
    }
}
