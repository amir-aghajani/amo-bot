<?php

declare(strict_types=1);

namespace App\Modules\Rewards\Services;

use App\Modules\Rewards\Models\LotteryDraw;
use App\Modules\Rewards\Models\LotteryWinner;
use App\Modules\Users\Models\User;
use App\Support\LocalTime;
use App\Support\Text;
use Illuminate\Support\Carbon;

/**
 * The nightly draw («قرعه‌کشی شبانه») as one customer finds it on the website and in the mini app: when the next one is
 * due, how many win and what, their entries in it so far — counted as the draw will count them (Lottery::entries()) —
 * and the last draw's winners, their first names alone. A handful of queries, whoever won.
 */
final class LotteryBoard
{
    /** A winner without a name worth showing. */
    public const NAMELESS = 'کاربر ***';

    public function __construct(
        private readonly LotterySettings $settings,
        private readonly Lottery $lottery,
    ) {}

    /**
     * The draw as `$customer` finds it; null while the shop runs none.
     *
     * @return array{next_draw_at: string, winners: int, prize: string|null, per_buyer: bool, entries: int, last_draw: array{drawn_at: string, winners: list<array{name: string, me: bool}>}|null}|null
     */
    public function for(User $customer): ?array
    {
        if (!$this->settings->enabled()) {
            return null;
        }

        $last = LotteryDraw::query()->with('winners.user')->latest('id')->first();
        $next = $this->nextDrawAt($last);

        return [
            'next_draw_at' => $next->toIso8601String(),
            'winners' => $this->settings->winners(),
            'prize' => $this->settings->prize()?->words(),
            'per_buyer' => $this->settings->perBuyer(),
            'entries' => $this->lottery->entries(Lottery::opens($next, $last), now(), $customer)[$customer->id] ?? 0,
            'last_draw' => $last === null ? null : [
                'drawn_at' => $this->drawnAt($last)->toIso8601String(),
                'winners' => array_values($last->winners->map(static fn(LotteryWinner $winner): array => [
                    'name' => self::masked($winner->user),
                    'me' => $winner->user_id === $customer->id,
                ])->all()),
            ],
        ];
    }

    /**
     * When the next draw is due: the shop's hour today on its clock (Lottery::drawIfDue()), until today's is drawn — its
     * day's row, one a day, is the newest draw then —, the hour tomorrow after that.
     */
    private function nextDrawAt(?LotteryDraw $last): Carbon
    {
        $today = LocalTime::of(now());
        $day = $last?->day === $today->format('Y-m-d') ? $today->modify('+1 day') : $today;

        return Carbon::instance($day->setTime($this->settings->hour(), 0))->utc();
    }

    /**
     * When `$draw` was drawn, UTC: its time — or, for a row written outside AmoBot that has none, the shop's hour on its
     * day (a day that reads as none: now), so the board always says one.
     */
    private function drawnAt(LotteryDraw $draw): Carbon
    {
        if ($draw->created_at !== null) {
            return $draw->created_at;
        }
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $draw->day, LocalTime::zone());

        return $day === false ? now() : Carbon::instance($day->setTime($this->settings->hour(), 0))->utc();
    }

    /**
     * «علی ر***»: the first word of their name, and the first letter of the next one; «علی ***» for one word alone, and
     * NAMELESS for a name that shows nothing.
     */
    private static function masked(User $winner): string
    {
        $name = $winner->name() ?? '';
        $words = Text::blank($name) ? false : preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || $words === []) {
            return self::NAMELESS;
        }

        return isset($words[1]) ? $words[0] . ' ' . mb_substr($words[1], 0, 1) . '***' : $words[0] . ' ***';
    }
}
