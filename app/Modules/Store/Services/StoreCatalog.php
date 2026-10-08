<?php

declare(strict_types=1);

namespace App\Modules\Store\Services;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanServer;
use App\Modules\Catalog\Services\PlanCategoryService;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Services\ServerReadiness;
use App\Modules\Store\Presenters\PlanPresenter;
use App\Modules\Store\Presenters\ServerPresenter;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * What the shop's website sells, in the shop the request is worked in: exactly what its bot's «خرید اشتراک» offers now
 * (PlanCategoryService::catalogue() — the same judgement, in the same few queries however many plans and servers), each
 * plan with the servers a customer may pick it on; and how those servers stand.
 */
final class StoreCatalog
{
    public function __construct(
        private readonly PlanCategoryService $categories,
        private readonly ServerReadiness $readiness,
    ) {}

    /**
     * Every active category in order — an empty one too, as the bot offers it — then the plans of none, or of one
     * switched off, as one last group without a category (none when there are none); a plan only while it can be
     * delivered now: switched on, on a server that can sell it, and — in an agent's shop — covered by the agent's traffic.
     *
     * @return list<array{category: array{id: int, name: string}|null, plans: list<array<string, mixed>>}>
     */
    public function groups(): array
    {
        ['groups' => $groups, 'choices' => $choices] = $this->categories->catalogue();

        return array_map(static fn(array $group): array => [
            'category' => PlanPresenter::category($group['category']),
            'plans' => array_values($group['plans']->map(static fn(Plan $plan): array => PlanPresenter::present($plan, $group['category'], $choices[$plan->id]))->all()),
        ], $groups);
    }

    /**
     * One plan of the catalogue, as groups() offers it.
     *
     * @return array<string, mixed>
     * @throws ModelNotFoundException 404 for one the shop does not sell now — switched off, no server able to deliver it,
     *                                an agent's traffic short of it — or none at all
     */
    public function plan(int $id): array
    {
        ['groups' => $groups, 'choices' => $choices] = $this->categories->catalogue();
        foreach ($groups as $group) {
            $plan = $group['plans']->first(static fn(Plan $plan): bool => $plan->id === $id);
            if ($plan !== null) {
                return PlanPresenter::present($plan, $group['category'], $choices[$plan->id]);
            }
        }

        throw (new ModelNotFoundException())->setModel(Plan::class, [$id]);
    }

    /**
     * The servers the shop's plans on sale (switched on) are on, in the servers' order: whether each takes a new customer
     * now — its connector installed, switched on, serving subscription links, with room (ServerReadiness) — and when the
     * shop last talked to its panel. Their room is counted with them, never a query a server.
     *
     * @return list<array{id: int, name: string, available: bool, checked_at: string|null}>
     */
    public function status(): array
    {
        $plans = PlanServer::query()->select('server_id')->whereIn('plan_id', Plan::active()->reorder()->select('id'));
        $servers = Server::query()->whereIn('id', $plans)->withCount(Server::activeServicesCount())->oldest('sort')->oldest('id')->get();

        return array_values($servers->map(fn(Server $server): array => ServerPresenter::status($server, $this->readiness->problem($server) === null))->all());
    }
}
