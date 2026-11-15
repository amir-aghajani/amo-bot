<?php

declare(strict_types=1);

namespace App\Modules\Imports\Writers;

use App\Core\Database\Sorting;
use App\Core\Exceptions\ValidationException;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanCategory;
use App\Modules\Catalog\Services\PlanCategoryService;
use App\Modules\Catalog\Services\PlanService;
use App\Modules\Imports\Enums\ProblemReason;
use App\Modules\Imports\Links;
use App\Modules\Imports\Records\ImportedPlan;
use App\Modules\Subscriptions\Services\ServiceTerms;
use App\Support\Money;
use App\Support\Text;
use Illuminate\Database\ConnectionInterface;

/**
 * The plans stage: each plan of the other bot made through the plans' own form (PlanService), sold on every inbound of
 * the servers its panels map to, in the category of its name — the shop's of that name as it is, or one made for it —,
 * switched on only when the owner says so and it has a server to sell on, never a plan only the other bot's agents saw
 * nor one it had stopped selling.
 * A category an import made is switched on once a plan of it comes in switched on. A plan none of whose panels came over
 * is made all the same, without a server, its own fields held to the form's bounds; the report names it. The plan, its
 * category and their links are written together, the category only once the plan's form took it.
 */
final class PlanWriter
{
    private const KIND = 'plans';
    private const CATEGORIES = 'categories';

    /** The plan form's bounds (PlanService): what a plan beyond them is held to. */
    private const NAME_MAX = 128;
    private const NOTE_MAX = 500;
    private const CATEGORY_MAX = 64;
    private const PRICE_MAX = '999999999999';
    private const DAYS_MAX = 3650;
    private const GB_MAX = 100000;
    private const DEVICES_MAX = 1000;

    public function __construct(
        private readonly PlanService $plans,
        private readonly PlanCategoryService $categories,
        private readonly Links $links,
        private readonly ConnectionInterface $db,
    ) {}

    public function write(Run $run, ImportedPlan $plan): void
    {
        if ($run->broughtBefore(Links::PLAN, self::KIND, $plan->key, Plan::class)) {
            return;
        }

        $servers = [];
        foreach ($plan->panelKeys === null ? $run->servers() : array_filter(array_map($run->server(...), $plan->panelKeys)) as $server) {
            $servers[$server->id] = ['server_id' => $server->id, 'all_inbounds' => true];
        }
        $name = Text::fit(trim($plan->name) !== '' ? trim($plan->name) : $plan->key, self::NAME_MAX);
        $price = self::whole($plan->price, self::PRICE_MAX);
        $agentPrice = $plan->agentPrice === null ? null : self::whole($plan->agentPrice, self::PRICE_MAX);
        $note = trim((string) $plan->note);
        $active = $run->choices->on('plans_on') && !$plan->agentsOnly && $plan->selling && $servers !== [];
        $values = [
            'name' => $name,
            'price' => $price,
            // An agent price is one below the price, or none.
            'agent_price' => $agentPrice !== null && Money::compare($agentPrice, $price) < 0 ? $agentPrice : null,
            'duration_days' => max(0, min(self::DAYS_MAX, $plan->durationDays)),
            'traffic_gb' => is_numeric($plan->trafficGb) ? round(max(0.0, min((float) self::GB_MAX, (float) $plan->trafficGb)), 2) : 0.0,
            'ip_limit' => max(0, min(self::DEVICES_MAX, $plan->ipLimit)),
            'checkout_note' => $note === '' ? null : Text::fit($note, self::NOTE_MAX),
            'is_active' => $active,
        ];
        $category = $plan->category === null || trim($plan->category) === '' ? null : trim($plan->category);

        try {
            $category = $this->db->transaction(function () use ($run, $plan, $servers, $values, $category, $active): array {
                $made = $servers === [] ? self::withoutServers($values) : $this->plans->create(['servers' => array_values($servers)] + array_map(static fn(mixed $value): mixed => is_float($value) ? (string) $value : $value ?? '', $values));
                $filed = $category === null ? ['id' => null, 'outcome' => null, 'refusal' => null] : $this->category($run, $category, $active);
                if ($filed['id'] !== null) {
                    $made->forceFill(['category_id' => $filed['id']])->save();
                }
                $this->links->add($run->import, Links::PLAN, $plan->key, $made->id);

                return $filed;
            });
        } catch (ValidationException $e) {
            $run->skipped(self::KIND, $plan->key, ProblemReason::Refused, $name . ': ' . Run::words($e));

            return;
        }
        $run->created(self::KIND);
        match ($category['outcome']) {
            'created' => $run->created(self::CATEGORIES),
            'linked' => $run->linked(self::CATEGORIES),
            default => null,
        };
        if ($category['refusal'] !== null) {
            $run->problem(self::CATEGORIES, (string) $plan->category, ProblemReason::Refused, $category['refusal']);
        }
        if ($servers === []) {
            $run->problem(self::KIND, $plan->key, ProblemReason::PlanWithoutServer, $name);
        }
        if ($plan->agentsOnly) {
            $run->problem(self::KIND, $plan->key, ProblemReason::AgentsOnlyPlan, $name);
        }
    }

    /**
     * The plan's category by its name, inside the plan's transaction: the one an import made or found before (linked
     * already; one it made switched on now when its plan comes in switched on), the shop's of that name as the owner
     * keeps it (linked), or a new one, switched on with its plan. A name the category form refuses files the plan under
     * none.
     *
     * @return array{id: int|null, outcome: 'created'|'linked'|null, refusal: string|null}
     */
    private function category(Run $run, string $name, bool $active): array
    {
        $linked = $this->links->alive($run->import, Links::CATEGORY, $name, PlanCategory::class);
        if ($linked !== null) {
            if ($active && $this->links->alive($run->import, Links::MADE_CATEGORY, $name, PlanCategory::class) !== null) {
                PlanCategory::query()->whereKey($linked)->where('is_active', false)->update(['is_active' => true]);
            }

            return ['id' => $linked, 'outcome' => null, 'refusal' => null];
        }

        // What a category made before and deleted since was is forgotten with it.
        $this->links->drop($run->import, Links::MADE_CATEGORY, $name);
        $existing = PlanCategory::query()->where('name', Text::fit($name, self::CATEGORY_MAX))->first();
        if ($existing !== null) {
            $this->links->add($run->import, Links::CATEGORY, $name, $existing->id);

            return ['id' => $existing->id, 'outcome' => 'linked', 'refusal' => null];
        }
        try {
            $made = $this->db->transaction(fn(): PlanCategory => $this->categories->create(['name' => Text::fit($name, self::CATEGORY_MAX), 'is_active' => $active]));
        } catch (ValidationException $e) {
            return ['id' => null, 'outcome' => null, 'refusal' => Run::words($e)];
        }
        $this->links->add($run->import, Links::CATEGORY, $name, $made->id);
        $this->links->add($run->import, Links::MADE_CATEGORY, $name, $made->id);

        return ['id' => $made->id, 'outcome' => 'created', 'refusal' => null];
    }

    /**
     * A plan with no server to sell on, which the plans' form does not take: written as the form would have written it
     * (its values held to the form's bounds above), last in the owner's order.
     *
     * @param array<string, mixed> $values
     */
    private static function withoutServers(array $values): Plan
    {
        $days = $values['duration_days'];
        unset($values['duration_days']);

        return Plan::query()->create($values + [
            'duration_hours' => (is_int($days) ? $days : 0) * ServiceTerms::HOURS_A_DAY,
            'sort' => Sorting::next(Plan::class),
        ]);
    }

    /** Whole Toman of a decimal string, from 0 to `$most` (what a price column keeps). */
    private static function whole(string $amount, string $most): string
    {
        if (!is_numeric($amount) || Money::compare($amount, 0) <= 0) {
            return '0';
        }
        $whole = explode('.', Money::normalize($amount))[0];

        return Money::compare($whole, $most) > 0 ? $most : $whole;
    }
}
