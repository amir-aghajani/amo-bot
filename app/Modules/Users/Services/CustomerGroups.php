<?php

declare(strict_types=1);

namespace App\Modules\Users\Services;

use App\Core\Database\Sorting;
use App\Core\Database\UniqueName;
use App\Core\Exceptions\ValidationException;
use App\Modules\Users\Models\CustomerGroup;
use App\Modules\Users\Models\User;
use App\Support\Input;
use App\Support\Validation;
use Illuminate\Database\Eloquent\Collection;

/**
 * The admin's own groups of customers («گروه‌ها» on the users page): create/rename/delete/reorder with Persian
 * validation — just a name, unique in the shop — and who is in which, set customer by customer from the users table. A
 * broadcast can go to one group. Deleting a group takes nobody's account away: its customers just leave it.
 */
final class CustomerGroups
{
    public const NAME_MAX = 32;

    private const TAKEN = 'گروهی با این نام وجود دارد.';

    /** @return list<array<string, mixed>> Every group in order, with how many customers are in it. */
    public function all(): array
    {
        return CustomerGroup::query()->withCount('users')->oldest('sort')->oldest('id')->get()->map($this->present(...))->values()->all();
    }

    /** @return Collection<int, CustomerGroup> Every group in the admin's order. */
    public function ordered(): Collection
    {
        return CustomerGroup::query()->oldest('sort')->oldest('id')->get();
    }

    /**
     * @param array<string, mixed> $input {name}
     * @throws ValidationException
     */
    public function create(array $input): CustomerGroup
    {
        $group = new CustomerGroup(['sort' => Sorting::next(CustomerGroup::class)]);
        UniqueName::save($group->fill(['name' => self::name($input, $group)]), self::TAKEN);

        return $group->loadCount('users');
    }

    /**
     * @param array<string, mixed> $input {name}
     * @throws ValidationException
     */
    public function update(CustomerGroup $group, array $input): CustomerGroup
    {
        UniqueName::save($group->fill(['name' => self::name($input, $group)]), self::TAKEN);

        return $group->loadCount('users');
    }

    public function delete(CustomerGroup $group): void
    {
        $group->delete();
    }

    /** @param list<int> $ids The new order; groups left out keep their place after the listed ones. */
    public function reorder(array $ids): void
    {
        Sorting::reorder(CustomerGroup::class, $ids);
    }

    /**
     * The groups a customer is in, as the admin ticked them: every one listed, no other.
     *
     * @param mixed $ids The `group_ids` the panel sent
     * @throws ValidationException on `group_ids` when one is not a group
     */
    public function assign(User $user, mixed $ids): void
    {
        $ids = is_array($ids) ? array_values(array_unique(array_map(static fn(mixed $id): int => (int) $id, $ids))) : null;
        if ($ids === null || count(array_filter($ids, static fn(int $id): bool => $id <= 0)) > 0 || CustomerGroup::query()->whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::on('group_ids', 'یکی از گروه‌ها پیدا نشد؛ صفحه را دوباره باز کنید.');
        }

        $user->groups()->sync($ids);
        $user->unsetRelation('groups');
    }

    /** @return array<string, mixed> One row, its customers counted with it (withCount()/loadCount()). */
    public function present(CustomerGroup $group): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'sort' => $group->sort,
            'counts' => ['users' => (int) $group->users_count],
            'created_at' => $group->created_at->toIso8601String(),
        ];
    }

    /** @return array{id: int, name: string} A group as a customer's row carries it. */
    public static function presentRef(CustomerGroup $group): array
    {
        return ['id' => $group->id, 'name' => $group->name];
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    private static function name(array $input, CustomerGroup $group): string
    {
        $name = Input::text($input, 'name');
        $message = match (true) {
            $name === '' => 'نام گروه را وارد کنید؛ مثلا «VIP» یا «همکاران».',
            mb_strlen($name) > self::NAME_MAX => Validation::tooLong('نام گروه', self::NAME_MAX),
            UniqueName::taken($group, $name) => self::TAKEN,
            default => null,
        };
        if ($message !== null) {
            throw ValidationException::on('name', $message);
        }

        return $name;
    }
}
