<?php

declare(strict_types=1);

namespace App\Modules\Rewards\Services;

use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\Payment;
use App\Modules\Rewards\DTO\Reward;
use App\Modules\Rewards\Enums\RewardKind;
use App\Modules\Rewards\Exceptions\RewardUnavailableException;
use App\Modules\Rewards\Models\LotteryDraw;
use App\Modules\Rewards\Models\LotteryWinner;
use App\Modules\Rewards\Support\WeightedDraw;
use App\Modules\Telegram\Reports\ShopReports;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\User;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * The nightly draw («قرعه‌کشی شبانه») of the current shop, once per day of the shop's clock, after the configured hour.
 * The day's row is inserted first (unique per shop and day), so two runs never draw twice. Entries are orders bought for
 * themselves at a price and paid since the draw before it (window()): one draw's window ends where the next one's begins,
 * so a late run, or the hour moved earlier or later, enters no order twice and skips none. Bot admins, banned customers
 * and (optionally) agents never enter. Winners are drawn by weight, each customer once; one who cannot be given the prize
 * is passed over, and a plan no server can deliver ends the draw.
 */
final class Lottery
{
    /** The wallet line a prize's Toman is, and the word a prize is given with. */
    public const REASON = 'جایزه قرعه‌کشی شبانه';

    /**
     * A draw's window ends this long before it is made: a payment settling at that moment — its time written a moment
     * before its commit — enters the next draw, never neither.
     */
    private const SETTLING_SECONDS = 60;

    /** The furthest back a window reaches: the draw before it — or, the shop's first, or after draws stopped a while, this. */
    private const WINDOW_MAX_HOURS = 48;

    /** A shop's first draw: the day before it. */
    private const FIRST_WINDOW_HOURS = 24;

    public function __construct(
        private readonly LotterySettings $settings,
        private readonly Rewards $rewards,
        private readonly CustomerNotifier $notifier,
        private readonly ShopReports $reports,
        private readonly LoggerInterface $logger,
    ) {}

    /** The current shop's draw of today, when it is due and nobody drew it yet; null otherwise. */
    public function drawIfDue(): ?LotteryDraw
    {
        if (!$this->settings->enabled()) {
            return null;
        }

        $now = LocalTime::of(now());
        $hour = $this->settings->hour();
        if ((int) $now->format('G') < $hour) {
            return null;
        }
        $day = $now->format('Y-m-d');
        if (LotteryDraw::query()->where('day', $day)->exists()) {
            return null;
        }

        return $this->draw($day);
    }

    /**
     * The entries of a draw whose window is `$since` (inclusive) to `$until` (exclusive), UTC, by customer: their orders
     * paid in it, or one per buyer.
     *
     * @return array<int, int>
     */
    public function entries(Carbon $since, Carbon $until): array
    {
        $paid = Payment::query()->select('order_id')->whereNotNull('paid_at')->where('paid_at', '>=', $since)->where('paid_at', '<', $until);
        $users = User::query()->select('id')
            ->where('role', '!=', UserRole::Admin->value)
            ->where('status', '!=', UserStatus::Banned->value)
            ->when($this->settings->excludesAgents(), static fn(Builder $query) => $query->whereNull('agency_level_id'));

        $orders = Order::spending()
            ->toBase()
            ->selectRaw('user_id, COUNT(*) AS entries')
            ->whereIn('id', $paid)
            ->where('amount', '>', 0)
            ->whereIn('user_id', $users)
            ->groupBy('user_id')
            ->orderBy('user_id')
            ->pluck('entries', 'user_id');

        $entries = [];
        foreach ($orders as $user => $count) {
            $entries[(int) $user] = $this->settings->perBuyer() ? 1 : (int) $count;
        }

        return $entries;
    }

    /**
     * The window of `$draw`, just made: from where the draw before it ended to SETTLING_SECONDS before this one — but never
     * further back than WINDOW_MAX_HOURS (all of them when the draw before has no time: written outside AmoBot); a
     * shop's first, FIRST_WINDOW_HOURS.
     *
     * @return array{Carbon, Carbon} From (inclusive), until (exclusive), UTC
     */
    private static function window(LotteryDraw $draw): array
    {
        $until = ($draw->created_at ?? now())->copy()->startOfSecond()->subSeconds(self::SETTLING_SECONDS)->utc();
        $before = LotteryDraw::query()->where('id', '<', $draw->id)->latest('id')->first();
        if ($before === null) {
            return [$until->copy()->subHours(self::FIRST_WINDOW_HOURS), $until];
        }
        $furthest = $until->copy()->subHours(self::WINDOW_MAX_HOURS);
        $since = $before->created_at?->copy()->subSeconds(self::SETTLING_SECONDS)->utc()->max($furthest);

        return [$since ?? $furthest, $until];
    }

    /** The draw of `$day`, made now; null when another run drew it first. */
    private function draw(string $day): ?LotteryDraw
    {
        $prize = $this->settings->prize();
        try {
            $draw = LotteryDraw::query()->create([
                'day' => $day,
                'entries' => 0,
                'prize_kind' => $prize->kind ?? RewardKind::Plan,
                'prize_amount' => $prize?->amount,
                'prize_plan_id' => $prize?->plan?->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        $entries = $this->entries(...self::window($draw));
        $draw->forceFill(['entries' => array_sum($entries)])->save();
        if ($entries === []) {
            $this->logger->info('The nightly draw of {day} had no entries', ['day' => $day]);

            return $draw;
        }
        if ($prize === null) {
            $this->logger->warning('The nightly draw of {day} gave nothing: its prize plan is not there any more', ['day' => $day]);
            $this->reports->lotteryDrawn($draw, []);

            return $draw;
        }

        $winners = $this->winners($draw, $prize, $entries);
        $this->reports->lotteryDrawn($draw, $winners);
        $this->logger->info('The nightly draw of {day}: {entries} entries, {winners} winners', ['day' => $day, 'entries' => $draw->entries, 'winners' => count($winners)]);

        return $draw;
    }

    /**
     * Draws winners by weight, each customer once, until enough were given the prize or nobody is left.
     *
     * @param array<int, int> $entries
     * @return list<LotteryWinner>
     */
    private function winners(LotteryDraw $draw, Reward $prize, array $entries): array
    {
        $customers = User::query()->whereIn('id', array_keys($entries))->get()->keyBy('id');
        $winners = [];
        while (count($winners) < $this->settings->winners() && $entries !== []) {
            $id = WeightedDraw::draw($entries);
            unset($entries[$id]);
            $customer = $customers->get($id);
            if ($customer === null) {
                continue;
            }

            try {
                $given = $this->rewards->give($customer, $prize, self::REASON);
            } catch (RewardUnavailableException $e) {
                if ($prize->kind === RewardKind::Plan) {
                    // No server delivers the plan today: nobody drawn next can be given it either.
                    $this->logger->warning('The nightly draw of {day} stopped: {reason}', ['day' => $draw->day, 'reason' => $e->getMessage()]);

                    break;
                }

                continue;
            } catch (\Throwable $e) {
                // The draw goes on with the next one drawn.
                $this->logger->log(Rewards::failureLevel($e), 'A winner of the nightly draw of {day} was passed over: {reason}', ['day' => $draw->day, 'user' => $id, 'reason' => $e->getMessage(), 'exception' => $e]);

                continue;
            }

            $winner = LotteryWinner::query()->create([
                'lottery_draw_id' => $draw->id,
                'user_id' => $customer->id,
                'outcome' => mb_substr($prize->words(), 0, 191),
                'order_id' => $given->order?->id,
                'subscription_id' => $given->subscription?->id,
            ]);
            $winner->setRelation('user', $customer);
            $this->notifier->lotteryWon($customer, $prize->words());
            $winners[] = $winner;
        }

        return $winners;
    }
}
