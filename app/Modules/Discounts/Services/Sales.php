<?php

declare(strict_types=1);

namespace App\Modules\Discounts\Services;

use App\Core\Exceptions\ValidationException;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Discounts\Models\Sale;
use App\Support\Input;
use App\Support\Validation;
use Illuminate\Database\Eloquent\Collection;

/**
 * The shop's timed sales («فروش ویژه») as the panels keep them. Deleting a sale changes no order it discounted: each
 * order keeps its own discount lines.
 */
final class Sales
{
    public const NAME_MAX = 64;

    /**
     * Every sale, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        return $this->presentAll(Sale::query()->orderByDesc('id')->get());
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException every refusal at once
     */
    public function create(array $input): Sale
    {
        $sale = new Sale();
        $this->save($sale, $input);

        return $sale;
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException every refusal at once
     */
    public function update(Sale $sale, array $input): Sale
    {
        $this->save($sale, $input);

        return $sale;
    }

    public function setEnabled(Sale $sale, bool $enabled): Sale
    {
        $sale->update(['enabled' => $enabled]);

        return $sale;
    }

    public function delete(Sale $sale): void
    {
        $sale->delete();
    }

    /** @return array<string, mixed> One sale as the panels show it. */
    public function present(Sale $sale): array
    {
        return $this->presentAll(new Collection([$sale]))[0];
    }

    /**
     * Sales as the panels show them, the plans they name read once for the whole list.
     *
     * @param Collection<int, Sale> $sales
     * @return list<array<string, mixed>>
     */
    private function presentAll(Collection $sales): array
    {
        $ids = $sales->flatMap(static fn(Sale $sale): array => $sale->plan_ids ?? [])->unique()->values()->all();
        $names = $ids === [] ? [] : Plan::query()->whereIn('id', $ids)->pluck('name', 'id')->all();

        return array_values($sales->map(static fn(Sale $sale): array => [
            'id' => $sale->id,
            'name' => $sale->name,
            'percent' => $sale->percent,
            'order_types' => $sale->order_types,
            'plans' => DiscountForm::named($sale->plan_ids, $names),
            'starts_at' => DiscountForm::moment($sale->starts_at),
            'ends_at' => DiscountForm::moment($sale->ends_at),
            'enabled' => $sale->enabled,
            'state' => $sale->windowState()->value,
            'created_at' => $sale->created_at->toIso8601String(),
        ])->all());
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    private function save(Sale $sale, array $input): void
    {
        $errors = [];
        $name = Input::text($input, 'name');
        if ($name === '') {
            $errors['name'][] = 'نام فروش ویژه را بنویسید.';
        } elseif (mb_strlen($name) > self::NAME_MAX) {
            $errors['name'][] = Validation::tooLong('نام', self::NAME_MAX);
        }
        $percent = Input::wholeOf($input['percent'] ?? null);
        if ($percent === null || $percent < 1 || $percent > 100) {
            $errors['percent'][] = 'درصد تخفیف باید عددی بین 1 تا 100 باشد.';
        }
        $orderTypes = DiscountForm::kinds($input['order_types'] ?? null, $errors);
        $planIds = DiscountForm::ids($input['plan_ids'] ?? null, 'plan_ids', Plan::query(), 'پلن', $errors);
        if (!Input::isBoolean($input['enabled'] ?? null)) {
            $errors['enabled'][] = Validation::NOT_A_SWITCH;
        }
        $window = DiscountForm::window($input, $errors);
        ValidationException::ifAny($errors);

        $sale->fill([
            'name' => $name,
            'percent' => $percent,
            'order_types' => $orderTypes,
            'plan_ids' => $planIds,
            'starts_at' => $window['starts_at'],
            'ends_at' => $window['ends_at'],
            'enabled' => Input::truthy($input['enabled']),
        ])->save();
    }
}
