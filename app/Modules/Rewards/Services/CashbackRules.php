<?php

declare(strict_types=1);

namespace App\Modules\Rewards\Services;

use App\Core\Database\Sorting;
use App\Core\Exceptions\ValidationException;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Rewards\Models\CashbackRule;
use App\Modules\Users\Models\CustomerGroup;
use App\Support\Input;
use App\Support\Validation;

/**
 * The cashback rules as the panels keep them: a percent for some kinds of order (bought for themselves), optionally one
 * customer group and one payment method. The highest matching percent counts (Cashback), so their order means nothing.
 */
final class CashbackRules
{
    /**
     * Every rule, oldest first, with its group and its way to pay.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return CashbackRule::query()->with(['customerGroup', 'paymentMethod'])->oldest('sort')->oldest('id')->get()->map(self::present(...))->values()->all();
    }

    /**
     * @param array<string, mixed> $input {percent, order_types, customer_group_id?, payment_method_id?, enabled?}
     * @throws ValidationException
     */
    public function create(array $input): CashbackRule
    {
        $rule = CashbackRule::query()->create(self::validate($input, true) + ['sort' => Sorting::next(CashbackRule::class)]);

        return $rule->load(['customerGroup', 'paymentMethod']);
    }

    /**
     * @param array<string, mixed> $input {percent, order_types, customer_group_id?, payment_method_id?, enabled?}
     * @throws ValidationException
     */
    public function update(CashbackRule $rule, array $input): CashbackRule
    {
        $rule->fill(self::validate($input, $rule->enabled))->save();

        return $rule->load(['customerGroup', 'paymentMethod']);
    }

    /** The rule's switch in the list. */
    public function setEnabled(CashbackRule $rule, bool $enabled): CashbackRule
    {
        $rule->forceFill(['enabled' => $enabled])->save();

        return $rule->load(['customerGroup', 'paymentMethod']);
    }

    public function delete(CashbackRule $rule): void
    {
        $rule->delete();
    }

    /**
     * One rule as the list shows it; its group and its way to pay (null: any) must be loaded.
     *
     * @return array{id: int, percent: int, order_types: list<string>, customer_group: array{id: int, name: string}|null, payment_method: array{id: int, label: string}|null, enabled: bool}
     */
    public static function present(CashbackRule $rule): array
    {
        $group = $rule->customerGroup;
        $method = $rule->paymentMethod;

        return [
            'id' => $rule->id,
            'percent' => $rule->percent,
            'order_types' => $rule->types(),
            'customer_group' => $group === null ? null : ['id' => $group->id, 'name' => $group->name],
            'payment_method' => $method === null ? null : ['id' => $method->id, 'label' => $method->label],
            'enabled' => $rule->enabled,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{percent: int, order_types: list<string>, customer_group_id: int|null, payment_method_id: int|null, enabled: bool}
     * @throws ValidationException
     */
    private static function validate(array $input, bool $enabled): array
    {
        $errors = [];

        $percent = Input::wholeOf($input['percent'] ?? null);
        if ($percent === null || $percent < 1 || $percent > 100) {
            $errors['percent'][] = 'درصد کش‌بک باید عددی بین 1 تا 100 باشد.';
        }

        $types = [];
        $sent = $input['order_types'] ?? null;
        if (!is_array($sent) || $sent === [] || array_diff(array_map(static fn(mixed $type): string => is_string($type) ? $type : '', $sent), OrderType::FOR_THEMSELVES) !== []) {
            $errors['order_types'][] = 'دست‌کم یکی از نوع‌های سفارش را انتخاب کنید: ' . OrderType::forThemselvesInWords() . '.';
        } else {
            // In the kinds' own order, each once.
            $types = array_values(array_intersect(OrderType::FOR_THEMSELVES, $sent));
        }

        $group = self::chosen($input, 'customer_group_id', static fn(int $id): bool => CustomerGroup::query()->whereKey($id)->exists());
        if ($group === false) {
            $errors['customer_group_id'][] = 'گروه مشتری را از گروه‌های فروشگاه انتخاب کنید، یا «همه مشتری‌ها» را.';
        }
        $method = self::chosen($input, 'payment_method_id', static fn(int $id): bool => PaymentMethod::query()->whereKey($id)->exists());
        if ($method === false) {
            $errors['payment_method_id'][] = 'روش پرداخت را از روش‌های پرداخت فروشگاه انتخاب کنید، یا «همه روش‌ها» را.';
        }

        $switch = $input['enabled'] ?? $enabled;
        if (!Input::isBoolean($switch)) {
            $errors['enabled'][] = Validation::NOT_A_SWITCH;
        }

        ValidationException::ifAny($errors);

        return [
            'percent' => (int) $percent,
            'order_types' => $types,
            'customer_group_id' => $group === false ? null : $group,
            'payment_method_id' => $method === false ? null : $method,
            'enabled' => Input::truthy($switch),
        ];
    }

    /**
     * A group or payment method a rule names: its id when the shop has it, null when none is named (any), false when the
     * shop has no such row.
     *
     * @param array<string, mixed> $input
     * @param \Closure(int): bool $exists
     */
    private static function chosen(array $input, string $field, \Closure $exists): int|false|null
    {
        $value = $input[$field] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        $id = Input::integerOf($value);

        return $id !== null && $exists($id) ? $id : false;
    }
}
