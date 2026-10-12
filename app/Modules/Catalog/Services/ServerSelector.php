<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Exceptions\NoServerAvailableException;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanServer;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Models\ServerInbound;
use App\Modules\Providers\ProviderRegistry;
use App\Modules\Providers\Services\ServerReadiness;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * What of the catalogue can be sold right now — one judgement for the bot, the website, moving a service and the panels'
 * explanations. A customer only sees servers that can deliver today (ServerReadiness, plus something sellable where the
 * panel deals in inbounds) and, in an agent's shop, plans the agent's prepaid traffic covers. A list's plans and servers
 * are read in one go, never a few queries per plan.
 */
final class ServerSelector
{
    private const NO_INBOUND = 'اینباند قابل فروشی ندارد؛ در صفحه سرور اینباندی را برای فروش علامت بزنید.';
    private const NO_SERVER = 'این پلن روی هیچ سروری نیست؛ از فرم پلن یک سرور به آن اضافه کنید.';
    private const NO_CHOICE = 'هیچ‌کدام از سرورهای این پلن الان قابل فروش نیست؛ دلیل هر کدام کنار نام همان سرور آمده است.';
    private const NOT_COVERED = 'حجم باقی‌مانده نمایندگی کمتر از حجم این پلن است؛ با خرید حجم، پلن دوباره در ربات دیده می‌شود.';

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly ServerReadiness $readiness,
    ) {}

    /**
     * The servers a customer can pick for the plan now, in the plan's order, each with the inbounds a purchase gets
     * (empty on a panel without inbounds). Its entries are read afresh.
     *
     * @return list<array{server: Server, inbounds: Collection<int, ServerInbound>}>
     */
    public function choices(Plan $plan): array
    {
        return $this->choicesAmong(self::entriesOf(new Collection([$plan]))[$plan->id]);
    }

    /** Why customers are not sold on the server (for the plan's `$entry`, when given) right now; null when they are. */
    public function problem(Server $server, ?PlanServer $entry = null): ?string
    {
        return $this->readiness->problem($server) ?? ($entry !== null && $this->inbounds($server, $entry) === null ? self::NO_INBOUND : null);
    }

    /**
     * Whether the plan may be offered: always in the main shop; in an agent's, while their traffic (read afresh) covers
     * its quota. Delivery does not ask — it draws the traffic itself (TrafficPool::draw()).
     */
    public function covers(Plan $plan): bool
    {
        $balances = [];

        return self::covered($plan, $balances);
    }

    /**
     * covers() of several plans at once, by id, each shop's traffic read once.
     *
     * @param iterable<Plan> $plans
     * @return array<int, bool>
     */
    public function coverage(iterable $plans): array
    {
        $balances = [];
        $covered = [];
        foreach ($plans as $plan) {
            $covered[$plan->id] = self::covered($plan, $balances);
        }

        return $covered;
    }

    /**
     * For an agent's panel warning: the shop's traffic balance and its smallest plan's quota when it cannot sell anything
     * for want of traffic (not even the smallest plan on sale, or none left at all); null while it can, and in the main
     * shop.
     *
     * @return array{balance: int, smallest_plan: int|null}|null
     */
    public function shortage(): ?array
    {
        $bot = CurrentBot::get();
        if ($bot->isMain()) {
            return null;
        }
        $balance = $bot->trafficBalance();
        $smallest = Plan::active()->where('traffic_gb', '>', 0)->reorder('traffic_gb')->first();
        if ($balance > 0 && ($smallest === null || $this->covers($smallest))) {
            return null;
        }

        return ['balance' => $balance, 'smallest_plan' => $smallest?->trafficBytes()];
    }

    /**
     * The inbounds a service of `$plan` gets when moved to `$server`: the plan's entry there if it has one, else every
     * inbound the server sells.
     *
     * @return Collection<int, ServerInbound>
     * @throws NoServerAvailableException saying why the server cannot take it
     */
    public function moveTarget(?Plan $plan, Server $server): Collection
    {
        $problem = $this->readiness->problem($server);
        $inbounds = $problem === null ? $this->inbounds($server, $plan?->servers()->where('server_id', $server->id)->first()) : null;

        return $inbounds ?? throw new NoServerAvailableException(sprintf('«%s»: %s', $server->name, $problem ?? self::NO_INBOUND));
    }

    /**
     * What a free test account on the server gets — every inbound the server sells, or an empty list on a panel without
     * inbounds — while it can take a client now; null while it cannot.
     *
     * @return Collection<int, ServerInbound>|null
     */
    public function testInbounds(Server $server): ?Collection
    {
        return $this->readiness->problem($server) === null ? $this->inbounds($server, null) : null;
    }

    /**
     * Why customers are not shown each plan now (its switch aside), by id; null for one they are shown.
     *
     * @param Collection<int, Plan> $plans
     * @return array<int, string|null>
     */
    public function judge(Collection $plans): array
    {
        return array_map(static fn(array $weighed): ?string => $weighed['reason'], $this->weigh($plans));
    }

    /**
     * The plans customers are offered now, by id, each with its choices. A plan that cannot be sold is left out rather
     * than shown as unbuyable.
     *
     * @param Collection<int, Plan> $plans
     * @return array<int, list<array{server: Server, inbounds: Collection<int, ServerInbound>}>>
     */
    public function offers(Collection $plans): array
    {
        $offers = [];
        foreach ($this->weigh($plans) as $id => ['reason' => $reason, 'choices' => $choices]) {
            if ($reason === null) {
                $offers[$id] = $choices;
            }
        }

        return $offers;
    }

    /**
     * The customer's choice of server: with no id, the plan's only choice (several require a pick).
     *
     * @return array{server: Server, inbounds: Collection<int, ServerInbound>}
     * @throws NoServerAvailableException
     */
    public function resolve(Plan $plan, ?int $serverId): array
    {
        $choices = $this->choices($plan);
        if ($choices === []) {
            throw new NoServerAvailableException("هیچ‌کدام از سرورهای پلن «{$plan->name}» الان در دسترس نیست.");
        }

        if ($serverId === null) {
            if (count($choices) > 1) {
                throw new NoServerAvailableException("پلن «{$plan->name}» روی چند سرور فروخته می‌شود؛ سفارش باید سرور را مشخص کند.");
            }

            return $choices[0];
        }

        foreach ($choices as $choice) {
            if ($choice['server']->id === $serverId) {
                return $choice;
            }
        }

        throw new NoServerAvailableException("سرور #{$serverId} برای پلن «{$plan->name}» در دسترس نیست.");
    }

    /**
     * judge() and choices() of the plans together, their entries read once and each shop's traffic once.
     *
     * @param Collection<int, Plan> $plans
     * @return array<int, array{reason: string|null, choices: list<array{server: Server, inbounds: Collection<int, ServerInbound>}>}>
     */
    private function weigh(Collection $plans): array
    {
        $entries = self::entriesOf($plans);
        $balances = [];
        $weighed = [];
        foreach ($plans as $plan) {
            $choices = $this->choicesAmong($entries[$plan->id]);
            $weighed[$plan->id] = [
                'reason' => match (true) {
                    $entries[$plan->id]->isEmpty() => self::NO_SERVER,
                    $choices === [] => self::NO_CHOICE,
                    !self::covered($plan, $balances) => self::NOT_COVERED,
                    default => null,
                },
                'choices' => $choices,
            ];
        }

        return $weighed;
    }

    /**
     * covers(), caching each shop's traffic in `$balances` (by bot id) over a list.
     *
     * @param array<int, int> $balances
     */
    private static function covered(Plan $plan, array &$balances): bool
    {
        $bot = $plan->shop();

        return $bot->isMain() || self::coveredBy($plan, $balances[$bot->id] ??= $bot->trafficBalance());
    }

    /** Whether `$balance` bytes cover the plan's quota; an unlimited plan cannot be paid from prepaid traffic. */
    private static function coveredBy(Plan $plan, int $balance): bool
    {
        $bytes = $plan->trafficBytes();

        return $bytes > 0 && $balance >= $bytes;
    }

    /**
     * The plans' entries, by plan id, read afresh in one query (never trusted from the plan model, which a long-lived
     * process may have read long ago), with their servers' active services counted and their inbounds.
     *
     * @param Collection<int, Plan> $plans
     * @return array<int, Collection<int, PlanServer>>
     */
    private static function entriesOf(Collection $plans): array
    {
        $entries = PlanServer::query()
            ->whereIn('plan_id', $plans->modelKeys())
            ->with(['server' => static fn(Relation $server) => $server->withCount(Server::activeServicesCount())->with('inbounds'), 'inbounds'])
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        $byPlan = [];
        foreach ($plans as $plan) {
            $byPlan[$plan->id] = $entries->filter(static fn(PlanServer $entry): bool => (int) $entry->plan_id === $plan->id)->values();
        }

        return $byPlan;
    }

    /**
     * The entries a customer may pick, as choices() hands them out.
     *
     * @param Collection<int, PlanServer> $entries
     * @return list<array{server: Server, inbounds: Collection<int, ServerInbound>}>
     */
    private function choicesAmong(Collection $entries): array
    {
        $choices = [];
        foreach ($entries as $entry) {
            $inbounds = $this->readiness->problem($entry->server) === null ? $this->inbounds($entry->server, $entry) : null;
            if ($inbounds !== null) {
                $choices[] = ['server' => $entry->server, 'inbounds' => $inbounds];
            }
        }

        return $choices;
    }

    /**
     * What a sale on the server gets: the entry's inbounds, else every inbound the server sells, or an empty list on a
     * panel without inbounds; null when there is nothing to sell.
     *
     * @return Collection<int, ServerInbound>|null
     */
    private function inbounds(Server $server, ?PlanServer $entry): ?Collection
    {
        if (!$this->providers->capabilities($server->driver)->inbounds) {
            return new Collection();
        }
        $inbounds = $entry?->sellableInbounds() ?? $server->sellableInbounds();

        return $inbounds->isNotEmpty() ? $inbounds : null;
    }
}
