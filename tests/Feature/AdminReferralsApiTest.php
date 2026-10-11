<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Accounts\Services\AccountMerger;
use App\Modules\Auth\Actor;
use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Bots\CurrentBot;
use App\Modules\Referrals\Enums\AlertReason;
use App\Modules\Referrals\Models\ReferralAlert;
use App\Modules\Referrals\Services\ReferralAlerts;
use App\Modules\Referrals\Services\ReferralService;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Tests\HttpTestCase;

/**
 * The referrals page: the program's rules and numbers, the referrers, invitees and commissions lists (searched on either
 * side), and the fake-referral alerts support clears. A commission stays with whoever earned it after an account merge.
 */
final class AdminReferralsApiTest extends HttpTestCase
{
    private User $ali;
    private User $sara;
    private User $reza;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAsAdmin();
        $this->referralProgram();
        $this->ali = $this->customer(['username' => 'ali']);
        $this->sara = $this->customer(['telegram_id' => 900_001, 'first_name' => 'Sara', 'username' => 'sara', 'referred_by' => $this->ali->id]);
        $this->customer(['telegram_id' => 900_002, 'first_name' => 'Nima', 'username' => 'nima', 'referred_by' => $this->ali->id]);
        $this->reza = $this->customer(['telegram_id' => 900_003, 'first_name' => 'Reza', 'username' => 'reza']);
        $this->customer(['telegram_id' => 900_004, 'first_name' => 'Mina', 'username' => 'mina', 'referred_by' => $this->reza->id]);
    }

    public function testTheSummaryHasTheRulesAndTheNumbers(): void
    {
        $this->paidByCard($this->topUpOrder($this->sara, '100000'));

        $summary = $this->decode($this->get('/api/admin/referrals'))['summary'];

        self::assertSame(['enabled' => true, 'rate' => 10, 'renewal_rate' => 10, 'first_only' => false, 'referrers' => 2, 'referred' => 3, 'commissions' => 1, 'paid' => '10000.00', 'alerts' => 0], $summary);
    }

    public function testTheReferrersComeMostReferralsFirstWithWhatTheyEarned(): void
    {
        $this->paidByCard($this->topUpOrder($this->sara, '100000'));

        $response = $this->get('/api/admin/referrals/referrers');

        self::assertSame(200, $response->getStatusCode());
        $rows = $this->decode($response)['referrers'];
        self::assertSame(['ali', 'reza'], array_map(static fn(array $row): ?string => $row['user']['username'], $rows));
        self::assertSame([2, '10000.00', 'active'], [$rows[0]['referrals'], $rows[0]['earned'], $rows[0]['status']]);
        self::assertSame([1, '0.00'], [$rows[1]['referrals'], $rows[1]['earned']]);
        self::assertNotNull($rows[0]['last_referral_at']);

        $found = $this->decode($this->get('/api/admin/referrals/referrers?search=%40reza'))['referrers'];
        self::assertSame(['reza'], array_map(static fn(array $row): ?string => $row['user']['username'], $found));
    }

    public function testTheInviteesSayWhoBroughtThemAndWhatTheyEarnedThem(): void
    {
        $this->paidByCard($this->topUpOrder($this->sara, '100000'));

        $rows = $this->decode($this->get('/api/admin/referrals/invitees'))['invitees'];

        self::assertSame(['mina', 'nima', 'sara'], array_map(static fn(array $row): ?string => $row['user']['username'], $rows), 'newest first');
        self::assertSame(['reza', 'ali', 'ali'], array_map(static fn(array $row): ?string => $row['referrer']['username'], $rows));
        self::assertSame(['0.00', '0.00', '10000.00'], array_column($rows, 'earned'));

        $byReferrer = $this->decode($this->get('/api/admin/referrals/invitees?search=ali'))['invitees'];
        self::assertSame(['nima', 'sara'], array_map(static fn(array $row): ?string => $row['user']['username'], $byReferrer), 'the referrer\'s name finds the ones they brought');
    }

    public function testOneReferrersInviteesAreTheOnesTheirLinkBroughtNeverThemselves(): void
    {
        // Sara came through Ali's link and brought Omid through hers: she is one of Ali's invitees, not one of her own.
        $this->customer(['telegram_id' => 900_005, 'username' => 'omid', 'referred_by' => $this->sara->id]);
        $invitees = fn(string $query): array => array_map(static fn(array $row): ?string => $row['user']['username'], $this->decode($this->get("/api/admin/referrals/invitees?{$query}"))['invitees']);

        self::assertSame(['omid'], $invitees("referrer={$this->sara->id}"));
        self::assertSame(['omid', 'sara'], $invitees('search=%40sara'), 'a search by her handle finds her own row too');
        self::assertSame(['nima', 'sara'], $invitees("referrer={$this->ali->id}"));
        self::assertSame([], $invitees("referrer={$this->sara->id}&search=nima"), 'with the search');
        $this->unchecked();
        self::assertCount(4, $invitees('referrer=sara'), 'what is no id (no panel sends one) is no filter');
    }

    public function testTheCommissionsSayWhoEarnedWhatFromWhosePayment(): void
    {
        $payment = $this->paidByCard($this->topUpOrder($this->sara, '100000'));
        $this->paidByCard($this->topUpOrder($this->customer(['telegram_id' => 900_005, 'username' => 'omid', 'referred_by' => $this->reza->id]), '50000'));

        $rows = $this->decode($this->get('/api/admin/referrals/commissions'))['commissions'];

        self::assertCount(2, $rows);
        self::assertSame(['reza', 'omid', '50000.00', 10, '5000.00'], [$rows[0]['referrer']['username'], $rows[0]['customer']['username'], $rows[0]['payment']['amount'], $rows[0]['rate'], $rows[0]['commission']]);
        self::assertSame('wallet_topup', $rows[0]['order']['type']);

        $byPayment = $this->decode($this->get('/api/admin/referrals/commissions?search=%23' . $payment->id))['commissions'];
        self::assertSame([$payment->id], array_map(static fn(array $row): int => $row['payment']['id'], $byPayment));
        $byCustomer = $this->decode($this->get('/api/admin/referrals/commissions?search=Sara'))['commissions'];
        self::assertSame(['sara'], array_map(static fn(array $row): ?string => $row['customer']['username'], $byCustomer));
    }

    public function testWhatWasEarnedStaysItsReferrersThroughAMerge(): void
    {
        // Sara came through Ali's link and paid: Ali earned it. Then her account is merged into an older one of hers, which
        // Reza's link brought: what she paid stays Ali's earning, and earns Reza nothing.
        $this->paidByCard($this->topUpOrder($this->sara, '100000'));
        Carbon::setTestNow(now()->subYear());
        $older = $this->webCustomer(['referred_by' => $this->reza->id]);
        Carbon::setTestNow();
        $survivor = $this->service(AccountMerger::class)->merge($this->sara, $older, AccountMerger::BY_CUSTOMER);
        self::assertSame([$older->id, $this->reza->id], [$survivor->id, $survivor->referred_by]);

        $referrals = $this->service(ReferralService::class);
        self::assertSame('10000.00', $referrals->statsFor($this->ali)['earned'], 'their own numbers — the bot, the website, their page — keep it');
        self::assertSame('0.00', $referrals->statsFor($this->reza)['earned']);

        $commission = $this->decode($this->get('/api/admin/referrals/commissions'))['commissions'][0];
        self::assertSame(['ali', $older->id], [$commission['referrer']['username'], $commission['customer']['id']], 'credited to Ali, paid by the account that stays');
        $referrers = $this->decode($this->get('/api/admin/referrals/referrers'))['referrers'];
        self::assertSame(['reza' => '0.00', 'ali' => '10000.00'], array_column(array_map(static fn(array $row): array => ['who' => $row['user']['username'], 'earned' => $row['earned']], $referrers), 'earned', 'who'));
        $invitees = $this->decode($this->get("/api/admin/referrals/invitees?referrer={$this->reza->id}"))['invitees'];
        self::assertSame([$older->id => '0.00'], array_column(array_filter($invitees, static fn(array $row): bool => $row['id'] === $older->id), 'earned', 'id'), 'her payments earned Reza, her referrer now, nothing');
    }

    public function testTheAlertsAreListedNewestFirstMarkedOnTheReferrersAndSupportClearsThem(): void
    {
        $burst = $this->referralAlert($this->ali);
        Carbon::setTestNow(now()->addMinute());
        $this->referralAlert($this->reza, ['reason' => AlertReason::Idle, 'count' => 20]);

        $rows = $this->decode($this->get('/api/admin/referrals/alerts'))['alerts'];
        self::assertSame([['reza', 'idle', 20], ['ali', 'burst', 10]], array_map(static fn(array $row): array => [$row['referrer']['username'], $row['reason'], $row['count']], $rows), 'newest first');
        self::assertSame(['ali'], array_map(static fn(array $row): ?string => $row['referrer']['username'], $this->decode($this->get('/api/admin/referrals/alerts?search=%40ali'))['alerts']));
        self::assertSame(['ali' => 1, 'reza' => 1], $this->alertsOnTheReferrers());
        self::assertSame(2, $this->decode($this->get('/api/admin/referrals'))['summary']['alerts']);

        self::assertSame(204, $this->postJson("/api/admin/referrals/alerts/{$burst->id}/clear")->getStatusCode());
        self::assertFalse(ReferralAlert::query()->whereKey($burst->id)->exists(), 'cleared: gone');
        self::assertSame(['ali' => 0, 'reza' => 1], $this->alertsOnTheReferrers());
        self::assertSame(1, $this->decode($this->get('/api/admin/referrals'))['summary']['alerts']);
        self::assertSame(404, $this->postJson("/api/admin/referrals/alerts/{$burst->id}/clear")->getStatusCode(), 'once');
    }

    public function testOneOfTheShopsAdminsNeverClearsAnAlertOnThemselves(): void
    {
        $alert = $this->referralAlert($this->ali);

        try {
            $this->service(ReferralAlerts::class)->clear($alert, Actor::groupAdmin($this->ali));
            self::fail('cleared on themselves');
        } catch (ActorRefusedException $e) {
            self::assertSame([403, ActorRefusedException::OWN_ALERT], [$e->status(), $e->getMessage()]);
        }
        $this->service(ReferralAlerts::class)->clear($alert, Actor::groupAdmin($this->reza));
        self::assertSame(0, ReferralAlert::query()->count(), "another admin's to clear");
    }

    public function testAnAgentsShopHasItsOwnAlerts(): void
    {
        $bot = $this->agentBot();
        $alert = CurrentBot::run($bot, function (): ReferralAlert {
            $referrer = $this->customer(['telegram_id' => 900_010, 'username' => 'agents_referrer']);

            return $this->referralAlert($referrer, ['reason' => AlertReason::Idle, 'count' => 20]);
        });

        self::assertSame([], $this->decode($this->get('/api/admin/referrals/alerts'))['alerts'], "the main shop's list");
        self::assertSame(404, $this->postJson("/api/admin/referrals/alerts/{$alert->id}/clear")->getStatusCode(), "another shop's alert is none of this one's");

        $this->loginAsAgent($bot);
        self::assertSame([$alert->id], array_column($this->decode($this->get('/api/agent/referrals/alerts'))['alerts'], 'id'));
        self::assertSame(204, $this->postJson("/api/agent/referrals/alerts/{$alert->id}/clear")->getStatusCode(), 'the agent clears their own');
    }

    /** @return array<string, int> The referrers list's alerts, by the referrer's handle. */
    private function alertsOnTheReferrers(): array
    {
        $rows = $this->decode($this->get('/api/admin/referrals/referrers'))['referrers'];

        return array_column(array_map(static fn(array $row): array => ['who' => $row['user']['username'], 'alerts' => $row['alerts']], $rows), 'alerts', 'who');
    }
}
