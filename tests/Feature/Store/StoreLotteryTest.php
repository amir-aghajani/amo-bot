<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Providers\Models\Server;
use App\Modules\Rewards\Enums\RewardKind;
use App\Modules\Rewards\Models\LotteryDraw;
use App\Modules\Rewards\Models\LotteryWinner;
use App\Modules\Rewards\Services\Lottery;
use App\Modules\Store\Exceptions\ProgramOffException;
use App\Modules\Store\Models\Website;
use App\Modules\Users\Models\User;
use App\Support\LocalTime;
use Illuminate\Support\Carbon;
use Tests\HttpTestCase;

/**
 * The nightly draw as a customer finds it: when the next one is due — the shop's hour today on its clock until today's is
 * drawn, tomorrow's then —, how many win and what, their entries so far as the draw counts them (paid orders since the
 * last draw, one per buyer when so set, never agents left out or the bot's admins), and the last draw's winners by their
 * first names alone; a 404 while the shop runs none.
 */
final class StoreLotteryTest extends HttpTestCase
{
    private Website $website;

    private Server $server;

    private Plan $plan;

    private User $ali;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-10 20:00:00');
        $this->withoutQr();
        $this->telegram();
        $this->website = $this->website();
        $this->server = $this->sellingServer();
        $this->plan = $this->plan(['price' => '120000.00'], $this->server);
        $this->ali = $this->customer(['username' => 'ali']);
        $this->lotteryOn();
        $this->bearer($this->customerSession($this->ali));
    }

    protected function tearDown(): void
    {
        LocalTime::use('UTC');

        parent::tearDown();
    }

    public function testTheNextDrawItsPrizeAndTheirEntriesSoFar(): void
    {
        $this->entry($this->ali, '2026-10-10 12:00:00');
        $this->entry($this->ali, '2026-10-09 23:00:00');
        $this->entry($this->ali, '2026-10-09 21:00:00');
        $this->entry($this->ali, '2026-10-10 13:00:00', ['amount' => '0.00']);
        $this->purchaseOrder($this->ali, $this->plan, $this->server);
        $this->entry($this->customer(['telegram_id' => 7272]), '2026-10-10 12:00:00');

        self::assertSame([
            'next_draw_at' => '2026-10-10T22:00:00+00:00',
            'winners' => 1,
            'prize' => '۵۰٬۰۰۰ تومان شارژ کیف پول',
            'per_buyer' => false,
            'entries' => 2,
            'last_draw' => null,
        ], $this->board(), "the shop's first draw counts the day before it: neither an order before that, a free one nor one unpaid");

        $this->lotteryOn(['lottery_entries' => 'buyer', 'lottery_winners' => 3]);
        self::assertSame([3, true, 1], [$this->board()['winners'], $this->board()['per_buyer'], $this->board()['entries']], 'one per buyer');
    }

    public function testOnceTodaysIsDrawnTheNextIsTomorrowsAndTheEntriesStartAgain(): void
    {
        $this->entry($this->ali, '2026-10-10 12:00:00');
        Carbon::setTestNow('2026-10-10 22:30:00');
        self::assertSame(['2026-10-10T22:00:00+00:00', 1], [$this->board()['next_draw_at'], $this->board()['entries']], 'due and not drawn yet: the hour today');

        $this->service(Lottery::class)->drawIfDue() ?? self::fail('the draw was due');

        $board = $this->board();
        self::assertSame(['2026-10-11T22:00:00+00:00', 0], [$board['next_draw_at'], $board['entries']]);
        self::assertSame(['drawn_at' => '2026-10-10T22:30:00+00:00', 'winners' => [['name' => 'Ali ***', 'me' => true]]], $board['last_draw']);

        Carbon::setTestNow('2026-10-10 23:00:00');
        $this->entry($this->ali, '2026-10-10 22:40:00');
        self::assertSame(1, $this->board()['entries'], 'paid since the last draw');

        $this->bearer($this->customerSession($this->customer(['telegram_id' => 7272])));
        self::assertSame([['name' => 'Ali ***', 'me' => false]], $this->board()['last_draw']['winners'] ?? null);
    }

    public function testTheWinnersFirstNamesAlone(): void
    {
        $draw = LotteryDraw::query()->create(['day' => '2026-10-09', 'entries' => 4, 'prize_kind' => RewardKind::Wallet, 'prize_amount' => '50000']);
        $winners = [
            $this->customer(['telegram_id' => 7001, 'first_name' => 'Ali', 'last_name' => 'Rezaei']),
            $this->customer(['telegram_id' => 7002, 'first_name' => 'علی', 'last_name' => 'رضایی']),
            $this->customer(['telegram_id' => 7003, 'first_name' => null]),
            $this->customer(['telegram_id' => 7004, 'first_name' => "\u{3164}"]),
            $this->ali,
        ];
        foreach ($winners as $winner) {
            LotteryWinner::query()->create(['lottery_draw_id' => $draw->id, 'user_id' => $winner->id, 'outcome' => '۵۰٬۰۰۰ تومان شارژ کیف پول']);
        }

        self::assertSame([
            ['name' => 'Ali R***', 'me' => false],
            ['name' => 'علی ر***', 'me' => false],
            ['name' => 'کاربر ***', 'me' => false],
            ['name' => 'کاربر ***', 'me' => false],
            ['name' => 'Ali ***', 'me' => true],
        ], $this->board()['last_draw']['winners'] ?? null, 'in the order they were drawn');
    }

    public function testAgentsLeftOutAndTheBotsAdminsHaveNoEntries(): void
    {
        $agent = $this->agent(overrides: ['telegram_id' => 7005]);
        $this->entry($agent, '2026-10-10 12:00:00');
        $this->bearer($this->customerSession($agent));
        self::assertSame(0, $this->board()['entries'], 'agents left out');
        $this->lotteryOn(['lottery_exclude_agents' => false]);
        self::assertSame(1, $this->board()['entries']);

        $admin = $this->admin(['telegram_id' => 7006]);
        $this->entry($admin, '2026-10-10 12:00:00');
        $this->bearer($this->customerSession($admin));
        self::assertSame(0, $this->board()['entries'], 'never the bot\'s admins');
    }

    public function testTheHourIsOnTheShopsClock(): void
    {
        LocalTime::use('Asia/Tehran');
        Carbon::setTestNow('2026-10-10 17:00:00');
        self::assertSame('2026-10-10T18:30:00+00:00', $this->board()['next_draw_at'], '22:00 in Tehran');

        LotteryDraw::query()->create(['day' => '2026-10-10', 'entries' => 0, 'prize_kind' => RewardKind::Wallet, 'prize_amount' => '50000']);
        self::assertSame('2026-10-11T18:30:00+00:00', $this->board()['next_draw_at'], "today's drawn");

        Carbon::setTestNow('2026-10-10 21:00:00');
        self::assertSame('2026-10-11T18:30:00+00:00', $this->board()['next_draw_at'], 'half past midnight in Tehran: a new day, its draw to come');
    }

    public function testADrawWithoutATimeWasDrawnAtTheShopsHourOnItsDay(): void
    {
        LocalTime::use('Asia/Tehran');
        $draw = LotteryDraw::query()->create(['day' => '2026-10-09', 'entries' => 0, 'prize_kind' => RewardKind::Wallet, 'prize_amount' => '50000']);
        LotteryDraw::query()->whereKey($draw->id)->update(['created_at' => null]);

        self::assertSame(['drawn_at' => '2026-10-09T18:30:00+00:00', 'winners' => []], $this->board()['last_draw'], 'written outside AmoBot without a time: 22:00 in Tehran that day, never none');
    }

    public function testAPrizePlanDeletedSinceIsNoPrize(): void
    {
        $gift = $this->plan(['name' => 'هدیه'], $this->server);
        $this->lotteryOn(['lottery_prize' => 'plan', 'lottery_plan_id' => $gift->id]);
        self::assertSame('سرویس «هدیه»', $this->board()['prize']);

        $gift->delete();
        self::assertNull($this->board()['prize']);
    }

    public function testNoneWhileTheShopRunsNone(): void
    {
        $this->lotteryOn(['lottery_enabled' => false]);

        $response = $this->get($this->storeApi($this->website, '/lottery'));

        self::assertSame([404, ProgramOffException::LOTTERY], [$response->getStatusCode(), $this->decode($response)['message']]);
    }

    public function testSignedInOnly(): void
    {
        $this->bearer(null);

        self::assertSame(401, $this->unchecked()->get($this->storeApi($this->website, '/lottery'))->getStatusCode());
    }

    /** @return array<string, mixed> `GET /lottery` as the customer signed in reads it */
    private function board(): array
    {
        $response = $this->get($this->storeApi($this->website, '/lottery'));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->decode($response);
    }

    /**
     * A purchase the customer paid at `$paidAt` (UTC) and was delivered — `$overrides` the order's columns.
     *
     * @param array<string, mixed> $overrides
     */
    private function entry(User $user, string $paidAt, array $overrides = []): void
    {
        $order = $this->purchaseOrder($user, $this->plan, $this->server, $overrides + ['status' => OrderStatus::Fulfilled, 'fulfilled_at' => $paidAt]);
        $this->cardPayment($order, $this->cardMethod(), ['status' => PaymentStatus::Paid, 'paid_at' => $paidAt]);
    }
}
