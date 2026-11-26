<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports;

use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * A shop's day in figures, for its daily report (DailyReport → ShopReports::daily()): read whole before the report is
 * written, so composing it asks nothing of the database. Income is money that came in (the dashboard's revenue: paid
 * payments not made from the wallet, top-ups among them); sales are orders whose payment was accepted that day, by the
 * line they are said in — wallet-paid ones too, so the two never add up and the report says them apart; a service given
 * free (a gift) is no sale.
 */
final readonly class DailyFigures
{
    /**
     * @param Carbon $day The day's first moment on the shop's clock
     * @param list<array{name: string, total: string}> $methods Income by payment method, largest first (the rest folded)
     * @param array<string, array{count: int, amount: string, bytes: int}> $sales By DailyReport::LINES key, only lines sold
     * @param list<array{name: string, count: int, amount: string}> $plans Purchases and renewals by plan, most sold first
     * @param list<array{name: string, count: int}> $servers Purchases and renewals by server, most sold first
     * @param array<string, int> $waiting What waits on support now, by the dashboard's keys (payments_to_review, stuck_orders, open_tickets, pending_reviews)
     * @param array{rows: list<array{bot: string, agent: string, count: int, amount: string}>, count: int, amount: string, more: int}|null $agents What each agent's bot sold (the main shop's report alone)
     * @param int|null $trafficLeft The agency's traffic left, bytes (an agent's report alone)
     */
    public function __construct(
        public Carbon $day,
        public string $income,
        public string $incomeBefore,
        public array $methods,
        public array $sales,
        public array $plans,
        public array $servers,
        public int $buyers,
        public int $newUsers,
        public int $tests,
        public int $activeServices,
        public array $waiting,
        public ?array $agents,
        public ?int $trafficLeft,
    ) {}

    /** Whether the shop sold nothing that day — whatever money came in (a refunded order's, one that went to the wallet). */
    public function quiet(): bool
    {
        return $this->sales === [];
    }

    /**
     * The same day with one name fewer, for a report that would not fit one message (ShopReports::dailyText()): an agent
     * folded into how many more are left out first — the agents' totals stay all of theirs —, then the last plan, then
     * the last server, then the last two payment methods as one «سایر», their money kept. Income and sales are never
     * touched. Null when no name is left to take.
     */
    public function shorter(): ?self
    {
        [$agents, $plans, $servers, $methods] = [$this->agents, $this->plans, $this->servers, $this->methods];
        if ($agents !== null && $agents['rows'] !== []) {
            array_pop($agents['rows']);
            $agents['more']++;
        } elseif ($plans !== []) {
            array_pop($plans);
        } elseif ($servers !== []) {
            array_pop($servers);
        } elseif (count($methods) > 1) {
            $last = array_pop($methods);
            $before = array_pop($methods);
            $methods[] = ['name' => DailyReport::OTHER, 'total' => Money::add($before['total'], $last['total'])];
        } else {
            return null;
        }

        return new self($this->day, $this->income, $this->incomeBefore, $methods, $this->sales, $plans, $servers, $this->buyers, $this->newUsers, $this->tests, $this->activeServices, $this->waiting, $agents, $this->trafficLeft);
    }
}
