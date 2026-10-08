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
 * What of the catalogue can be sold right now. The admin may attach any server to a plan; a customer is only shown what
 * can deliver today — a server whose connector is installed, that is switched on, serves subscription links (the one
 * thing the customer gets), has room and — where the panel deals in inbounds — something sellable on it; and, in an
 * agent's bot, a plan whose traffic the agent's prepaid traffic still covers. One judgement for the bot's shop, the
 * website's catalogue, a move, and the screens that say why something is not offered: problem() for a server or a plan's
 * entry on it, judge() and offers() for plans — their servers read once for the whole list, never a few queries a plan.
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
     * The servers a customer can pick for a plan right now, in the plan's order, each with the inbounds a purchase on it
     * gets (every selectable one for a whole-server entry, or the pinned set; none on a panel without inbounds, where the
     * whole server is the only thing to sell). Its entries are read as they are now, whatever the plan was read with.
     *
     * @return list<array{server: Server, inbounds: Collection<int, ServerInbound>}>
     */
    public function choices(Plan $plan): array
    {
        return $this->choicesAmong(self::entriesOf(new Collection([$plan]))[$plan->id]);
    }

    /**
     * Why customers are not sold on the server — on the plan's `$entry` for it, when given — right now, in the admin's
     * words; null when they are.
     */
    public function problem(Server $server, ?PlanServer $entry = null): ?string
    {
        return $this->readiness->problem($server) ?? ($entry !== null && $this->inbounds($server, $entry) === null ? self::NO_INBOUND : null);
    }

    /**
     * Whether the plan may be offered: in an agent's bot, while the agent's traffic covers what it sells (read afresh —
     * another sale may have drawn on it a moment ago); always in the main bot. A delivery does not ask: it draws the
     * traffic itself (TrafficPool::draw()).
     */
    public function covers(Plan $plan): bool
    {
        $balances = [];

        return self::covered($plan, $balances);
    }

    /**
     * covers() of several plans at once — a page of a customer's services, each renewed on its plan —, by id: an
     * agent's traffic read once for its shop (afresh for every list).
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
     * Whether the current shop — an agent's bot — sells nothing for want of traffic, for its panel to warn about: its
     * traffic cannot cover even its smallest plan on sale (covers()), or it has none left, which is no better once a plan
     * is put on sale — no plan is offered and no service renewed. What it may still sell and what the smallest plan needs
     * (null while none is on sale); null while it can sell, and always in the main bot.
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
     * Where a service of `$plan` may move: a server that can sell (problem()); on a panel with inbounds the client gets
     * the plan's entry for that server when the plan has one (the inbounds a purchase there gets), every inbound the
     * server sells otherwise.
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
     * Why customers are not shown each plan right now, its switch aside, in the admin's words — no server on it, none
     * of its servers able to sell (problem() says why of each), or, in an agent's bot, traffic that does not cover it —,
     * by id; null for one they are shown. The plans' servers are read in one go (entriesOf()), an agent's traffic once
     * for its shop.
     *
     * @param Collection<int, Plan> $plans
     * @return array<int, string|null>
     */
    public function judge(Collection $plans): array
    {
        return array_map(static fn(array $weighed): ?string => $weighed['reason'], $this->weigh($plans));
    }

    /**
     * What a customer is offered of the plans right now, by id: the plans judge() has nothing against, each with the
     * servers to pick it on (choices(), in the plan's order) — the plans' servers read once for them all. A plan whose
     * every server is off, full, without subscription links or without a sellable inbound is kept out of the shop rather
     * than shown as unbuyable: the bot's «خرید اشتراک» lists these plans, the website its catalogue with their locations
     * (PlanCategoryService::catalogue()).
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
     * Resolve the customer's choice. Without a server id the plan's only choice is used; with several, the customer must
     * have picked one.
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
     * judge() and choices() of the plans together: why each is not shown (null when it is) and the servers a customer
     * may pick it on — the plans' entries read once for them all (entriesOf()), an agent's traffic once for its shop,
     * and only for a plan something could be sold on.
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
     * covers(), its shop's traffic read once over a list: `$balances`, by bot id — the main bot's shop sells without.
     *
     * @param array<int, int> $balances
     */
    private static function covered(Plan $plan, array &$balances): bool
    {
        $bot = $plan->shop();

        return $bot->isMain() || self::coveredBy($plan, $balances[$bot->id] ??= $bot->trafficBalance());
    }

    /**
     * Whether an agent's traffic (`$balance`, in bytes) covers the plan: it sells a quota — an unlimited plan cannot be
     * paid for from prepaid traffic — no bigger than what is left.
     */
    private static function coveredBy(Plan $plan, int $balance): bool
    {
        $bytes = $plan->trafficBytes();

        return $bytes > 0 && $balance >= $bytes;
    }

    /**
     * The plans' entries as they are now, by plan id, each plan's in its order — read afresh, never kept on a plan (one
     * read a while ago — a long-lived process, a screen's two questions — is judged by today's servers), in one go for
     * them all with what judging them reads: the server with its active services counted (its room) and its inbounds,
     * and the entry's pinned inbounds (PlanServer::sellableInbounds() reads them off the entry then).
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
     * What a sale on the server gets: the entry's inbounds, or — without one — every inbound the server sells; none on a
     * panel without inbounds, which is sold whole. Null when there is nothing to sell.
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
