<?php

declare(strict_types=1);

namespace App\Modules\Users\Services;

use App\Core\Database\Sorting;
use App\Core\Database\UniqueName;
use App\Core\Exceptions\ValidationException;
use App\Modules\Auth\Actor;
use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Auth\StaffGrant;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\CustomerGroup;
use App\Modules\Users\Models\User;
use App\Support\Input;
use App\Support\Validation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The customer groups as the panels keep them (a name unique in the shop, and the «همکار» agent-pricing switch), and each
 * customer's groups. Deleting a group only takes its customers out of it.
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
     * @param array<string, mixed> $input {name, agent_pricing?}
     * @throws ValidationException
     */
    public function create(array $input): CustomerGroup
    {
        $group = new CustomerGroup(['sort' => Sorting::next(CustomerGroup::class)]);
        UniqueName::save($group->fill(self::validate($input, $group)), self::TAKEN);

        return $group->loadCount('users');
    }

    /**
     * From the website, a group a shop's admin is in is not switched to «همکار»: their prices would be the agents'
     * (UserActions::assignGroups() keeps them out of one the same way).
     *
     * @param array<string, mixed> $input {name, agent_pricing?}
     * @throws ActorRefusedException 403 from the website, «همکار» switched on with an admin in the group
     * @throws ValidationException
     */
    public function update(CustomerGroup $group, Actor $actor, array $input): CustomerGroup
    {
        $fields = self::validate($input, $group);
        if ($actor->isStaff() && $fields['agent_pricing'] && !$group->agent_pricing && $group->users()->where('role', UserRole::Admin)->exists()) {
            throw ActorRefusedException::adminInGroup($group->name);
        }
        UniqueName::save($group->fill($fields), self::TAKEN);

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
     * The groups a customer is in, as the admin ticked them: every one listed, no other. A «همکار» group they are not in
     * yet sets their prices as the catalogue does: from the website it asks the `catalog` grant (the plain groups, and
     * one they are in already, do not). Whose account an admin may put in a group is UserActions::assignGroups()'s to say.
     *
     * @param mixed $ids The `group_ids` the panel sent
     * @return list<int> The groups' ids
     * @throws ActorRefusedException 403 from the website without `catalog`, a «همکار» group added
     * @throws ValidationException on `group_ids` when one is not a group
     */
    public function assign(User $user, Actor $actor, mixed $ids): array
    {
        // Whole numbers only: "2abc" or a list is no group's id.
        $ids = is_array($ids) ? array_values(array_unique(array_map(static fn(mixed $id): int => Input::integerOf($id) ?? 0, $ids))) : null;
        if ($ids === null || in_array(0, $ids, true) || CustomerGroup::query()->whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::on('group_ids', 'یکی از گروه‌ها پیدا نشد؛ صفحه را دوباره باز کنید.');
        }
        if (!$actor->holds(StaffGrant::Catalog) && CustomerGroup::query()->whereIn('id', $ids)->where('agent_pricing', true)->whereDoesntHave('users', static fn(Builder $member) => $member->whereKey($user->id))->exists()) {
            throw ActorRefusedException::agentGroupNotGranted();
        }

        $user->groups()->sync($ids);
        $user->unsetRelation('groups');

        return $ids;
    }

    /** @return array<string, mixed> One row, its customers counted with it (withCount()/loadCount()). */
    public function present(CustomerGroup $group): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'agent_pricing' => $group->agent_pricing,
            'sort' => $group->sort,
            'counts' => ['users' => (int) $group->users_count],
            'created_at' => $group->created_at?->toIso8601String(),
        ];
    }

    /** @return array{id: int, name: string} A group as a customer's row carries it. */
    public static function presentRef(CustomerGroup $group): array
    {
        return ['id' => $group->id, 'name' => $group->name];
    }

    /**
     * The group's name and its switch, every refusal at once; the switch left out keeps the group's (a new one's off).
     *
     * @param array<string, mixed> $input
     * @return array{name: string, agent_pricing: bool}
     * @throws ValidationException
     */
    private static function validate(array $input, CustomerGroup $group): array
    {
        $errors = [];
        $name = Input::text($input, 'name');
        $message = match (true) {
            $name === '' => 'نام گروه را وارد کنید؛ مثلا «VIP» یا «همکاران».',
            mb_strlen($name) > self::NAME_MAX => Validation::tooLong('نام گروه', self::NAME_MAX),
            UniqueName::taken($group, $name) => self::TAKEN,
            default => null,
        };
        if ($message !== null) {
            $errors['name'][] = $message;
        }

        $agentPricing = $group->exists && $group->agent_pricing;
        if (array_key_exists('agent_pricing', $input)) {
            if (Input::isBoolean($input['agent_pricing'])) {
                $agentPricing = Input::truthy($input['agent_pricing']);
            } else {
                $errors['agent_pricing'][] = Validation::NOT_A_SWITCH;
            }
        }
        ValidationException::ifAny($errors);

        return ['name' => $name, 'agent_pricing' => $agentPricing];
    }
}
