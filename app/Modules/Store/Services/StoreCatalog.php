<?php

declare(strict_types=1);

namespace App\Modules\Store\Services;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanCategory;
use App\Modules\Catalog\Models\PlanServer;
use App\Modules\Catalog\Services\PlanCategoryService;
use App\Modules\Orders\DTO\Buyer;
use App\Modules\Orders\Services\Pricing;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Services\ServerReadiness;
use App\Modules\Store\Presenters\PlanPresenter;
use App\Modules\Store\Presenters\ServerPresenter;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * What the website sells: exactly what the bot's «خرید اشتراک» offers now (PlanCategoryService::catalogue(), in a few
 * queries however many plans and servers), each plan with the servers it may be picked on and the prices the reader pays
 * (Pricing: a first-purchase or agent price when theirs, less their largest automatic discount); and how those servers
 * stand.
 */
final class StoreCatalog
{
    public function __construct(
        private readonly PlanCategoryService $categories,
        private readonly Pricing $pricing,
        private readonly ServerReadiness $readiness,
    ) {}

    /**
     * Every active category in order (an empty one too, as the bot offers it), then the plans of none or of a switched-off
     * one as a last group without a category. A plan is listed only while it can be delivered now. Priced for `$customer`
     * (null: a guest), who is looked up once for the whole catalogue.
     *
     * @return list<array{category: array{id: int, name: string}|null, plans: list<array<string, mixed>>}>
     */
    public function groups(?User $customer): array
    {
        ['groups' => $groups, 'choices' => $choices] = $this->categories->catalogue();
        $buyer = $this->buyer($customer, $groups);
        $automatic = $this->pricing->automatic($customer);

        return array_map(static fn(array $group): array => [
            'category' => PlanPresenter::category($group['category']),
            'plans' => array_values($group['plans']->map(static fn(Plan $plan): array => PlanPresenter::present($plan, $group['category'], $choices[$plan->id], $buyer, Pricing::offer($buyer, $automatic, $plan)))->all()),
        ], $groups);
    }

    /**
     * One plan of the catalogue, as groups() offers it to `$customer` (null: a guest).
     *
     * @return array<string, mixed>
     * @throws ModelNotFoundException 404 for a plan not on sale now, or none at all
     */
    public function plan(int $id, ?User $customer): array
    {
        ['groups' => $groups, 'choices' => $choices] = $this->categories->catalogue();
        foreach ($groups as $group) {
            $plan = $group['plans']->first(static fn(Plan $plan): bool => $plan->id === $id);
            if ($plan !== null) {
                $buyer = $this->pricing->buyer($customer, $plan);

                return PlanPresenter::present($plan, $group['category'], $choices[$plan->id], $buyer, Pricing::offer($buyer, $this->pricing->automatic($customer), $plan));
            }
        }

        throw (new ModelNotFoundException())->setModel(Plan::class, [$id]);
    }

    /**
     * The reader as the plans' prices see them (Pricing::buyer(), asked once — and not at all while no plan has another price).
     *
     * @param list<array{category: PlanCategory|null, plans: Collection<int, Plan>}> $groups
     */
    private function buyer(?User $customer, array $groups): Buyer
    {
        $plans = [];
        foreach ($groups as $group) {
            array_push($plans, ...$group['plans']->all());
        }

        return $this->pricing->buyer($customer, ...$plans);
    }

    /**
     * The servers of the plans switched on, in the servers' order: whether each takes a new customer now (ServerReadiness)
     * and when the shop last reached its panel. Their room is counted in the same query.
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
