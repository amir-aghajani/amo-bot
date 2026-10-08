<?php

declare(strict_types=1);

namespace App\Modules\Agency\Services;

use App\Core\Database\Sorting;
use App\Core\Database\UniqueName;
use App\Core\Exceptions\ValidationException;
use App\Modules\Agency\Exceptions\LevelInUseException;
use App\Modules\Agency\Models\AgencyLevel;
use App\Support\Input;
use App\Support\Money;
use App\Support\Validation;
use Illuminate\Database\Eloquent\Collection;

/**
 * The agents' levels («سطح‌های نمایندگی»): create/update/delete/reorder with Persian validation — a unique name and the
 * price per GB its agents buy their bot's traffic at — in the admin's order, which is also the order the bot lists
 * them in.
 */
final class AgencyLevels
{
    public const NAME_MAX = 32;
    public const PRICE_MAX = 100_000_000;

    private const TAKEN = 'سطحی با این نام وجود دارد.';

    /** @return list<array<string, mixed>> Every level in order, with its agent count. */
    public function all(): array
    {
        return AgencyLevel::query()->withCount('agents')->oldest('sort')->oldest('id')->get()->map($this->present(...))->values()->all();
    }

    /** @return Collection<int, AgencyLevel> Every level in order. */
    public function ordered(): Collection
    {
        return AgencyLevel::query()->oldest('sort')->oldest('id')->get();
    }

    /**
     * @param array<string, mixed> $input {name, price_per_gb}
     * @throws ValidationException
     */
    public function create(array $input): AgencyLevel
    {
        $level = new AgencyLevel(self::validate($input, new AgencyLevel()) + ['sort' => Sorting::next(AgencyLevel::class)]);
        UniqueName::save($level, self::TAKEN);

        return $level->loadCount('agents');
    }

    /**
     * @param array<string, mixed> $input {name, price_per_gb}
     * @throws ValidationException
     */
    public function update(AgencyLevel $level, array $input): AgencyLevel
    {
        UniqueName::save($level->fill(self::validate($input, $level)), self::TAKEN);

        return $level->loadCount('agents');
    }

    /** @throws LevelInUseException while agents are on it */
    public function delete(AgencyLevel $level): void
    {
        if ($level->agents()->exists()) {
            throw new LevelInUseException('سطحی که نماینده دارد حذف نمی‌شود؛ اول نماینده‌هایش را به سطح دیگری ببرید.');
        }

        $level->delete();
    }

    /** @param list<int> $ids The new order; levels left out keep their place after the listed ones. */
    public function reorder(array $ids): void
    {
        Sorting::reorder(AgencyLevel::class, $ids);
    }

    /** @return array<string, mixed> One row, its agents counted with it (withCount()/loadCount()). */
    public function present(AgencyLevel $level): array
    {
        return [
            'id' => $level->id,
            'name' => $level->name,
            'price_per_gb' => Money::normalize($level->price_per_gb),
            'sort' => $level->sort,
            'counts' => ['agents' => (int) $level->agents_count],
            'created_at' => $level->created_at->toIso8601String(),
            'updated_at' => $level->updated_at->toIso8601String(),
        ];
    }

    /** @return array{id: int, name: string, price_per_gb: string} A level as a row of another list carries it. */
    public static function presentRef(AgencyLevel $level): array
    {
        return ['id' => $level->id, 'name' => $level->name, 'price_per_gb' => Money::normalize($level->price_per_gb)];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{name: string, price_per_gb: string}
     * @throws ValidationException
     */
    private static function validate(array $input, AgencyLevel $level): array
    {
        $errors = [];

        $name = Input::text($input, 'name');
        if ($name === '') {
            $errors['name'][] = 'نام سطح را وارد کنید؛ مثلا «برنزی» یا «طلایی».';
        } elseif (mb_strlen($name) > self::NAME_MAX) {
            $errors['name'][] = Validation::tooLong('نام سطح', self::NAME_MAX);
        } elseif (UniqueName::taken($level, $name)) {
            $errors['name'][] = self::TAKEN;
        }

        $price = Input::amount($input, 'price_per_gb');
        if ($price === null || $price <= 0 || $price > self::PRICE_MAX) {
            $errors['price_per_gb'][] = 'قیمت هر گیگابایت را به تومان و بدون اعشار وارد کنید.';
        }

        ValidationException::ifAny($errors);

        return ['name' => $name, 'price_per_gb' => Money::normalize((int) $price)];
    }
}
