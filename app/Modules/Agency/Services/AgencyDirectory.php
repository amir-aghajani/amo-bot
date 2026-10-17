<?php

declare(strict_types=1);

namespace App\Modules\Agency\Services;

use App\Core\Database\Page;
use App\Core\Database\PageRequest;
use App\Modules\Agency\Enums\AgencyRequestStatus;
use App\Modules\Agency\Models\AgencyLevel;
use App\Modules\Agency\Models\AgencyRequest;
use App\Modules\Agency\Models\TrafficTransaction;
use App\Modules\Auth\Services\Reviewers;
use App\Modules\Bots\Models\Bot;
use App\Modules\Bots\Services\Bots;
use App\Modules\Orders\Models\Order;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\UserDirectory;
use App\Support\JalaliMonth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The owner's «نمایندگان» page and the agent's own account page: the program's numbers, the requests, and the agents with
 * their bot, its traffic, what it sold and what they owe. Agents are customers of the main bot.
 */
final class AgencyDirectory
{
    public function __construct(
        private readonly TrafficPool $pool,
        private readonly Bots $bots,
        private readonly AgencyStanding $standing,
        private readonly Reviewers $reviewers,
    ) {}

    /**
     * The program's numbers, above the lists (its rules are AgencySettings', on their own section).
     *
     * @return array{levels: int, agents: int, bots: int, pending: int}
     */
    public function summary(): array
    {
        return [
            'levels' => AgencyLevel::query()->count(),
            'agents' => User::query()->whereNotNull('agency_level_id')->count(),
            'bots' => Bot::activeAgents()->whereNotNull('telegram_id')->count(),
            'pending' => AgencyRequest::query()->where('status', AgencyRequestStatus::Pending->value)->count(),
        ];
    }

    /** The requests, newest first (or the oldest, `dir`): one status or all, searched by the customer. */
    public function requests(PageRequest $list): Page
    {
        $query = AgencyRequest::query()->with(['user', 'level']);
        $status = $list->enum('status', AgencyRequestStatus::class);
        if ($status !== null) {
            $query->where('status', $status->value);
        }
        if ($list->term !== '') {
            // A few hundred requests are read whole faster than the customers a term finds are listed first.
            $query->whereIn('user_id', User::idsMatching($list->term, listed: false));
        }

        return Page::fetchTogether($query, $list, $this->presentRequests(...), $list->sort(['created' => 'id'], 'created'));
    }

    /**
     * The agents, on one level or all, searched by name: their bot, what it sold, what they owe — newest first, or by
     * their wallet, their bot's traffic or what it sold (`sort`).
     */
    public function agents(PageRequest $list): Page
    {
        $query = self::agentRows();
        $level = $list->id('level');
        if ($level !== null) {
            $query->where('agency_level_id', $level);
        }
        if ($list->term !== '') {
            User::matching($query, $list->term);
        }

        return Page::fetch($query, $list, $this->presentAgent(...), $list->sort([
            'joined' => 'id',
            'balance' => User::balanceOrder(),
            'traffic' => self::ofTheirBot(Bot::trafficBalanceColumn()),
            'sold' => self::ofTheirBot(Bot::soldColumn()),
        ], 'joined'));
    }

    /**
     * One agent, as the list shows them.
     *
     * @throws ModelNotFoundException for a customer who is not one
     */
    public function agent(int $userId): User
    {
        return self::agentRows()->findOrFail($userId);
    }

    /** An agent's traffic, line by line, newest first. */
    public function trafficLedger(User $agent, PageRequest $list): Page
    {
        $bot = $agent->ownBot;

        return $bot === null ? Page::empty() : $this->pool->ledger($bot, $list);
    }

    /**
     * «حساب نمایندگی», the agent's own page of their panel: their bot, their level and its price per GB, their wallet and
     * credit with the shop, and their terms of selling — the end, the monthly minimum, a suspension (AgencyStanding).
     *
     * @return array<string, mixed>
     */
    public function account(Bot $bot): array
    {
        $agent = $bot->agent ?? throw new \LogicException("Bot #{$bot->id} has no agent.");

        return [
            'bot' => $this->presentBot($bot),
            'agent' => UserDirectory::presentRef($agent),
            'level' => $agent->agencyLevel === null ? null : AgencyLevels::presentRef($agent->agencyLevel),
            'credit_limit' => $agent->credit_limit,
            'balance' => $agent->balance(),
            'standing' => $this->standing->present($bot),
        ];
    }

    /** @return array<string, mixed> One request, its customer and level loaded with it. */
    public function presentRequest(AgencyRequest $request): array
    {
        return $this->presentRequests(new Collection([$request]))[0];
    }

    /**
     * Requests as the page shows them, the names of the shop's admins who decided them — in the report group — read once
     * for them all (Reviewers::names()).
     *
     * @param Collection<int, AgencyRequest> $requests
     * @return list<array<string, mixed>>
     */
    private function presentRequests(Collection $requests): array
    {
        $names = $this->reviewers->names($requests->pluck('reviewer'));

        return array_values($requests->map(static function (AgencyRequest $request) use ($names): array {
            $open = $request->status === AgencyRequestStatus::Pending;

            return [
                'id' => $request->id,
                'user' => UserDirectory::presentRef($request->user),
                'user_status' => $request->user->status->value,
                'agent' => $request->user->isAgent(),
                'status' => $request->status->value,
                'note' => $request->note,
                'level' => $request->level === null ? null : AgencyLevels::presentRef($request->level),
                'reason' => $request->reason,
                'reviewer' => $request->reviewer,
                'reviewer_name' => $names[$request->reviewer ?? ''] ?? null,
                'decided_at' => $request->decided_at?->toIso8601String(),
                'created_at' => $request->created_at->toIso8601String(),
                'actions' => ['approve' => $open, 'reject' => $open],
            ];
        })->all());
    }

    /** @return array<string, mixed> */
    public function presentAgent(User $agent): array
    {
        $level = $agent->agencyLevel ?? throw new \LogicException("User #{$agent->id} is not an agent.");
        $bot = $agent->ownBot;
        // Whether the bot runs is its agent's agency (Bot::status()): the row at hand, not a query a row.
        $bot?->setRelation('agent', $agent);

        return [
            'id' => $agent->id,
            'user' => UserDirectory::presentRef($agent),
            'status' => $agent->status->value,
            'level' => AgencyLevels::presentRef($level),
            'credit_limit' => $agent->credit_limit,
            'balance' => $agent->balance(),
            'bot' => $bot === null ? null : $this->presentBot($bot),
            'counts' => $bot?->stats() ?? ['customers' => 0, 'sold' => 0, 'active' => 0],
            // Their terms of selling, this month's purchases read with the row (agentRows()); what the owner keeps from their shop.
            'standing' => $bot === null ? null : $this->standing->present($bot, $agent->getAttribute(AgencyStanding::PURCHASED)),
            'hidden' => $bot?->hiddenCounts() ?? ['servers' => 0, 'plans' => 0],
        ];
    }

    /**
     * An agent's bot, as the panel shows it: who it is, whether it runs, what keeps it from running, its traffic.
     *
     * @return array{id: int, username: string|null, title: string|null, status: string, connected: bool, problem: string|null, traffic_balance: int, connected_at: string|null}
     */
    public function presentBot(Bot $bot): array
    {
        return [
            'id' => $bot->id,
            'username' => $bot->username,
            'title' => $bot->title,
            'status' => $bot->status()->value,
            'connected' => $this->bots->hasToken($bot),
            'problem' => $bot->problem,
            'traffic_balance' => $bot->trafficBalance(),
            'connected_at' => $bot->connected_at?->toIso8601String(),
        ];
    }

    /**
     * The agents, each read with what their row shows: their wallet and this month's traffic bought, their level, and
     * their bot with the traffic it may still sell, what its shop did and what the owner keeps from it — in the same
     * queries, not a few a row.
     *
     * @return Builder<User>
     */
    private static function agentRows(): Builder
    {
        return User::query()
            ->whereNotNull('agency_level_id')
            ->addSelect(User::balanceColumn())
            ->addSelect(AgencyStanding::purchasedColumn(JalaliMonth::now()))
            ->with(['agencyLevel', 'ownBot' => static fn(Relation $bot) => $bot->addSelect(Bot::trafficBalanceColumn())->addSelect(Bot::hiddenCountColumns())->withCount(Bot::statCounts())]);
    }

    /**
     * One of the bot's columns (Bot::trafficBalanceColumn(), Bot::soldColumn()) read for the agent each row of
     * agentRows() is: what the list orders the agents by — an agent without a bot at 0 (Sort).
     *
     * @param array<string, Builder<TrafficTransaction>>|array<string, Builder<Order>> $column
     */
    private static function ofTheirBot(array $column): QueryBuilder
    {
        return Bot::query()->select($column)->whereColumn('bots.user_id', 'users.id')->toBase();
    }
}
