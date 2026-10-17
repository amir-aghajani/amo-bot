<?php

declare(strict_types=1);

namespace App\Modules\Agency\Services;

use App\Core\Exceptions\ValidationException;
use App\Modules\Agency\DTO\SellingTerms;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Enums\SellingSuspension;
use App\Modules\Bots\Models\Bot;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Telegram\Reports\ShopReports;
use App\Support\JalaliMonth;
use App\Support\LocalTime;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * An agent's terms of selling, beside their level and credit: when their agency ends (`bots.agency_expires_at`) and the
 * Toman of traffic they buy each Jalali month at least (`monthly_minimum`) — given on approval (the program's, or the
 * owner's), changed on the agents page. Each is warned of once, the program's warnDays() ahead, and then suspends the
 * shop's SELLING, never its bot (Bot::status()): purchases, renewals, extras, wallet top-ups, free tests and gifts of a
 * service are refused (Bot::sells()) while the bot, the panel, the services sold — their sync, reminders and
 * clean-up — and the delivery of what was paid before go on, and the agent still buys traffic in the main bot. The
 * agent is told each step, the owner's report group too.
 *
 * - An end counts the moment it passes (Bot::sellingSuspension()); the hourly task (Tasks\AgencyStandingTask) writes it
 *   down and tells. A later end (or none) lifts it; the owner's «ادامه فروش» does not while it has passed.
 * - A minimum is judged once a Jalali month is over on the shop's clock: the agent's fulfilled traffic orders of that
 *   month in the main shop, refunds aside (purchased()). The month it was set or changed in is not judged. A shop
 *   suspended for it sells again as soon as the month it is in reaches the minimum — the traffic's delivery asks
 *   (bought()), the task too —, or by the owner's hand; none at all lifts it too.
 * - While a passed end holds the shop, last month's minimum waits to be judged — an end that passes over a minimum's
 *   suspension leaves last month to be judged again —, and lifting the end judges it: a month short with this one short
 *   too keeps the shop suspended, for the minimum (liftEnd()).
 * - The owner suspends by hand (manual), and only the owner lifts it.
 *
 * One reason stands at a time: the owner's over an end's over a minimum's. Every move is a conditional write, so two
 * runs, or a run and the owner, warn and tell once.
 */
final class AgencyStanding
{
    public const EXTEND_FIRST = 'تاریخ نمایندگی این نماینده گذشته است؛ اول آن را تمدید کنید.';
    public const NOT_SUSPENDED = 'فروش این نماینده متوقف نیست.';
    public const NO_SHOP = 'این نماینده هنوز فروشگاهی ندارد.';

    /** The alias purchasedColumn() reads a month's purchases under. */
    public const PURCHASED = 'month_purchased';

    public function __construct(
        private readonly AgencySettings $settings,
        private readonly CustomerNotifier $notifier,
        private readonly ShopReports $reports,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * The program's terms for an agent approved now: its term counted from now — to the hour on the shop's clock, as the
     * agents page keeps an end, so saving their row unchanged changes nothing —, its minimum.
     */
    public function defaults(): SellingTerms
    {
        $days = $this->settings->termDays();
        $end = $days === null ? null : LocalTime::of(Carbon::now()->addDays($days));

        return new SellingTerms($end === null ? null : Carbon::instance($end->setTime((int) $end->format('G'), 0))->utc(), $this->settings->monthlyMinimum());
    }

    /**
     * Starts an approved agent's terms afresh — a returning agent's suspension and warnings gone —, in the caller's
     * transaction. The month of approval is not judged against the minimum.
     */
    public function begin(Bot $bot, SellingTerms $terms): void
    {
        $bot->forceFill([
            'agency_expires_at' => $terms->expiresAt,
            'expiry_warned_at' => null,
            'monthly_minimum' => $terms->minimum,
            'minimum_checked_month' => $terms->minimum === null ? null : JalaliMonth::now()->key(),
            'minimum_warned_month' => null,
            'selling_suspended_at' => null,
            'suspension_reason' => null,
        ])->save();
    }

    /**
     * Gives the agent's shop new terms (the owner's agents page); false when they are the ones it has. A new end is warned
     * of afresh, and lifts a suspension for an end passed when it lies ahead (or there is none) — but for last month's
     * minimum, judged then (liftEnd()); a new or changed minimum is first judged on the next whole month, and none lifts a
     * suspension for one. What the new terms call for now — an end already passed or near — is done at once (judge()).
     */
    public function change(Bot $bot, SellingTerms $terms): bool
    {
        $kept = SellingTerms::of($bot);
        if ($terms->same($kept)) {
            return false;
        }

        $changes = [];
        if (!(new SellingTerms($terms->expiresAt, null))->same(new SellingTerms($kept->expiresAt, null))) {
            $changes += ['agency_expires_at' => $terms->expiresAt, 'expiry_warned_at' => null];
        }
        if (!(new SellingTerms(null, $terms->minimum))->same(new SellingTerms(null, $kept->minimum))) {
            $changes += ['monthly_minimum' => $terms->minimum, 'minimum_checked_month' => $terms->minimum === null ? null : JalaliMonth::now()->key(), 'minimum_warned_month' => null];
        }
        $bot->forceFill($changes)->save();

        if ($terms->expiresAt === null || $terms->expiresAt->isFuture()) {
            $this->liftEnd($bot, Carbon::now());
        }
        if ($terms->minimum === null) {
            $this->lift($bot, SellingSuspension::Minimum);
        }
        $this->judge($bot->refresh(), Carbon::now());

        return true;
    }

    /**
     * The owner's «توقف فروش»: the shop sells nothing new until the owner lets it — whatever else suspended it before.
     *
     * @throws ValidationException 422 on `status` while it is suspended by hand already
     */
    public function suspendByHand(Bot $bot, ?string $note): void
    {
        if (!$this->suspend($bot, SellingSuspension::Manual, $note)) {
            throw ValidationException::on('status', 'فروش این نماینده همین حالا به دست شما متوقف است.');
        }
    }

    /**
     * The owner's «ادامه فروش»: a suspension lifted, whatever its reason — but an end that has passed, which is extended
     * instead. A month a minimum suspended it for stays judged.
     *
     * @throws ValidationException 422 on `status`: its end passed, or it is not suspended
     */
    public function resume(Bot $bot): void
    {
        if ($bot->agency_expires_at !== null && !$bot->agency_expires_at->isFuture()) {
            throw ValidationException::on('status', self::EXTEND_FIRST);
        }
        if (!$this->lift($bot)) {
            throw ValidationException::on('status', self::NOT_SUSPENDED);
        }
    }

    /**
     * The hourly review of one agent's shop (Tasks\AgencyStandingTask): an end passed is written down, one near warned of
     * once; a month over below the minimum suspends — unless this month's purchases reached it already, and while a passed
     * end holds the shop it waits for the end to lift (liftEnd()) —, this month's purchases reaching it lift that, and a
     * month near its end below it is warned of once. Nothing for a shop whose agency ended.
     */
    public function judge(Bot $bot, Carbon $now): void
    {
        $agent = $bot->agent;
        if ($bot->isMain() || $agent === null || !$agent->isAgent()) {
            return;
        }
        $warn = $this->settings->warnDays();
        $month = JalaliMonth::of($now);
        $last = $month->previous();

        $expires = $bot->agency_expires_at;
        if ($expires !== null && $expires->lessThanOrEqualTo($now)) {
            // Over a minimum's suspension, last month is left to be judged again once the end lifts.
            $this->suspend($bot, SellingSuspension::Expired, replacing: ['minimum_checked_month' => $last->previous()->key()]);
        } elseif ($expires !== null && $expires->copy()->subDays($warn)->lessThanOrEqualTo($now) && $bot->expiry_warned_at === null
            && Bot::query()->whereKey($bot->id)->whereNull('expiry_warned_at')->update(['expiry_warned_at' => $now]) === 1) {
            $this->notifier->agencyExpiring($agent, $expires);
        }

        $minimum = $bot->monthly_minimum;
        if ($minimum === null || !Money::isPositive($minimum)) {
            return;
        }
        $checked = $bot->minimum_checked_month;
        $purchased = $this->purchased($bot, $month);
        $short = Money::compare($purchased, $minimum) < 0;
        if ($checked === null) {
            // Never judged: from the next month on.
            Bot::query()->whereKey($bot->id)->whereNull('minimum_checked_month')->update(['minimum_checked_month' => $month->key()]);
            $checked = $month->key();
        } elseif (strcmp($checked, $last->key()) < 0 && $bot->suspension_reason !== SellingSuspension::Expired
            && Bot::query()->whereKey($bot->id)->where('minimum_checked_month', $checked)->update(['minimum_checked_month' => $last->key()]) === 1) {
            $checked = $last->key();
            // A month short suspends — unless the one it is in reached the minimum already, which would lift it at once.
            if ($short && Money::compare($this->purchased($bot, $last), $minimum) < 0) {
                $this->suspend($bot, SellingSuspension::Minimum);
            }
        }

        $bot->refresh();
        if ($bot->suspension_reason === SellingSuspension::Minimum) {
            if (!$short) {
                $this->lift($bot, SellingSuspension::Minimum);
            }

            return;
        }
        // Warned once a month that will be judged, while the shop still sells.
        if ($short && $checked !== $month->key() && $bot->minimum_warned_month !== $month->key() && $bot->sells()
            && $month->daysLeft($now) <= $warn
            && Bot::query()->whereKey($bot->id)->where(static fn(Builder $row) => $row->whereNull('minimum_warned_month')->orWhere('minimum_warned_month', '!=', $month->key()))->update(['minimum_warned_month' => $month->key()]) === 1) {
            $this->notifier->agencyMinimumShort($agent, $month, $purchased, Money::normalize($minimum), $month->daysLeft($now));
        }
    }

    /**
     * After an agent's traffic order was delivered: a shop suspended for its minimum sells again once this month's
     * purchases reach it — the shop read as it is now, not as the order's delivery found it (the hourly task may have
     * suspended it meanwhile). Never throws — the order is delivered whatever this says.
     */
    public function bought(Order $order): void
    {
        try {
            $bot = $order->type === OrderType::Traffic ? $order->user->ownBot()->first() : null;
            if ($bot === null || $bot->suspension_reason !== SellingSuspension::Minimum || $bot->monthly_minimum === null) {
                return;
            }
            if (Money::compare($this->purchased($bot, JalaliMonth::now()), $bot->monthly_minimum) >= 0) {
                $this->lift($bot, SellingSuspension::Minimum);
            }
        } catch (\Throwable $e) {
            $this->logger->error('The monthly minimum of order {id} was not judged: {message}', ['id' => $order->id, 'message' => $e->getMessage(), 'exception' => $e]);
        }
    }

    /** The Toman of traffic the shop's agent bought in the month: their traffic orders delivered then, refunds aside. */
    public function purchased(Bot $bot, JalaliMonth $month): string
    {
        if ($bot->user_id === null) {
            return Money::normalize(0);
        }

        return Money::normalize((string) self::purchases($month)->where('user_id', $bot->user_id)->sum('amount'));
    }

    /**
     * A list of agents' `addSelect()` reading each one's purchases of the month (purchased()) with their row, under
     * PURCHASED: one subquery instead of a query per agent.
     *
     * @return array<string, Builder<Order>>
     */
    public static function purchasedColumn(JalaliMonth $month): array
    {
        return [self::PURCHASED => self::purchases($month)->selectRaw('coalesce(sum(amount), 0)')->whereColumn('orders.user_id', 'users.id')];
    }

    /**
     * The agent's terms of selling as the panels show them: the end, the minimum with this month's purchases — and whether
     * this month is judged against it —, the suspension and why, and the days ahead each is warned. `$purchased` is the
     * month's purchases when a list read them with the row (purchasedColumn()).
     *
     * @return array{expires_at: string|null, monthly_minimum: string|null, month: array{name: string, purchased: string, ends_at: string, judged: bool}, suspension: array{reason: string, since: string|null}|null, warn_days: int}
     */
    public function present(Bot $bot, mixed $purchased = null): array
    {
        $month = JalaliMonth::now();
        $suspension = $bot->sellingSuspension();

        return [
            'expires_at' => $bot->agency_expires_at?->toIso8601String(),
            'monthly_minimum' => $bot->monthly_minimum === null ? null : Money::normalize($bot->monthly_minimum),
            'month' => [
                'name' => $month->name(),
                'purchased' => is_numeric($purchased) ? Money::normalize((string) $purchased) : $this->purchased($bot, $month),
                'ends_at' => $month->end->toIso8601String(),
                'judged' => $bot->monthly_minimum !== null && $bot->minimum_checked_month !== $month->key(),
            ],
            'suspension' => $suspension === null ? null : [
                'reason' => $suspension->value,
                'since' => ($bot->selling_suspended_at ?? $bot->agency_expires_at)?->toIso8601String(),
            ],
            'warn_days' => $this->settings->warnDays(),
        ];
    }

    /**
     * Suspends the shop's selling for `$reason`, over a weaker reason that stands (the owner's over an end's over a
     * minimum's) — writing `$replacing` with it then; true when this call did. The agent is told — with the owner's
     * note, by hand —, and the report group.
     *
     * @param array<string, string> $replacing
     */
    private function suspend(Bot $bot, SellingSuspension $reason, ?string $note = null, array $replacing = []): bool
    {
        $over = match ($reason) {
            SellingSuspension::Manual => [SellingSuspension::Expired->value, SellingSuspension::Minimum->value],
            SellingSuspension::Expired => [SellingSuspension::Minimum->value],
            SellingSuspension::Minimum => [],
        };
        $suspended = Bot::query()->whereKey($bot->id)->whereNull('selling_suspended_at')->update(['selling_suspended_at' => Carbon::now(), 'suspension_reason' => $reason->value]) === 1
            || ($over !== [] && Bot::query()->whereKey($bot->id)->whereIn('suspension_reason', $over)->update(['suspension_reason' => $reason->value] + $replacing) === 1);
        if (!$suspended) {
            return false;
        }

        $this->suspended($bot, $reason, $note);

        return true;
    }

    /**
     * An end passed is lifted (change()): its suspension goes — unless last month's minimum, waiting while the end held
     * the shop (judge()), fell short with this month short of it too: then, in one conditional write, the shop stays
     * suspended for the minimum, last month judged, and the agent and the report group are told so.
     */
    private function liftEnd(Bot $bot, Carbon $now): void
    {
        $bot->refresh();
        $month = JalaliMonth::of($now);
        $last = $month->previous();
        $minimum = $bot->monthly_minimum;
        $checked = $bot->minimum_checked_month;
        $due = $bot->suspension_reason === SellingSuspension::Expired
            && $minimum !== null && Money::isPositive($minimum)
            && $checked !== null && strcmp($checked, $last->key()) < 0
            && Money::compare($this->purchased($bot, $last), $minimum) < 0
            && Money::compare($this->purchased($bot, $month), $minimum) < 0;
        if (!$due) {
            $this->lift($bot, SellingSuspension::Expired);

            return;
        }

        $kept = Bot::query()->whereKey($bot->id)->where('suspension_reason', SellingSuspension::Expired->value)
            ->update(['suspension_reason' => SellingSuspension::Minimum->value, 'minimum_checked_month' => $last->key()]) === 1;
        if ($kept) {
            $this->suspended($bot, SellingSuspension::Minimum, null);
        }
    }

    /** The shop was just suspended for `$reason`: logged, the agent told (with the owner's note), the report group too. */
    private function suspended(Bot $bot, SellingSuspension $reason, ?string $note): void
    {
        $bot->refresh();
        $this->logger->info('Selling of bot {bot} suspended: {reason}', ['bot' => $bot->id, 'reason' => $reason->value]);
        $agent = $bot->agent;
        if ($agent !== null) {
            $this->notifier->agencySuspended($agent, $reason, $note);
        }
        $this->reports->agencySuspended($bot, $reason);
    }

    /**
     * Lifts the shop's suspension — only one for `$only`, when given; true when this call did. The agent is told once it
     * sells again (an end passed still holds it), and the report group.
     */
    private function lift(Bot $bot, ?SellingSuspension $only = null): bool
    {
        $lifted = Bot::query()->whereKey($bot->id)->whereNotNull('selling_suspended_at')
            ->when($only !== null, static fn(Builder $row) => $row->where('suspension_reason', $only?->value))
            ->update(['selling_suspended_at' => null, 'suspension_reason' => null]) === 1;
        if (!$lifted) {
            return false;
        }

        $bot->refresh();
        $this->logger->info('Selling of bot {bot} resumed', ['bot' => $bot->id]);
        if ($bot->sells()) {
            $agent = $bot->agent;
            if ($agent !== null) {
                $this->notifier->agencyResumed($agent);
            }
            $this->reports->agencyResumed($bot);
        }

        return true;
    }

    /** @return Builder<Order> The agents' traffic orders delivered in the month, in the main shop, refunds aside. */
    private static function purchases(JalaliMonth $month): Builder
    {
        return Order::query()
            ->withoutGlobalScope(CurrentBot::SCOPE)
            ->where('orders.bot_id', Bot::MAIN)
            ->where('type', OrderType::Traffic->value)
            ->where('status', OrderStatus::Fulfilled->value)
            ->where('fulfilled_at', '>=', $month->start)
            ->where('fulfilled_at', '<', $month->end);
    }
}
