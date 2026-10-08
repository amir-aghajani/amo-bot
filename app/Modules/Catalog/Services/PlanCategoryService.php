<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Core\Database\Sorting;
use App\Core\Database\UniqueName;
use App\Core\Exceptions\ValidationException;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanCategory;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Models\ServerInbound;
use App\Support\Input;
use App\Support\Validation;
use Illuminate\Database\Eloquent\Collection;

/**
 * Plan categories for the admin screen and the shop: create/rename/delete/reorder with Persian validation — a name
 * unique in the shop, the table's unique index deciding (Core\Database\UniqueName) — and the grouping the customer
 * browses (the categories with their sellable plans, plus an "other" group for plans without one): in the bot
 * (groups()), and on the shop's website with each plan's servers to pick (catalogue()).
 */
final class PlanCategoryService
{
    private const NAME_MAX = 64;
    private const TAKEN = 'دسته‌ای با این نام وجود دارد.';

    public function __construct(private readonly ServerSelector $selector) {}

    /** @return list<array<string, mixed>> Every category in display order with its plan count. */
    public function all(): array
    {
        return PlanCategory::query()->withCount('plans')->oldest('sort')->oldest('id')->get()->map($this->present(...))->values()->all();
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function create(array $input): PlanCategory
    {
        $category = new PlanCategory(self::validate($input, new PlanCategory()) + ['sort' => Sorting::next(PlanCategory::class)]);
        UniqueName::save($category, self::TAKEN);

        return $category;
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function update(PlanCategory $category, array $input): PlanCategory
    {
        UniqueName::save($category->fill(self::validate($input, $category)), self::TAKEN);

        return $category;
    }

    public function setActive(PlanCategory $category, bool $active): PlanCategory
    {
        $category->forceFill(['is_active' => $active])->save();

        return $category;
    }

    /** Its plans stay, uncategorised (their foreign key lets go of it): the shop lists them under "other". */
    public function delete(PlanCategory $category): void
    {
        $category->delete();
    }

    /**
     * New display order: the ids as they should appear; rows left out keep their place after the listed ones.
     *
     * @param list<int> $ids
     */
    public function reorder(array $ids): void
    {
        Sorting::reorder(PlanCategory::class, $ids);
    }

    /** Whether the shop asks for a category first: it does as soon as one active category exists. */
    public function isGrouped(): bool
    {
        return PlanCategory::active()->exists();
    }

    /**
     * What the customer browses in the bot: every active category, in order — an empty one is still offered and says so
     * when opened — then the uncategorised sellable plans as one last group (category null). Without any category the
     * single "other" group is the whole shop and the category step is skipped. Only plans with a server to pick right now
     * are in (ServerSelector::offers()): the admin may keep any server on a plan, the customer sees what can deliver.
     *
     * @return list<array{category: PlanCategory|null, plans: Collection<int, Plan>}>
     */
    public function groups(): array
    {
        return $this->catalogue()['groups'];
    }

    /**
     * groups(), with what each plan in them offers the customer to pick — the servers it can be delivered on now, in the
     * plan's order (ServerSelector::offers(), by plan id): the shop's website shows its catalogue with them, read in the
     * same few queries however many plans and servers.
     *
     * @return array{groups: list<array{category: PlanCategory|null, plans: Collection<int, Plan>}>, choices: array<int, list<array{server: Server, inbounds: Collection<int, ServerInbound>}>>}
     */
    public function catalogue(): array
    {
        $plans = Plan::active()->get();
        $choices = $this->selector->offers($plans);
        $plans = $plans->filter(static fn(Plan $plan): bool => isset($choices[$plan->id]))->values();
        $categories = PlanCategory::active()->get()->keyBy('id');

        $groups = [];
        foreach ($categories as $category) {
            $groups[] = ['category' => $category, 'plans' => $plans->filter(static fn(Plan $plan): bool => $plan->category_id === $category->id)->values()];
        }

        // Plans with no category, or whose category is switched off, are still for sale — as "other".
        $other = $plans->filter(static fn(Plan $plan): bool => $plan->category_id === null || !$categories->has($plan->category_id))->values();
        if ($other->isNotEmpty()) {
            $groups[] = ['category' => null, 'plans' => $other];
        }

        return ['groups' => $groups, 'choices' => $choices];
    }

    /** @return array<string, mixed> */
    public function present(PlanCategory $category): array
    {
        // A list counts every row's plans in its own query (all()); a row read on its own — an edit, a switch — has not.
        if (!array_key_exists('plans_count', $category->getAttributes())) {
            $category->loadCount('plans');
        }

        return [
            'id' => $category->id,
            'name' => $category->name,
            'is_active' => $category->is_active,
            'sort' => $category->sort,
            'counts' => ['plans' => (int) $category->getAttribute('plans_count')],
            'created_at' => $category->created_at->toIso8601String(),
            'updated_at' => $category->updated_at->toIso8601String(),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{name: string, is_active: bool}
     * @throws ValidationException
     */
    private static function validate(array $input, PlanCategory $category): array
    {
        $name = Input::text($input, 'name');
        $refusal = match (true) {
            $name === '' => 'نام دسته را وارد کنید؛ مثلا «ماهانه» یا «اقتصادی».',
            mb_strlen($name) > self::NAME_MAX => Validation::tooLong('نام دسته', self::NAME_MAX),
            UniqueName::taken($category, $name) => self::TAKEN,
            default => null,
        };
        if ($refusal !== null) {
            throw ValidationException::on('name', $refusal);
        }

        return ['name' => $name, 'is_active' => Input::active($input, $category->exists ? $category->is_active : null)];
    }
}
